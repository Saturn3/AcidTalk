<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChannelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $pivot = $this->relationLoaded('members') ? $this->members->firstWhere('id', $user?->id)?->pivot : null;
        $other = $this->type === 'dm' && $this->relationLoaded('members') ? $this->members->firstWhere('id', '!=', $user?->id) : null;

        return [
            'id' => $this->id,
            'name' => $user ? $this->displayNameFor($user) : $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'topic' => $this->topic,
            'description' => $this->description,
            'ai_enabled' => $this->ai_enabled,
            'created_by' => $this->created_by,
            'member_count' => $this->members_count ?? ($this->relationLoaded('members') ? $this->members->count() : null),
            'is_member' => $pivot !== null,
            'notify' => $pivot?->notify ?? 'all',
            'last_read_at' => $pivot?->last_read_at,
            'unread_count' => $this->unread_count ?? 0,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'is_archived' => $this->archived_at !== null,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'other_user' => $other ? new UserResource($other) : null,
            'members' => $this->when($this->relationLoaded('members') && $request->routeIs('channels.show'), fn () => UserResource::collection($this->members)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
