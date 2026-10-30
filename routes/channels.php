<?php

use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('channel.{id}', fn (User $user, int $id) => Channel::find($id)?->canRead($user) ?? false);
Broadcast::channel('user.{id}', fn (User $user, int $id) => $user->id === $id);
Broadcast::channel('App.Models.User.{id}', fn (User $user, int $id) => $user->id === $id);
