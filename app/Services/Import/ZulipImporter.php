<?php

namespace App\Services\Import;

use App\Http\Controllers\Api\AuthController;
use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Reaction;
use App\Models\User;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Importa utenti, canali (stream), topic, messaggi, reazioni, allegati e messaggi
 * diretti da un server Zulip tramite la sua API REST (/api/v1).
 *
 * Mappatura:
 *  - stream pubblico/privato  -> canale public/private
 *  - topic                    -> di default tutti i messaggi restano nel canale, con il nome del topic
 *                                quando cambia (topics=flat); topics=none senza nome; topics=threads = thread
 *  - messaggio diretto 1:1    -> DM; di gruppo -> canale privato
 *  - /user_uploads/...        -> allegati AcidTalk (immagini, PDF, audio...)
 *
 * È idempotente: le corrispondenze sono salvate in import_mappings, quindi
 * rilanciando il comando vengono importati solo i messaggi nuovi.
 */
class ZulipImporter
{
    public array $stats = ['users' => 0, 'channels' => 0, 'messages' => 0, 'skipped' => 0, 'attachments' => 0, 'reactions' => 0, 'dm_channels' => 0, 'errors' => 0];

    protected string $base;


    protected string $source;

    protected Organization $org;

    /** @var array<int,int> zulip user id -> local user id */
    protected array $userMap = [];

    /** @var array<int,Channel> canali da aggiornare (last_message_at) a fine importazione */
    protected array $dirty = [];

    /** @var Closure(string,string):void */
    protected Closure $log;

    public function __construct(
        string $url,
        protected string $email,
        protected string $apiKey,
        protected array $options = [],
    ) {
        $this->base = rtrim($url, '/');
        $host = parse_url($this->base, PHP_URL_HOST) ?: $this->base;
        $this->source = 'zulip:'.$host;
        $this->org = Organization::current();
        $this->log = fn (string $level, string $msg) => null;
        $this->options += [
            'streams' => [],          // nomi degli stream da importare (vuoto = tutti)
            'topics' => 'flat',       // flat | none | threads
            'dms' => false,           // importa i messaggi diretti visibili all'account API
            'uploads' => true,        // scarica gli allegati
            'since' => null,          // Carbon: ignora messaggi precedenti
            'default_password' => null,
            'batch' => 1000,
            'include_archived' => false,
        ];
    }

    public function onLog(Closure $log): static
    {
        $this->log = $log;

        return $this;
    }

    public function run(): array
    {
        $this->info('Connessione a '.$this->base.' …');
        $me = $this->get('users/me');
        $this->info('Autenticato come '.($me['full_name'] ?? $this->email).' (ruolo Zulip '.($me['role'] ?? '?').')');

        $this->importUsers();
        $streams = $this->importStreams();

        foreach ($streams as $stream) {
            $this->importStreamMessages($stream);
        }
        if ($this->options['dms']) {
            $this->importDirectMessages();
        }
        $this->finish();

        return $this->stats;
    }

    // ------------------------------------------------------------------ utenti

    /** @return array<int,array> utenti nel formato dell'API Zulip */
    protected function fetchUsers(): array
    {
        return $this->get('users', ['client_gravatar' => 'false'])['members'] ?? [];
    }

