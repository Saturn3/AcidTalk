<?php

namespace App\Support;

use App\Models\Organization;

/** Configurazione condivisa fra SPA web (bootstrap), app mobile e desktop (GET /api/config). */
class ClientConfig
{
    public const VERSION = '2.6.0';

    /** Risposta di GET /api/client-config (e del vecchio alias /api/config). */
    public static function response(): array
    {
        return ['org' => self::org(\App\Models\Organization::current()), 'realtime' => self::realtime(), 'version' => self::VERSION];
    }

    public static function org(Organization $org): array
    {
        return [
            'name' => $org->name,
            'domain' => $org->domain,
            'ai_bot_name' => $org->setting('ai_bot_name', 'AI'),
            'video_provider' => $org->setting('video_provider'),
            'max_upload_mb' => (int) $org->setting('max_upload_mb', 25),
            'allow_guest_invites' => (bool) $org->setting('allow_guest_invites'),
            'sidebar_dm_days' => (int) $org->setting('sidebar_dm_days', 60),
            'sidebar_channel_days' => (int) $org->setting('sidebar_channel_days', 90),
            'read_receipts' => (bool) $org->setting('read_receipts', true),
            'push_enabled' => app(\App\Services\Push\FcmSender::class)->configured(),
        ];
    }

    /** null = nessun WebSocket: i client usano il polling (con ?changed_since per modifiche e anteprime). */
    public static function realtime(): ?array
    {
        return match (config('broadcasting.default')) {
            'reverb' => [
                'driver' => 'reverb',
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('broadcasting.connections.reverb.options.host'),
                'port' => (int) config('broadcasting.connections.reverb.options.port'),
                'scheme' => config('broadcasting.connections.reverb.options.scheme'),
            ],
            'pusher' => [
                'driver' => 'pusher',
                'key' => config('broadcasting.connections.pusher.key'),
                'cluster' => config('broadcasting.connections.pusher.options.cluster'),
                'scheme' => 'https',
            ],
            default => null,
        };
    }
}
