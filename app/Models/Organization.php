<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $fillable = ['name', 'domain', 'allow_domain_signup', 'settings'];

    protected $casts = ['settings' => 'array', 'allow_domain_signup' => 'boolean'];

    /** Impostazioni di default, sovrascrivibili dal pannello admin (salvate nel JSON settings). */
    public const DEFAULTS = [
        'ai_provider' => null,           // null => config('ai.default')
        'ai_model' => null,
        'ai_bot_name' => 'AI',
        'ai_system_prompt' => null,
        'video_provider' => 'jitsi',     // jitsi | meet
        'jitsi_domain' => 'meet.jit.si',
        'meet_link' => null,             // link fisso Google Meet opzionale
        'max_upload_mb' => 25,
        'allow_guest_invites' => true,
        'sidebar_dm_days' => 60,         // DM visibili nella barra laterale: attivi negli ultimi N giorni
        'sidebar_channel_days' => 90,    // canali visibili nella barra laterale: attività negli ultimi N giorni
        'read_receipts' => true,         // conferme di lettura (spunte) visibili agli utenti
        'emoji_autoconvert' => true,     // :sorriso: e :) convertiti in emoji
        'fcm_service_account' => null,   // JSON dell'account di servizio Firebase (notifiche push app)
    ];

    private static ?self $cached = null;

    public static function current(): self
    {
        // in cache per la durata della richiesta (azzerata al salvataggio)
        if (static::$cached && ! app()->runningUnitTests()) {
            return static::$cached;
        }

        return static::$cached = static::query()->firstOrCreate(
            ['id' => 1],
            ['name' => config('organization.name'), 'domain' => config('organization.domain'), 'allow_domain_signup' => config('organization.allow_domain_signup')]
        );
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::$cached = null);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(Invite::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $value = data_get($this->settings, $key);
        if ($value === null || $value === '') {
            return $default ?? (self::DEFAULTS[$key] ?? null);
        }

        return $value;
    }

    public function allSettings(): array
    {
        return array_merge(self::DEFAULTS, $this->settings ?? []);
    }

    public function emailMatchesDomain(string $email): bool
    {
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));

        return $domain !== '' && $domain === strtolower($this->domain);
    }
}
