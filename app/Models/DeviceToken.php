<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Token FCM di un dispositivo (app Android, iOS, macOS) per le notifiche push. */
class DeviceToken extends Model
{
    protected $fillable = ['user_id', 'token', 'platform', 'device_name', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
