<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// Battito del cron: visibile in Amministrazione › Stato sistema
Schedule::call(fn () => Cache::forever('acidtalk.cron_heartbeat', now()->toIso8601String()))->everyMinute()->name('heartbeat');

// Pulizia inviti scaduti e notifiche vecchie
Schedule::call(function () {
    \App\Models\Invite::whereNull('accepted_at')->where('expires_at', '<', now()->subDays(30))->delete();
    \DB::table('notifications')->whereNotNull('read_at')->where('created_at', '<', now()->subDays(60))->delete();
})->daily();

// Risposte AI, anteprime e webhook dei bot rimasti in sospeso (client chiuso subito dopo l'invio)
Schedule::command('acidtalk:pending')->everyMinute()->withoutOverlapping(5);

// Hosting condiviso senza processi persistenti (es. SiteGround): il cron di schedule:run
// esegue ogni minuto i job in coda (email, job con QUEUE_WORKER=true) e poi termina.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
