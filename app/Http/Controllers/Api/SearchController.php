<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Channel;
use App\Models\Message;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate(['q' => 'required|string|min:2|max:100', 'channel_id' => 'nullable|integer']);
        $user = $request->user();
        $channelIds = Channel::visibleTo($user, true)->pluck('id');
        if (! empty($data['channel_id'])) {
            $channelIds = $channelIds->intersect([(int) $data['channel_id']]);
        }
        $results = Message::whereIn('channel_id', $channelIds)
            ->where('body', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $data['q']).'%')
            ->with(['user', 'attachments', 'reactions', 'linkPreviews', 'channel'])
            ->orderByDesc('id')->limit(40)->get();

        return MessageResource::collection($results)->additional([
            'channels' => $results->pluck('channel')->unique('id')->mapWithKeys(fn ($c) => [$c->id => $c->displayNameFor($user)]),
        ]);
    }
}