    protected function importUsers(): void
    {
        $members = $this->fetchUsers();
        $this->info(count($members).' utenti trovati su Zulip');

        foreach ($members as $m) {
            $zid = (int) $m['user_id'];
            if ($local = $this->mapped('user', $zid)) {
                $this->userMap[$zid] = $local;

                continue;
            }
            $email = strtolower(trim($m['delivery_email'] ?? '') ?: trim($m['email'] ?? ''));
            if (! $email) {
                continue;
            }
            $user = User::where('email', $email)->first();
            if (! $user) {
                $user = User::create([
                    'organization_id' => $this->org->id,
                    'name' => $m['full_name'] ?: Str::before($email, '@'),
                    'email' => $email,
                    'password' => $this->options['default_password'] ?: null,
                    'role' => $this->role($m),
                    'title' => ! empty($m['is_bot']) ? 'Bot (importato da Zulip)' : null,
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();
                if (! empty($m['is_active']) && empty($m['is_bot'])) {
                    AuthController::onboard($user);
                }
                $this->stats['users']++;
            }
            $this->map('user', $zid, $user->id);
            $this->userMap[$zid] = $user->id;
        }
    }

    protected function role(array $m): string
    {
        $role = (int) ($m['role'] ?? 400);
        if (! empty($m['is_admin']) || ! empty($m['is_owner']) || in_array($role, [100, 200], true)) {
            return 'admin';
        }

        return (! empty($m['is_guest']) || $role === 600) ? 'guest' : 'member';
    }

    protected function localUser(array $msg): ?int
    {
        $zid = (int) ($msg['sender_id'] ?? 0);
        if (isset($this->userMap[$zid])) {
            return $this->userMap[$zid];
        }
        // mittente non presente in /users (es. utente cross-realm): lo creiamo al volo
        $email = strtolower($msg['sender_email'] ?? '');
        if (! $email) {
            return null;
        }
        $user = User::firstOrCreate(['email' => $email], [
            'organization_id' => $this->org->id,
            'name' => $msg['sender_full_name'] ?? Str::before($email, '@'),
            'role' => 'member',
        ]);
        $this->map('user', $zid, $user->id);

        return $this->userMap[$zid] = $user->id;
    }

    // ------------------------------------------------------------------ canali

    /** @return array<int,array> stream nel formato dell'API Zulip */
    protected function fetchStreams(): array
    {
        return $this->get('streams', [
            'include_public' => 'true', 'include_subscribed' => 'true',
            'include_all_active' => 'true', 'include_all' => 'true',
            'include_default' => 'true',
        ])['streams'] ?? [];
    }

    /** @return array<int,int> id Zulip degli iscritti allo stream */
    protected function fetchSubscribers(int $streamId): array
    {
        return $this->get("streams/{$streamId}/members")['subscribers'] ?? [];
    }

    protected function importStreams(): array
    {
        $streams = $this->fetchStreams();

        $filter = array_map(fn ($s) => mb_strtolower(trim($s)), $this->options['streams']);
        if ($filter) {
            $streams = array_values(array_filter($streams, fn ($s) => in_array(mb_strtolower($s['name']), $filter, true)));
        }
        if (! $this->options['include_archived']) {
            $streams = array_values(array_filter($streams, fn ($s) => empty($s['is_archived'])));
        }
        $this->info(count($streams).' stream da importare');

        $result = [];
        foreach ($streams as $s) {
            $channel = $this->channelForStream($s);
            $result[] = ['zulip' => $s, 'channel' => $channel];
        }

        return $result;
    }

    protected function channelForStream(array $s): Channel
    {
        $zid = (int) $s['stream_id'];
        if (($id = $this->mapped('channel', $zid)) && ($c = Channel::find($id))) {
            $this->syncMembers($c, $zid);

            return $c;
        }
        $slug = Str::slug($s['name']) ?: 'zulip-'.$zid;
        $channel = Channel::where('organization_id', $this->org->id)->where('slug', $slug)->first();
        if (! $channel) {
            $channel = Channel::create([
                'organization_id' => $this->org->id,
                'name' => $slug,
                'slug' => $slug,
                'type' => ! empty($s['invite_only']) ? 'private' : 'public',
                'archived_at' => ! empty($s['is_archived']) ? now() : null,
                'description' => Str::limit(strip_tags($s['description'] ?? ''), 1000, ''),
                'topic' => Str::limit(strip_tags($s['description'] ?? ''), 250, ''),
                'ai_enabled' => true,
                'created_by' => $this->userMap[(int) ($s['creator_id'] ?? 0)] ?? null,
            ]);
            if (! empty($s['date_created'])) {
                $channel->forceFill(['created_at' => Carbon::createFromTimestamp($s['date_created'], config('app.timezone'))])->save();
            }
            $this->stats['channels']++;
            $this->info("  + canale #{$slug}".(! empty($s['invite_only']) ? ' (privato)' : '').(! empty($s['is_archived']) ? ' (archiviato)' : ''));
        }
        $this->map('channel', $zid, $channel->id);
        $this->syncMembers($channel, $zid);

        return $channel;
    }

    protected function syncMembers(Channel $channel, int $streamId): void
    {
        try {
            $subs = $this->fetchSubscribers($streamId);
        } catch (RuntimeException $e) {
            $this->warn("  impossibile leggere gli iscritti dello stream {$streamId}: ".$e->getMessage());

            return;
        }
        $ids = collect($subs)->map(fn ($z) => $this->userMap[(int) $z] ?? null)->filter()->unique()->values()->all();
        if ($ids) {
            $channel->members()->syncWithoutDetaching($ids);
        }
    }

    // --------------------------------------------------------------- messaggi

    protected function importStreamMessages(array $item): void
    {
        $s = $item['zulip'];
        $channel = $item['channel'];
        $this->info("Messaggi di #{$channel->slug} …");
        $narrow = [['operator' => 'stream', 'operand' => $s['name']]];
        $lastTopic = null;
        $this->paginate($narrow, function (array $msg) use ($channel, $s, &$lastTopic) {
            $this->storeMessage($channel, $msg, (int) $s['stream_id'], $lastTopic);
        });
        $this->touchChannel($channel);
    }

    protected function importDirectMessages(): void
    {
        $this->info('Messaggi diretti visibili all\'account '.$this->email.' …');
        $narrow = [['operator' => 'is', 'operand' => 'private']];
        $this->paginate($narrow, fn (array $msg) => $this->storeDirectMessage($msg));
    }

    /** Messaggio diretto: 1:1 -> DM, di gruppo -> canale privato. */
    protected function storeDirectMessage(array $msg): void
    {
        $recipients = collect(is_array($msg['display_recipient']) ? $msg['display_recipient'] : [])
            ->map(fn ($r) => $this->userMap[(int) $r['id']] ?? null)->filter()->unique()->sort()->values();
        if ($recipients->count() < 2) {
            $this->stats['skipped']++;   // note a se stessi

            return;
        }
        $key = $recipients->implode('-');
        $channel = ($id = $this->mapped('dm', $key)) ? Channel::find($id) : null;
        if (! $channel) {
            if ($recipients->count() === 2) {
                $channel = Channel::directBetween(User::find($recipients[0]), User::find($recipients[1]));
            } else {
                $names = User::whereIn('id', $recipients)->pluck('name')->map(fn ($n) => Str::slug(Str::before($n, ' ')))->implode('-');
                $channel = Channel::create([
                    'organization_id' => $this->org->id,
                    'name' => Str::limit('gruppo-'.$names, 70, ''),
                    'slug' => Str::limit('gruppo-'.$names, 60, '').'-'.substr(md5($key), 0, 6),
                    'type' => 'private',
                    'description' => 'Conversazione di gruppo importata da Zulip',
                    'created_by' => $recipients[0],
                ]);
                $channel->members()->syncWithoutDetaching($recipients->all());
            }
            $this->map('dm', $key, $channel->id);
            $this->stats['dm_channels']++;
        }
        $none = null;
        $this->storeMessage($channel, $msg, null, $none);
        $this->dirty[$channel->id] = $channel;
    }

    /** Scorre i messaggi dal più vecchio al più recente, a pagine. */
    protected function paginate(array $narrow, Closure $each): void
    {
        $anchor = 'oldest';
        $include = true;
        $since = $this->options['since'];
        do {
            $res = $this->get('messages', [
                'anchor' => $anchor,
                'include_anchor' => $include ? 'true' : 'false',
                'num_before' => 0,
                'num_after' => $this->options['batch'],
                'narrow' => json_encode($narrow),
                'apply_markdown' => 'false',
                'client_gravatar' => 'true',
            ]);
            $messages = $res['messages'] ?? [];
            foreach ($messages as $msg) {
                if ($since && $msg['timestamp'] < $since->getTimestamp()) {
                    continue;
                }
                try {
                    $each($msg);
                } catch (\Throwable $e) {
                    $this->stats['errors']++;
                    $this->warn('  messaggio '.$msg['id'].' non importato: '.$e->getMessage());
                }
            }
            if ($messages) {
                $anchor = (string) end($messages)['id'];
                $include = false;
                $this->info('  … '.$this->stats['messages'].' messaggi importati finora');
            }
        } while ($messages && empty($res['found_newest']));
    }

    protected function storeMessage(Channel $channel, array $msg, ?int $streamId, ?string &$lastTopic): void
    {
        if ($this->mapped('message', $msg['id'])) {
            $this->stats['skipped']++;
            $lastTopic = trim((string) ($msg['subject'] ?? $msg['topic'] ?? ''));

            return;
        }
        if ($this->options['since'] && $msg['timestamp'] < $this->options['since']->getTimestamp()) {
            return;
        }
        $userId = $this->localUser($msg);
        $created = Carbon::createFromTimestamp($msg['timestamp'], config('app.timezone'));
        [$body, $uploads] = $this->convertContent((string) ($msg['content'] ?? ''));

        $topic = trim((string) ($msg['subject'] ?? $msg['topic'] ?? ''));
        $parentId = null;
        $isNoTopic = $topic === '' || in_array(mb_strtolower($topic), ['(no topic)', '(nessun argomento)', 'general chat'], true);

        if ($streamId !== null && ! $isNoTopic) {
            if ($this->options['topics'] === 'threads') {
                $key = $streamId.'|'.mb_strtolower($topic);
                $rootId = $this->mapped('topic', $key);
                if ($rootId && Message::withTrashed()->whereKey($rootId)->exists()) {
                    $parentId = $rootId;
                } else {
                    $body = '**'.$this->escapeMd($topic)."**\n\n".$body;
                }
            } elseif ($this->options['topics'] === 'flat' && $topic !== $lastTopic) {
                $body = '**['.$this->escapeMd($topic).']** '.$body;
            }
        }
        $lastTopic = $topic;

        DB::transaction(function () use ($channel, $msg, $userId, $created, $body, $uploads, $parentId, $streamId, $topic, $isNoTopic) {
            $m = new Message([
                'channel_id' => $channel->id,
                'user_id' => $userId,
                'parent_id' => $parentId,
                'body' => $body,
                'kind' => 'text',
                'is_ai' => false,
                'meta' => array_filter(['source' => 'zulip', 'zulip_id' => $msg['id'], 'zulip_topic' => $topic ?: null]),
            ]);
            $m->created_at = $created;
            $m->updated_at = $created;
            if (! empty($msg['last_edit_timestamp'])) {
                $m->edited_at = Carbon::createFromTimestamp($msg['last_edit_timestamp'], config('app.timezone'));
            }
            $m->save();
            $this->map('message', $msg['id'], $m->id);

            if ($parentId) {
                Message::whereKey($parentId)->increment('reply_count', 1, ['last_reply_at' => $created]);
            } elseif ($streamId !== null && ! $isNoTopic && $this->options['topics'] === 'threads') {
                $this->map('topic', $streamId.'|'.mb_strtolower($topic), $m->id);
            }

            foreach ($uploads as $u) {
                $this->attach($m, $u, $userId);
            }
            foreach ((array) ($msg['reactions'] ?? []) as $r) {
                if (is_array($r)) {
                    $this->react($m, $r);
                }
            }
            if ($userId) {
                $channel->members()->syncWithoutDetaching([$userId]);
            }
        });
        $this->stats['messages']++;
    }

    protected function finish(): void
    {
        foreach ($this->dirty as $channel) {
            $this->touchChannel($channel);
        }
        $this->dirty = [];
    }

    protected function touchChannel(Channel $channel): void
    {
        $last = Message::where('channel_id', $channel->id)->max('created_at');
        if ($last) {
            $channel->forceFill(['last_message_at' => $last])->save();
        }
    }

    // --------------------------------------------------- conversione Markdown

    /**
     * Converte il Markdown di Zulip nel Markdown di AcidTalk ed estrae gli upload.
     *
     * @return array{0:string,1:array<int,array{name:string,path:string}>}
     */
    public function convertContent(string $md): array
    {
        $uploads = [];

        // link a file caricati su Zulip: [nome](/user_uploads/…) -> allegato
        $md = preg_replace_callback(
            '~\[([^\]]*)\]\((?:'.preg_quote($this->base, '~').')?/user_uploads/([^)\s]+)\)~u',
            function ($m) use (&$uploads) {
                $uploads[] = ['name' => $m[1] !== '' ? $m[1] : basename($m[2]), 'path' => $m[2]];

                return '';
            },
            $md
        );

        // menzioni: @**Nome**, @**Nome|12**, @_**Nome**  -> **@Nome**
        $md = preg_replace('/@_?\*\*([^*|]+)(?:\|\d+)?\*\*/u', '**@$1**', $md);
        // gruppi utente: @*gruppo*
        $md = preg_replace('/@_?\*([^*\s][^*]*)\*/u', '**@$1**', $md);
        // link a stream/topic: #**stream>topic** / #**stream**
        $md = preg_replace_callback('/#\*\*([^*>]+)(?:>([^*]+))?\*\*/u', fn ($m) => '**#'.Str::slug($m[1]).(isset($m[2]) && $m[2] !== '' ? ' › '.$m[2] : '').'**', $md);
        // blocchi ```quote e ```spoiler -> citazione
        $md = preg_replace_callback('/^(`{3,}|~{3,})\s*(quote|spoiler)\s*(.*)\R(.*?)\R\1\s*$/msu', function ($m) {
            $lines = preg_split('/\R/u', $m[4]);
            $title = $m[2] === 'spoiler' && trim($m[3]) !== '' ? '> **'.trim($m[3])."**\n" : '';

            return $title.implode("\n", array_map(fn ($l) => '> '.$l, $lines));
        }, $md);
        // ```math -> blocco di codice latex
        $md = preg_replace('/^(`{3,})\s*math\s*$/mu', '$1latex', $md);
        // $$formula$$ inline -> codice
        $md = preg_replace('/\$\$(.+?)\$\$/u', '`$1`', $md);
        // :emoji: più comuni -> Unicode
        $md = preg_replace_callback('/:([a-z0-9_+\-]+):/u', fn ($m) => self::EMOJI[$m[1]] ?? $m[0], $md);

        $md = preg_replace("/\n{3,}/", "\n\n", trim($md));

        return [$md, $uploads];
    }

    protected function escapeMd(string $s): string
    {
        return str_replace(['*', '_', '`'], ['\*', '\_', '\`'], $s);
    }

    // ------------------------------------------------- allegati e reazioni

    protected function attach(Message $m, array $u, ?int $userId): void
    {
        if (! $this->options['uploads']) {
            $m->body = trim($m->body."\n".'('.$u['name'].' — allegato Zulip non importato)');
            $m->save();

            return;
        }
        try {
            $bytes = $this->download($u['path']);
        } catch (\Throwable $e) {
            $this->warn('  allegato '.$u['name'].' non scaricato: '.$e->getMessage());
            $m->body = trim($m->body."\n".'('.$u['name'].' — allegato non disponibile)');
            $m->save();
            $this->stats['errors']++;

            return;
        }
        $name = Str::limit(basename(urldecode($u['name'])), 180, '');
        $file = basename(urldecode($u['path']));
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $path = 'attachments/zulip/'.$m->created_at->format('Y/m').'/'.Str::random(16).($ext ? '.'.$ext : '');
        Storage::disk('public')->put($path, $bytes);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        if ($ext && ! str_contains($name, '.')) {
            $name .= '.'.$ext;
        }
        $kind = Attachment::kindFromMime($mime, $name);
        if ($kind === 'audio') {
            $mime = Attachment::audioMime($mime, $name);
        }
        Attachment::create([
            'message_id' => $m->id,
            'user_id' => $userId ?? $m->channel->created_by ?? User::where('role', 'admin')->value('id'),
            'disk' => 'public',
            'path' => $path,
            'name' => $name,
            'mime' => $mime,
            'size' => strlen($bytes),
            'kind' => $kind,
        ]);
        $this->stats['attachments']++;
    }

    protected function download(string $path): string
    {
        // API moderna: restituisce un URL temporaneo firmato
        $res = $this->client()->get($this->base.'/api/v1/user_uploads/'.$path);
        if ($res->successful() && ($tmp = $res->json('url'))) {
            $file = Http::timeout(120)->get(str_starts_with($tmp, 'http') ? $tmp : $this->base.$tmp);
            if ($file->successful()) {
                return $file->body();
            }
        }
        // fallback: download diretto con Basic Auth
        $file = $this->client()->timeout(120)->get($this->base.'/user_uploads/'.$path);
        if (! $file->successful()) {
            throw new RuntimeException('HTTP '.$file->status());
        }

        return $file->body();
    }

    protected function react(Message $m, array $r): void
    {
        $userId = $this->userMap[(int) ($r['user_id'] ?? $r['user']['id'] ?? 0)] ?? null;
        if (! $userId) {
            return;
        }
        $emoji = ($r['reaction_type'] ?? 'unicode_emoji') === 'unicode_emoji' && ! empty($r['emoji_code'])
            ? implode('', array_map(fn ($cp) => mb_chr(hexdec($cp), 'UTF-8'), explode('-', $r['emoji_code'])))
            : ':'.($r['emoji_name'] ?? '?').':';
        $emoji = mb_substr($emoji, 0, 16);
        $created = Reaction::firstOrCreate(['message_id' => $m->id, 'user_id' => $userId, 'emoji' => $emoji]);
        if ($created->wasRecentlyCreated) {
            $this->stats['reactions']++;
        }
    }

    // ---------------------------------------------------------------- HTTP

    protected function client(): PendingRequest
    {
        return Http::withBasicAuth($this->email, $this->apiKey)->acceptJson()->timeout(60)
            ->withUserAgent('AcidTalk-Zulip-Importer/1.0');
    }

    protected function get(string $endpoint, array $query = []): array
    {
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            /** @var Response $res */
            $res = $this->client()->get($this->base.'/api/v1/'.$endpoint, $query);
            if ($res->status() === 429) {
                $wait = (int) ($res->header('Retry-After') ?: $res->json('retry-after') ?: 2 * $attempt);
                $this->warn("  limite di richieste Zulip raggiunto, attendo {$wait}s …");
                sleep(max(1, min(60, $wait)));

                continue;
            }
            if (! $res->successful() || $res->json('result') === 'error') {
                throw new RuntimeException("Zulip {$endpoint}: HTTP {$res->status()} ".($res->json('msg') ?? Str::limit($res->body(), 200)));
            }

            return $res->json() ?? [];
        }
        throw new RuntimeException("Zulip {$endpoint}: troppe richieste (429)");
    }

    // ------------------------------------------------------------- mappature

    protected function mapped(string $type, string|int $external): ?int
    {
        $id = DB::table('import_mappings')->where(['source' => $this->source, 'type' => $type, 'external_id' => (string) $external])->value('local_id');

        return $id ? (int) $id : null;
    }

    protected function map(string $type, string|int $external, int $local): void
    {
        DB::table('import_mappings')->updateOrInsert(
            ['source' => $this->source, 'type' => $type, 'external_id' => (string) $external],
            ['local_id' => $local, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    protected function info(string $m): void
    {
        ($this->log)('info', $m);
    }

    protected function warn(string $m): void
    {
        ($this->log)('warn', $m);
    }

    public const EMOJI = [
        'smile' => '😄', 'smiley' => '😃', 'grinning' => '😀', 'joy' => '😂', 'laughing' => '😆', 'wink' => '😉',
        'slight_smile' => '🙂', 'blush' => '😊', 'heart_eyes' => '😍', 'thinking' => '🤔', 'cry' => '😢', 'sob' => '😭',
        'rage' => '😡', 'angry' => '😠', 'sweat_smile' => '😅', 'scream' => '😱', 'sunglasses' => '😎', 'upside_down' => '🙃',
        'thumbs_up' => '👍', '+1' => '👍', 'thumbs_down' => '👎', '-1' => '👎', 'clap' => '👏', 'pray' => '🙏',
        'ok_hand' => '👌', 'wave' => '👋', 'muscle' => '💪', 'raised_hands' => '🙌', 'point_up' => '☝️',
        'heart' => '❤️', 'fire' => '🔥', 'tada' => '🎉', 'rocket' => '🚀', 'star' => '⭐', 'eyes' => '👀',
        'check' => '✅', 'check_mark' => '✔️', 'white_check_mark' => '✅', 'x' => '❌', 'warning' => '⚠️',
        'bulb' => '💡', 'coffee' => '☕', 'beer' => '🍺', 'pizza' => '🍕', 'calendar' => '📅', 'email' => '📧',
        'phone' => '📞', 'computer' => '💻', 'bug' => '🐛', 'lock' => '🔒', 'question' => '❓', 'exclamation' => '❗',
        'octopus' => '🐙', 'party_popper' => '🎉', 'working_on_it' => '🛠️', 'hundred' => '💯', '100' => '💯',
    ];
}
