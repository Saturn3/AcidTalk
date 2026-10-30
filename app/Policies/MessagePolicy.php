<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

class MessagePolicy
{
    public function update(User $user, Message $message): bool
    {
        return $message->canBeEditedBy($user);
    }

    public function delete(User $user, Message $message): bool
    {
        return $message->canBeDeletedBy($user);
    }
}
