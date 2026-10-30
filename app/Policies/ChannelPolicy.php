<?php

namespace App\Policies;

use App\Models\Channel;
use App\Models\User;

class ChannelPolicy
{
    public function view(User $user, Channel $channel): bool
    {
        return $channel->canRead($user);
    }

    public function post(User $user, Channel $channel): bool
    {
        return $channel->canRead($user) && ! $channel->isArchived();
    }

    public function manage(User $user, Channel $channel): bool
    {
        return $user->isAdmin() || $channel->created_by === $user->id
            || $channel->members()->where('users.id', $user->id)->wherePivot('role', 'owner')->exists();
    }
}
