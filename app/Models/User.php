<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'organization_id', 'name', 'email', 'password', 'role', 'title',
        'avatar_color', 'status_text', 'status_emoji', 'notification_prefs', 'last_seen_at',
        'avatar_path', 'is_bot', 'bot_owner_id', 'bot_description', 'bot_webhook_url', 'bot_webhook_secret',
    ];

    protected $hidden = ['password', 'remember_token', 'bot_webhook_secret'];

    protected $casts = [
        'password' => 'hashed',
        'notification_prefs' => 'array',
        'last_seen_at' => 'datetime',
        'email_verified_at' => 'datetime',
        'is_bot' => 'boolean',
    ];

    public const PALETTE = ['#4a154b', '#1264a3', '#2bac76', '#e8912d', '#e01e5a', '#5f3dc4', '#0b7285', '#862e9c'];

    protected static function booted(): void
    {
        static::creating(function (User $u) {
            if (! $u->avatar_color || $u->avatar_color === '#4a154b') {
                $u->avatar_color = self::PALETTE[crc32($u->email) % count(self::PALETTE)];
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class)->withPivot(['role', 'notify', 'last_read_at'])->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isBot(): bool
    {
        return (bool) $this->is_bot;
    }

    /** URL pubblico dell'immagine avatar (null = iniziali su colore). */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? url('/media/avatars/'.basename($this->avatar_path)) : null;
    }

    /** Nome usato nelle menzioni dei bot: @nomebot (minuscolo, senza spazi). */
    public function handle(): string
    {
        return strtolower(preg_replace('/[^\p{L}\d._-]+/u', '', $this->name));
    }

    public function deviceTokens(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function isGuest(): bool
    {
        return $this->role === 'guest';
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $ini = implode('', array_map(fn ($p) => mb_substr($p, 0, 1), array_slice($parts, 0, 2)));

        return mb_strtoupper($ini ?: mb_substr($this->email, 0, 2));
    }

    public function prefs(): array
    {
        return array_merge([
            'mentions' => true, 'dms' => true, 'channels' => 'all', 'email' => false, 'push' => true, 'sound' => true, 'theme' => 'light',
        ], $this->notification_prefs ?? []);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at && $this->last_seen_at->gt(now()->subMinutes(3));
    }
}
