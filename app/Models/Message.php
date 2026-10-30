<?php

namespace App\Models;

use App\Services\MessageRenderer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use SoftDeletes;

    protected $fillable = ['channel_id', 'user_id', 'parent_id', 'body', 'kind', 'is_ai', 'meta', 'reply_count', 'last_reply_at', 'edited_at'];

    protected $casts = ['meta' => 'array', 'is_ai' => 'boolean', 'edited_at' => 'datetime', 'last_reply_at' => 'datetime'];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Message::class, 'parent_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class);
    }

    public function linkPreviews(): HasMany
    {
        return $this->hasMany(LinkPreview::class);
    }

    /** HTML sicuro (Markdown + sanitizzazione). */
    public function html(): string
    {
        return app(MessageRenderer::class)->render($this->body ?? '');
    }

    /** @return array<int,string> */
    public function urls(): array
    {
        preg_match_all('~https?://[^\s<>()"\']+~i', $this->body ?? '', $m);

        return array_values(array_unique(array_map(fn ($u) => rtrim($u, '.,;:!?*_~\''), $m[0] ?? [])));
    }

    public function mentionsAi(): bool
    {
        return (bool) preg_match('/(^|\s)@ai\b/iu', $this->body ?? '');
    }

    /** @return array<int,string> nomi utente menzionati con @Nome */
    public function mentions(): array
    {
        preg_match_all('/@([\p{L}\d._-]+)/u', $this->body ?? '', $m);

        return array_values(array_unique(array_map('strtolower', $m[1] ?? [])));
    }

    public function canBeEditedBy(User $u): bool
    {
        return $this->user_id === $u->id && ! $this->is_ai;
    }

    public function canBeDeletedBy(User $u): bool
    {
        return $this->user_id === $u->id || $u->isAdmin();
    }
}
