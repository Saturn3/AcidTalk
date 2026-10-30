<?php

namespace App\Http\Controllers\Api;

use App\Events\ChannelTouched;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChannelResource;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ChannelController extends Controller
{
    /** Tutti i canali visibili all'utente, con conteggio non letti. */
    public function index(Request $request)
    {
        $user = $request->user();
        // ?archived=1 -> solo canali archiviati (sezione "Archiviati" di Sfoglia canali)
        $archived = $request->boolean('archived') ? 'only' : false;
        $channels = Channel::visibleTo($user, $archived)->with('members')->withCount('members')->orderBy('type')->orderBy('name')->get();

        $unread = DB::table('messages')
            ->join('channel_user', 'channel_user.channel_id', '=', 'messages.channel_id')
            ->where('channel_user.user_id', $user->id)
            ->whereNull('messages.deleted_at')->whereNull('messages.parent_id')
            ->where(fn ($q) => $q->whereNull('messages.user_id')->orWhere('messages.user_id', '!=', $user->id))
            ->whereRaw('(channel_user.last_read_at IS NULL OR messages.created_at > channel_user.last_read_at)')
            ->groupBy('messages.channel_id')->pluck(DB::raw('count(*) as c'), 'messages.channel_id');

        $channels->each(fn (Channel $c) => $c->unread_count = (int) ($unread[$c->id] ?? 0));

        return ChannelResource::collection($channels);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_if($user->isGuest(), 403, 'Gli ospiti non possono creare canali.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', 'regex:/^[\p{L}\d _\-]+$/u'],
            'type' => ['required', Rule::in(['public', 'private'])],
            'topic' => 'nullable|string|max:200',
            'description' => 'nullable|string|max:1000',
            'ai_enabled' => 'boolean',
            'member_ids' => 'array',
            'member_ids.*' => 'integer|exists:users,id',
        ]);
        $slug = Str::slug($data['name']);
        abort_if(Channel::where('organization_id', $user->organization_id)->where('slug', $slug)->exists(), 422, 'Esiste già un canale con questo nome.');

        $channel = Channel::create([
            'organization_id' => $user->organization_id,
            'name' => mb_strtolower(trim($data['name'])),
            'type' => $data['type'],
            'topic' => $data['topic'] ?? null,
            'description' => $data['description'] ?? null,
            'ai_enabled' => $data['ai_enabled'] ?? true,
            'created_by' => $user->id,
        ]);
        $members = collect($data['member_ids'] ?? [])->reject(fn ($id) => $id == $user->id)
            ->mapWithKeys(fn ($id) => [$id => ['role' => 'member']])->all();
        $channel->members()->attach([$user->id => ['role' => 'owner']] + $members);
        $channel->messages()->create(['user_id' => null, 'kind' => 'system', 'body' => "**{$user->name}** ha creato il canale #{$channel->name}".($channel->topic ? " — {$channel->topic}" : '')]);

        foreach (array_keys($members) as $id) {
            \App\Support\Realtime::send(new ChannelTouched((int) $id, ['type' => 'channel.added', 'channel_id' => $channel->id]));
        }

        return (new ChannelResource($channel->load('members')->loadCount('members')))->response()->setStatusCode(201);
    }

    public function show(Request $request, Channel $channel)
    {
        $this->authorize('view', $channel);

        return new ChannelResource($channel->load('members')->loadCount('members'));
    }

    public function update(Request $request, Channel $channel)
    {
        $this->authorize('manage', $channel);
        $channel->ensureWritable();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60', 'regex:/^[\p{L}\d _\-]+$/u'],
            'topic' => 'nullable|string|max:200',
            'description' => 'nullable|string|max:1000',
            'ai_enabled' => 'boolean',
            'type' => ['sometimes', Rule::in(['public', 'private'])],
        ]);
        if (isset($data['name']) && ! $channel->isDirect()) {
            $data['name'] = mb_strtolower(trim($data['name']));
            $data['slug'] = Str::slug($data['name']);
        }
        $channel->update($data);

        return new ChannelResource($channel->fresh('members')->loadCount('members'));
    }

    /** Compatibilità con i client esistenti: DELETE archivia il canale (non cancella nulla). */
    public function destroy(Request $request, Channel $channel)
    {
        return $this->archive($request, $channel);
    }

    /** Archivia: il canale esce dalla barra laterale, resta consultabile e ricercabile in sola lettura. */
    public function archive(Request $request, Channel $channel)
    {
        $this->authorize('manage', $channel);
        abort_if($channel->slug === 'generale', 422, 'Il canale #generale non può essere archiviato.');
        abort_unless(in_array($channel->type, ['public', 'private'], true), 422, 'Solo i canali possono essere archiviati.');
        abort_if($channel->isArchived(), 422, 'Il canale è già archiviato.');

        $channel->messages()->create(['user_id' => null, 'kind' => 'system', 'body' => "**{$request->user()->name}** ha archiviato il canale"]);
        $channel->forceFill(['archived_at' => now()])->save();
        $this->notifyMembers($channel, 'channel.archived');

        return new ChannelResource($channel->fresh('members')->loadCount('members'));
    }

    /** Ripristina un canale archiviato. */
    public function unarchive(Request $request, Channel $channel)
    {
        $this->authorize('manage', $channel);
        abort_unless($channel->isArchived(), 422, 'Il canale non è archiviato.');
        $slugTaken = Channel::where('organization_id', $channel->organization_id)->where('slug', $channel->slug)
            ->whereKeyNot($channel->id)->whereNull('archived_at')->exists();
        abort_if($slugTaken, 422, "Esiste già un canale attivo #{$channel->slug}: rinominalo prima di ripristinare questo.");

        $channel->forceFill(['archived_at' => null])->save();
        $channel->messages()->create(['user_id' => null, 'kind' => 'system', 'body' => "**{$request->user()->name}** ha ripristinato il canale"]);
        $this->notifyMembers($channel, 'channel.unarchived');

        return new ChannelResource($channel->fresh('members')->loadCount('members'));
    }

    private function notifyMembers(Channel $channel, string $type): void
    {
        foreach ($channel->members()->pluck('users.id') as $id) {
            \App\Support\Realtime::send(new ChannelTouched((int) $id, ['type' => $type, 'channel_id' => $channel->id]));
        }
    }

    public function join(Request $request, Channel $channel)
    {
        $user = $request->user();
        abort_unless($channel->type === 'public' && ! $user->isGuest(), 403, 'Canale non accessibile.');
        $channel->ensureWritable();
        $channel->members()->syncWithoutDetaching([$user->id => ['role' => 'member', 'last_read_at' => now()]]);
        $channel->messages()->create(['user_id' => null, 'kind' => 'system', 'body' => "**{$user->name}** si è unito al canale"]);

        return new ChannelResource($channel->fresh('members')->loadCount('members'));
    }

    public function leave(Request $request, Channel $channel)
    {
        abort_if($channel->slug === 'generale', 422, 'Non puoi lasciare #generale.');
        $channel->members()->detach($request->user()->id);
        $channel->messages()->create(['user_id' => null, 'kind' => 'system', 'body' => "**{$request->user()->name}** ha lasciato il canale"]);

        return response()->json(['ok' => true]);
    }

    /** Aggiunge membri (DM: no; privati: solo chi gestisce; pubblici: chiunque sia membro). */
    public function addMembers(Request $request, Channel $channel)
    {
        $this->authorize('view', $channel);
        abort_if($channel->isDirect(), 422, 'Non puoi aggiungere membri a un messaggio diretto.');
        $channel->ensureWritable();
        if ($channel->type === 'private') {
            $this->authorize('manage', $channel);
        }
        $data = $request->validate(['user_ids' => 'required|array', 'user_ids.*' => 'integer']);
        $users = User::where('organization_id', $channel->organization_id)->whereIn('id', $data['user_ids'])->get();
        $channel->members()->syncWithoutDetaching($users->mapWithKeys(fn ($u) => [$u->id => ['role' => 'member']])->all());
        $names = $users->pluck('name')->map(fn ($n) => "**{$n}**")->implode(', ');
        if ($names) {
            $channel->messages()->create(['user_id' => null, 'kind' => 'system', 'body' => "{$request->user()->name} ha aggiunto {$names}"]);
        }
        foreach ($users as $u) {
            \App\Support\Realtime::send(new ChannelTouched($u->id, ['type' => 'channel.added', 'channel_id' => $channel->id]));
        }

        return new ChannelResource($channel->fresh('members')->loadCount('members'));
    }

    public function removeMember(Request $request, Channel $channel, User $user)
    {
        $this->authorize('manage', $channel);
        $channel->members()->detach($user->id);

        return response()->json(['ok' => true]);
    }

    /** Segna come letto. */
    public function read(Request $request, Channel $channel)
    {
        $this->authorize('view', $channel);
        $user = $request->user();
        $channel->members()->syncWithoutDetaching([$user->id => []]);
        $before = $channel->members()->where('users.id', $user->id)->first()?->pivot?->last_read_at;
        $now = now();
        $channel->members()->updateExistingPivot($user->id, ['last_read_at' => $now]);
        // conferma di lettura in tempo reale, solo se c'erano messaggi non letti
        if (\App\Models\Organization::current()->setting('read_receipts', true)
            && $channel->messages()->where('created_at', '>', $before ?: '1970-01-01')->where('user_id', '!=', $user->id)->exists()) {
            \App\Support\Realtime::send(new \App\Events\ChannelRead($channel->id, $user->id, $now->toIso8601String()), true);
        }

        return response()->json(['ok' => true, 'last_read_at' => $now->toIso8601String()]);
    }

    /** Conferme di lettura: fino a quando ogni membro ha letto il canale. */
    public function reads(Request $request, Channel $channel)
    {
        $this->authorize('view', $channel);

        return response()->json(['data' => $channel->readReceipts($request->user()), 'enabled' => (bool) \App\Models\Organization::current()->setting('read_receipts', true)]);
    }

    /** Livello notifiche per il canale: all | mentions | none */
    public function notify(Request $request, Channel $channel)
    {
        $this->authorize('view', $channel);
        $data = $request->validate(['notify' => ['required', Rule::in(['all', 'mentions', 'none'])]]);
        $channel->members()->syncWithoutDetaching([$request->user()->id => []]);
        $channel->members()->updateExistingPivot($request->user()->id, ['notify' => $data['notify']]);

        return response()->json(['ok' => true, 'notify' => $data['notify']]);
    }

    /** Apre (o ritrova) un messaggio diretto con un utente. */
    public function direct(Request $request)
    {
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);
        $other = User::where('organization_id', $request->user()->organization_id)->findOrFail($data['user_id']);
        abort_if($other->id === $request->user()->id, 422, 'Usa il DM con l\'AI per parlare da solo :)');
        $channel = Channel::directBetween($request->user(), $other);
        \App\Support\Realtime::send(new ChannelTouched($other->id, ['type' => 'channel.added', 'channel_id' => $channel->id]));

        return new ChannelResource($channel->load('members')->loadCount('members'));
    }

    /** DM personale con l'assistente AI. */
    public function ai(Request $request)
    {
        $channel = Channel::aiDirectFor($request->user());

        return new ChannelResource($channel->load('members')->loadCount('members'));
    }
}
