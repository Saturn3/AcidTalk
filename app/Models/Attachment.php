<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    protected $fillable = ['message_id', 'user_id', 'disk', 'path', 'name', 'mime', 'size', 'kind', 'meta'];

    protected $casts = ['meta' => 'array'];

    protected $appends = ['url'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /** Estensioni che trattiamo sempre come audio (anche se il server le rileva come video/webm, video/mp4, application/ogg...). */
    public const AUDIO_EXT = ['mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'flac', 'weba', 'mka'];

    /**
     * Tipo di allegato. $hint è il MIME dichiarato dal browser oppure 'voice' per i messaggi vocali:
     * serve perché finfo riconosce un .webm solo audio (MediaRecorder di Chrome/Firefox) come "video/webm".
     */
    public static function kindFromMime(?string $mime, string $name = '', ?string $hint = null): string
    {
        $mime = strtolower((string) $mime);
        $hint = strtolower((string) $hint);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $ambiguous = in_array($mime, ['video/webm', 'video/mp4', 'video/ogg', 'application/ogg', 'application/octet-stream', ''], true);

        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            $mime === 'application/pdf' || $ext === 'pdf' => 'pdf',
            str_starts_with($mime, 'audio/') => 'audio',
            $ambiguous && ($hint === 'voice' || str_starts_with($hint, 'audio/') || in_array($ext, self::AUDIO_EXT, true)
                || ($ext === 'webm' && str_starts_with(strtolower(basename($name)), 'vocale-'))) => 'audio',
            str_starts_with($mime, 'video/') => 'video',
            default => 'file',
        };
    }

    /** MIME da usare quando l'allegato è audio ma il server l'ha rilevato come video/contenitore generico. */
    public static function audioMime(string $mime, string $name): string
    {
        if (str_starts_with($mime, 'audio/')) {
            return $mime;
        }
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'mp3' => 'audio/mpeg', 'm4a', 'aac', 'mp4' => 'audio/mp4', 'ogg', 'oga', 'opus' => 'audio/ogg',
            'wav' => 'audio/wav', 'flac' => 'audio/flac', default => 'audio/webm',
        };
    }
}
