<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageDeleted;
use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Message;
use App\Services\MessageTasks;
use App\Services\NotificationDispatcher;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    private const WITH = ['user', 'attachments', 'reactions', 'linkPreviews'];

    /** Messaggi principali del canale (senza risposte nei thread), dal più recente. Cursor: ?before=<id> */
    public function index(Request $request, Channel $channel)
    {
        $this->authorize('view', $channel);
        $limit = min((int) $request->integer('limit', 50), 100);

        // Sincronizzazione senza WebSocket: messaggi modificati, eliminati, con nuove reazioni,
        // risposte o anteprime dei link dopo ?changed_since=<ISO 8601> (anche i thread con ?thread=<id>).
        if ($request->filled('changed_since')) {
            $since = \Illuminate\Support\Carbon::parse($request->string('changed_since'))->subSeconds(2);
            $cq = $channel->messages()->withTrashed()->with(self::WITH)->where('updated_at', '>=', $since)->orderBy('updated_at')->limit(200);
            $request->filled('thread') ? $cq->where('parent_id', $request->integer('thread')) : $cq->whereNull('parent_id');
            $changed = $cq->get();

            return response()->json([
                'data' => $changed->whereNull('deleted_at')->map(fn ($m) => (new MessageResource($m))->resolve($request))->values(),
                'deleted' => $changed->whereNotNull('deleted_at')->pluck('id')->values(),
                'meta' => ['server_time' => now()->toIso8601String(), 'channel_id' => $channel->id, 'reads' => $channel->readReceipts($request->user())],
            ]);
        }

        $q = $channel->messages()->whereNull('parent_id')->with(self::WITH)->orderByDesc('id');
        if ($before = $request->integer('before')) {
            $q->where('id', '<', $before);
        }
        if ($after = $request->integer('after')) {
            $q->where('id', '>', $after)->reorder('id');
        }
        $items = $q->limit($limit + 1)->get();
        $hasMore = $items->count() > $limit;
        $items = $items->take($limit)->sortBy('id')->values();

        return MessageResource::collection($items)->additional(['meta' => ['has_more' => $hasMore, 'channel_id' => $channel->id, 'server_time' => now()->toIso8601String(), 'reads' => $channel->readReceipts($request->user())]]);
    }

    public function store(Request $request, Channel $channel)
    {
        $this->authorize('view', $channel);
        $channel->ensureWritable();
        $this->authorize('post', $channel);
        $user = $request->user();
        $data = $request->validate([
            'body' => 'nullable|string|max:20000',
            'parent_id' => 'nullable|integer',
            'kind' => 'nullable|in:text,voice',
            'attachment_ids' => 'array|max:10',
            'attachment_ids.*' => 'integer',
            'meta' => 'nullable|array',
        ]);
        $attachments = Attachment::whereIn('id', $data['attachment_ids'] ?? [])->where('user_id', $user->id)->whereNull('message_id')->get();
        abort_if(trim((string) ($data['body'] ?? '')) === '' && $attachments->isEmpty(), 422, 'Il messaggio è vuoto.');

        $parent = null;
        if (! empty($data['parent_id'])) {
            $parent = Message::where('channel_id', $channel->id)->whereNull('parent_id')->findOrFail($data['parent_id']);
        }

        $message = $channel->messages()->create([
            'user_id' => $user->id,
            'parent_id' => $parent?->id,
            'body' => $data['body'] ?? null,
            'kind' => $data['kind'] ?? ($attachments->contains('kind', 'audio') && empty($data['body']) ? 'voice' : 'text'),
            'meta' => $data['meta'] ?? null,
        ]);
        if ($attachments->isNotEmpty()) {
            Attachment::whereIn('id', $attachments->pluck('id'))->update(['message_id' => $message->id]);
        }
        if ($parent) {
            $parent->increment('reply_count', 1, ['last_reply_at' => now()]);
        }
        $channel->forceFill(['last_message_at' => now()])->save();
        $channel->members()->syncWithoutDetaching([$user->id => []]);
        $channel->members()->updateExistingPivot($user->id, ['last_read_at' => now()]);

        $message->load(self::WITH);
        \App\Support\Realtime::send(new MessageSent($message), true);
        if ($parent) {
            \App\Support\Realtime::send(new MessageUpdated($parent->fresh(self::WITH)), true);
        }
        app(NotificationDispatcher::class)->dispatch($message, $channel->load('members'));

        // AI, anteprime e bot: se il client lo dichiara (X-AcidTalk-Tasks: client) le esegue lui
        // chiamando /messages/{id}/tasks/{task}, altrimenti partono in background.
        MessageTasks::schedule($message, $channel, self::clientDriven($request));

        return (new MessageResource($message))->response()->setStatusCode(201);
    }

    private static function clientDriven(Request $request): bool
    {
        return strtolower((string) $request->header('X-AcidTalk-Tasks')) === 'client';
    }

    /**
     * Esegue in modo sincrono un'attività in sospeso del messaggio (ai | previews | bots).
     * Idempotente: se la risposta AI esiste già viene restituita quella.
     */
    public function runTask(Request $request, Message $message, string $task)
    {
        $this->authorize('view', $message->channel);
        abort_unless(in_array($task, MessageTasks::TASKS, true), 404);
        $created = app(MessageTasks::class)->run($message, $task);

        return response()->json([
            'message' => (new MessageResource($message->fresh(self::WITH)))->resolve($request),
            'replies' => collect($created)->map(fn ($m) => (new MessageResource($m->loadMissing(self::WITH)))->resolve($request))->values(),
            'pending' => $message->fresh()->meta['pending'] ?? [],
        ]);
    }

    public function show(Request $request, Message $message)
    {
        $this->authorize('view', $message->channel);

        return new MessageResource($message->load(self::WITH));
    }

    /** Modifica del testo: solo l'autore. */
    public function update(Request $request, Message $message)
    {
        $message->channel->ensureWritable();
        $this->authorize('update', $message);
        $data = $request->validate(['body' => 'required|string|max:20000']);
        if (trim($data['body']) !== trim((string) $message->body)) {
            $message->update(['body' => $data['body'], 'edited_at' => now()]);
            $message->linkPreviews()->delete();
            MessageTasks::scheduleEdit($message, self::clientDriven($request));
        }
        $message->load(self::WITH);
        \App\Support\Realtime::send(new MessageUpdated($message), true);

        return new MessageResource($message);
    }

    /** Rimuove un'anteprima link dal messaggio (autore o amministratore), come "Rimuovi anteprima" di Slack. */
    public function removePreview(Request $request, Message $message, int $preview)
    {
        $message->channel->ensureWritable();
        abort_unless($message->canBeEditedBy($request->user()) || $request->user()->isAdmin(), 403, 'Solo l\'autore può rimuovere l\'anteprima.');
        $message->linkPreviews()->whereKey($preview)->delete();
        $message->touch();
        $message->load(self::WITH);
        \App\Support\Realtime::send(new MessageUpdated($message), true);

        return new MessageResource($message);
    }

    /** Cancellazione (soft delete): autore o amministratore. */
    public function destroy(Request $request, Message $message)
    {
        $message->channel->ensureWritable();
        $this->authorize('delete', $message);
        $channelId = $message->channel_id;
        $parentId = $message->parent_id;
        $message->delete();
        if ($parentId) {
            Message::whereKey($parentId)->where('reply_count', '>', 0)->decrement('reply_count');
            if ($parent = Message::find($parentId)) {
                \App\Support\Realtime::send(new MessageUpdated($parent->load(self::WITH)), true);
            }
        }
        \App\Support\Realtime::send(new MessageDeleted($message->id, $channelId, $parentId), true);

        return response()->json(['ok' => true, 'id' => $message->id]);
    }

    /** Thread: messaggio radice + risposte in ordine cronologico. */
    public function thread(Request $request, Message $message)
    {
        $this->authorize('view', $message->channel);
        $root = $message->parent_id ? $message->parent : $message;
        $replies = $root->replies()->with(self::WITH)->orderBy('id')->get();

        return response()->json([
            'root' => (new MessageResource($root->load(self::WITH)))->resolve($request),
            'data' => MessageResource::collection($replies)->resolve($request),
            'reads' => $message->channel->readReceipts($request->user()),
        ]);
    }
}
