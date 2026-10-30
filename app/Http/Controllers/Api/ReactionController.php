<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Http\Request;

class ReactionController extends Controller
{
    /** Aggiunge o rimuove (toggle) una reazione. */
    public function toggle(Request $request, Message $message)
    {
        $this->authorize('view', $message->channel);
        $message->channel->ensureWritable();
        $data = $request->validate(['emoji' => 'required|string|max:32']);
        $existing = $message->reactions()->where('user_id', $request->user()->id)->where('emoji', $data['emoji'])->first();
        $existing ? $existing->delete() : $message->reactions()->create(['user_id' => $request->user()->id, 'emoji' => $data['emoji']]);
        $message->touch(); // serve alla sincronizzazione via polling (?changed_since)

        $message->load(['user', 'attachments', 'reactions', 'linkPreviews']);
        \App\Support\Realtime::send(new MessageUpdated($message), true);

        return new MessageResource($message);
    }
}
