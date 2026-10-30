<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Channel;
use App\Models\VideoCall;
use App\Services\VideoCallLinks;
use Illuminate\Http\Request;

class VideoCallController extends Controller
{
    /** Avvia una videochiamata nel canale: crea il link (Jitsi/Meet) e lo pubblica come messaggio. */
    public function store(Request $request, Channel $channel)
    {
        $channel->ensureWritable();
        $this->authorize('post', $channel);
        $user = $request->user();
        $data = $request->validate(['url' => 'nullable|url|max:2048']);

        $link = $data['url'] ?? null
            ? ['provider' => VideoCallLinks::detect($data['url']) ?? 'custom', 'url' => $data['url']]
            : VideoCallLinks::create($channel->organization, $channel);

        $call = $channel->calls()->create(['started_by' => $user->id, 'provider' => $link['provider'], 'url' => $link['url']]);
        $label = VideoCallLinks::label($link['provider']);
        $message = $channel->messages()->create([
            'user_id' => $user->id,
            'kind' => 'call',
            'body' => "📹 **{$user->name}** ha avviato una videochiamata su {$label}: {$link['url']}",
            'meta' => ['call_id' => $call->id, 'provider' => $link['provider'], 'url' => $link['url']],
        ]);
        $message->linkPreviews()->create(['url' => $link['url'], 'kind' => 'video_call', 'provider' => $link['provider'], 'title' => $label, 'description' => 'Clicca per partecipare alla videochiamata', 'site_name' => parse_url($link['url'], PHP_URL_HOST)]);
        $channel->forceFill(['last_message_at' => now()])->save();
        $message->load(['user', 'attachments', 'reactions', 'linkPreviews']);
        \App\Support\Realtime::send(new MessageSent($message), true);

        return response()->json(['call' => $call, 'message' => (new MessageResource($message))->resolve($request)], 201);
    }

    public function end(Request $request, VideoCall $call)
    {
        $this->authorize('view', $call->channel);
        $call->update(['ended_at' => now()]);

        return response()->json($call);
    }

    public function index(Request $request, Channel $channel)
    {
        $this->authorize('view', $channel);

        return $channel->calls()->whereNull('ended_at')->latest()->get();
    }
}
