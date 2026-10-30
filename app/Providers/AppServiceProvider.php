<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // La SPA è servita dallo stesso dominio dell'API: aggiunge sempre l'host corrente ai domini
        // "stateful" di Sanctum, così l'app web funziona anche se APP_URL o SANCTUM_STATEFUL_DOMAINS
        // non sono stati impostati correttamente (es. www. o porta diversa su hosting condiviso).
        if (! $this->app->runningInConsole() && ($host = request()->getHttpHost())) {
            $stateful = (array) config('sanctum.stateful', []);
            if (! in_array($host, $stateful, true)) {
                config(['sanctum.stateful' => array_merge($stateful, [$host])]);
            }
        }

        // Dietro il proxy di SiteGround/Cloudflare genera URL https quando APP_URL è https
        if (str_starts_with((string) config('app.url'), 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
    }
}
