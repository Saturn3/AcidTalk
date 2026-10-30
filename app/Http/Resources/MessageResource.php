<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $me = $request->user();
        $reactions = $this->relationLoaded('reactions')
            ? $this->reactions->groupBy('emoji')->map(fn ($g, $emoji) => [
                'emoji' => $emoji,
                'count' => $g->count(),
                'user_ids' => $g->pluck('user_id')->values(),
                'me' => $me ? $g->contains('user_id', $me->id) : false,
            ])->values()
            : [];

        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'parent_id' => $this->parent_id,
            'user' => $this->is_ai || ! $this->user
                ? ['id' => null, 'name' => $this->meta['bot_name'] ?? 'AI', 'initials' => 'AI', 'avatar_color' => '#5f3dc4', 'is_ai' => true]
                : (new UserResource($this->user))->resolve($request) + ['is_ai' => false],
            'body' => $this->body,
            'html' => $this->html(),
            'kind' => $this->kind,
            'is_ai' => $this->is_ai,
            'meta' => $this->meta ?? (object) [],
            'attachments' => $this->whenLoaded('attachments'),
            'reactions' => $reactions,
            'link_previews' => $this->whenLoaded('linkPreviews'),
            'reply_count' => $this->reply_count,
            'last_reply_at' => $this->last_reply_at?->toIso8601String(),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'can_edit' => $me ? $this->canBeEditedBy($me) : false,
            'can_delete' => $me ? $this->canBeDeletedBy($me) : false,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
