<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'title' => $this->title,
            'initials' => $this->initials(),
            'avatar_color' => $this->avatar_color,
            'avatar_url' => $this->avatarUrl(),
            'is_bot' => (bool) $this->is_bot,
            'bot_description' => $this->when((bool) $this->is_bot, $this->bot_description),
            'status_text' => $this->status_text,
            'status_emoji' => $this->status_emoji,
            'online' => $this->isOnline(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'notification_prefs' => $this->when($request->user()?->id === $this->id || $request->user()?->isAdmin(), fn () => $this->prefs()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
