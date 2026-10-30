<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Conferma di lettura: un membro ha letto il canale fino a last_read_at. */
class ChannelRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $channelId, public int $userId, public string $lastReadAt) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('channel.'.$this->channelId)];
    }

    public function broadcastAs(): string
    {
        return 'channel.read';
    }

    public function broadcastWith(): array
    {
        return ['channel_id' => $this->channelId, 'user_id' => $this->userId, 'last_read_at' => $this->lastReadAt];
    }
}
