<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Channel extends Model
{
    protected $fillable = ['organization_id', 'name', 'slug', 'type', 'topic', 'description', 'ai_enabled', 'created_by', 'last_message_at', 'archived_at'];

    protected $casts = ['ai_enabled' => 'boolean', 'last_message_at' => 'datetime', 'archived_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (Channel $c) {
            if (! $c->slug && in_array($c->type, ['public', 'private'])) {
                $c->slug = Str::slug($c->name) ?: Str::random(8);
            }
        });
        // i riferimenti #canale nei messaggi vanno ricalcolati quando i canali cambiano
        static::saved(fn () => \App\Services\MessageRenderer::$channelRefs = null);
        static::deleted(fn () => \App\Services\MessageRenderer::$channelRefs = null);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['role', 'notify', 'last_read_at'])->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(VideoCall::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Canali visibili all'utente: pubblici della sua organizzazione + quelli di cui è membro.
     *
     * @param  bool|string  $archived  false = solo attivi (default), 'only' = solo archiviati, true = tutti
     */
    public function scopeVisibleTo(Builder $q, User $user, bool|string $archived = false): Builder
    {
        return $q->where('organization_id', $user->organization_id)
            ->when($archived === false, fn ($w) => $w->whereNull('archived_at'))
            ->when($archived === 'only', fn ($w) => $w->whereNotNull('archived_at'))
            ->where(function (Builder $w) use ($user) {
                if (! $user->isGuest()) {
                    $w->where('type', 'public');
                }
                $w->orWhereHas('members', fn (Builder $m) => $m->where('users.id', $user->id));
            });
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** I canali archiviati sono in sola lettura. */
    public function ensureWritable(): void
    {
        abort_if($this->isArchived(), 423, "Il canale #{$this->name} è archiviato ed è in sola lettura. Ripristinalo per scrivere.");
    }

    public function isMember(User $user): bool
    {
        return $this->members()->where('users.id', $user->id)->exists();
    }

    public function canRead(User $user): bool
    {
        if ($this->organization_id !== $user->organization_id) {
            return false;
        }
        if ($this->type === 'public' && ! $user->isGuest()) {
            return true;
        }

        return $this->isMember($user);
    }

    /**
     * Conferme di lettura: ultimo momento di lettura di ogni membro (escluso $me, al massimo 100).
     *
     * @return array<int,array{user_id:int,name:string,last_read_at:?string}>
     */
    public function readReceipts(?User $me = null): array
    {
        if (! Organization::current()->setting('read_receipts', true)) {
            return [];
        }

        return $this->members()->where('users.is_bot', false)
            ->when($me, fn ($q) => $q->where('users.id', '!=', $me->id))
            ->orderByDesc('channel_user.last_read_at')->limit(100)
            ->get(['users.id', 'users.name'])
            ->map(fn ($u) => [
                'user_id' => $u->id, 'name' => $u->name,
                'last_read_at' => $u->pivot->last_read_at ? \Illuminate\Support\Carbon::parse($u->pivot->last_read_at)->toIso8601String() : null,
            ])->values()->all();
    }

    public function isDirect(): bool
    {
        return in_array($this->type, ['dm', 'ai_dm']);
    }

    /** Nome mostrato: per i DM è il nome dell'altro partecipante. */
    public function displayNameFor(User $user): string
    {
        if ($this->type === 'ai_dm') {
            return $this->organization->setting('ai_bot_name', 'AI');
        }
        if ($this->type === 'dm') {
            $other = $this->members->firstWhere('id', '!=', $user->id);

            return $other?->name ?? 'Me';
        }

        return $this->name;
    }

    /** Trova o crea il DM tra due utenti. */
    public static function directBetween(User $a, User $b): self
    {
        $existing = static::where('type', 'dm')->where('organization_id', $a->organization_id)
            ->whereHas('members', fn ($q) => $q->where('users.id', $a->id))
            ->whereHas('members', fn ($q) => $q->where('users.id', $b->id))
            ->first();
        if ($existing) {
            return $existing;
        }
        $c = static::create(['organization_id' => $a->organization_id, 'name' => 'dm', 'type' => 'dm', 'created_by' => $a->id]);
        $c->members()->attach([$a->id => ['role' => 'owner'], $b->id => ['role' => 'member']]);

        return $c;
    }

    public static function aiDirectFor(User $u): self
    {
        $existing = static::where('type', 'ai_dm')->where('organization_id', $u->organization_id)
            ->whereHas('members', fn ($q) => $q->where('users.id', $u->id))->first();
        if ($existing) {
            return $existing;
        }
        $c = static::create(['organization_id' => $u->organization_id, 'name' => 'ai', 'type' => 'ai_dm', 'created_by' => $u->id, 'ai_enabled' => true]);
        $c->members()->attach($u->id, ['role' => 'owner']);

        return $c;
    }
}
