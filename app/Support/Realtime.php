<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Invio immediato degli eventi realtime (Reverb/Pusher) senza passare dalla coda:
 * su hosting condiviso senza worker gli eventi in coda non partirebbero mai.
 * Un errore del broadcaster (es. credenziali Pusher errate) non deve mai far fallire la richiesta.
 */
class Realtime
{
    public static function send(object $event, bool $toOthers = false): void
    {
        try {
            $pending = broadcast($event);
            if ($toOthers) {
                $pending->toOthers();
            }
            unset($pending); // il broadcast parte nel distruttore di PendingBroadcast
        } catch (\Throwable $e) {
            Log::warning('Broadcast realtime non riuscito: '.$e->getMessage(), ['event' => $event::class]);
        }
    }
}
