<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Invite extends Model
{
    protected $fillable = ['organization_id', 'invited_by', 'email', 'role', 'token', 'channel_ids', 'expires_at', 'accepted_at'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected $casts = ['channel_ids' => 'array', 'expires_at' => 'datetime', 'accepted_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (Invite $i) {
            $i->token ??= Str::random(48);
            $i->expires_at ??= now()->addDays(7);
        });
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isValid(): bool
    {
        return ! $this->accepted_at && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function url(): string
    {
        return url('/invite/'.$this->token);
    }
}
