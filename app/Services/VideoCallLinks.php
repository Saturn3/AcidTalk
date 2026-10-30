<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Organization;
use Illuminate\Support\Str;

class VideoCallLinks
{
    /** Riconosce i link a servizi di videochiamata. Ritorna provider o null. */
    public static function detect(string $url): ?string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        return match (true) {
            str_contains($host, 'meet.google.com') => 'meet',
            str_contains($host, 'jit.si') || str_contains($host, 'jitsi') => 'jitsi',
            str_contains($host, 'zoom.us') => 'zoom',
            str_contains($host, 'teams.microsoft.com') || str_contains($host, 'teams.live.com') => 'teams',
            str_contains($host, 'whereby.com') => 'whereby',
            default => null,
        };
    }

    public static function label(string $provider): string
    {
        return match ($provider) {
            'meet' => 'Google Meet', 'jitsi' => 'Jitsi Meet', 'zoom' => 'Zoom', 'teams' => 'Microsoft Teams', 'whereby' => 'Whereby', default => 'Videochiamata',
        };
    }

    /** Genera un link per una nuova chiamata in base alle impostazioni dell'organizzazione. */
    public static function create(Organization $org, Channel $channel): array
    {
        $provider = $org->setting('video_provider', 'jitsi');
        if ($provider === 'meet' && $org->setting('meet_link')) {
            return ['provider' => 'meet', 'url' => $org->setting('meet_link')];
        }
        if ($provider === 'meet') {
            return ['provider' => 'meet', 'url' => 'https://meet.google.com/new'];
        }
        $domain = $org->setting('jitsi_domain', 'meet.jit.si');
        $room = Str::slug($org->name).'-'.Str::slug($channel->name ?: 'room').'-'.Str::lower(Str::random(6));

        return ['provider' => 'jitsi', 'url' => 'https://'.$domain.'/'.$room];
    }
}
