<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\MessageTasks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateAiReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public Message $trigger) {}

    /**
     * Con QUEUE_WORKER=true va in coda, altrimenti viene eseguito subito dopo la risposta HTTP.
     * In ogni caso il cron (acidtalk:pending) recupera le risposte rimaste in sospeso.
     */
    public static function dispatchFor(Message $message): void
    {
        config('queue.worker') ? static::dispatch($message) : static::dispatchAfterResponse($message);
    }

    public function handle(MessageTasks $tasks): void
    {
        $tasks->ai($this->trigger);
    }
}
