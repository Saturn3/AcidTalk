<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\MessageTasks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FetchLinkPreview implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Message $message) {}

    public static function dispatchFor(Message $message): void
    {
        config('queue.worker') ? static::dispatch($message) : static::dispatchAfterResponse($message);
    }

    public function handle(MessageTasks $tasks): void
    {
        $tasks->previews($this->message);
    }
}
