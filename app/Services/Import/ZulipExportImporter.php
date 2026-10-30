<?php

namespace App\Services\Import;

use App\Models\Channel;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Importa l'esportazione completa di un server Zulip (./manage.py export -r <realm>
 * oppure "Esporta organizzazione" dal pannello di amministrazione).
 *
 * Rispetto all'API include TUTTI i messaggi diretti, gli stream privati anche senza
 * iscrizione dell'amministratore e i file caricati, letti direttamente dall'archivio.
 *
 * Struttura attesa (cartella estratta o archivio .tar.gz):
 *   realm.json            zerver_userprofile, zerver_stream, zerver_recipient,
 *                         zerver_subscription, zerver_huddle, zerver_reaction, ...
 *   messages-000001.json  zerver_message (+ zerver_usermessage), uno o più file
 *   attachment.json       zerver_attachment
 *   uploads/records.json  + file caricati in uploads/<path_id>
 */
class ZulipExportImporter extends ZulipImporter
{
    private const RECIPIENT_PERSONAL = 1;

    private const RECIPIENT_STREAM = 2;

    private const RECIPIENT_GROUP = 3;

    protected string $dir;

    protected array $realm = [];

    /** @var array<int,array{type:int,type_id:int}> */
    protected array $recipients = [];

    /** @var array<int,array<int,int>> recipient id -> utenti iscritti (attivi) */
    protected array $recipientMembers = [];

    /** @var array<int,array> recipient id -> stream */
    protected array $streamByRecipient = [];

    /** @var array<int,array{channel:Channel,stream:array}> stream id -> canale */
    protected array $channels = [];

    /** @var array<int,array> */
    protected array $usersById = [];

    /** @var array<int,array<int,array>> message id -> reazioni */
    protected array $reactions = [];

    /** @var array<int,?string> */
    protected array $lastTopic = [];

    protected ?string $tmpDir = null;

    /** @var array<string,string>|null */
    protected ?array $uploadMap = null;

    /**
     * @param  string  $path  cartella dell'esportazione o file .tar.gz
     * @param  string|null  $sourceUrl  URL del server Zulip originale: se coincide con quello usato
     *                                  per l'importazione via API, i messaggi già importati vengono riconosciuti
     */
    public function __construct(string $path, array $options = [], ?string $sourceUrl = null)
    {
        $this->dir = $this->resolveExport($path);
        $this->realm = $this->readJson('realm.json');

        $realmRow = $this->realm['zerver_realm'][0] ?? [];
        parent::__construct($sourceUrl ?: 'https://'.($realmRow['string_id'] ?? 'zulip').'.zulip-export', '', '', $options + ['dms' => true]);
        if (! $sourceUrl) {
            $this->source = 'zulip-export:'.($realmRow['string_id'] ?? 'realm').'-'.($realmRow['id'] ?? 0);
        }
    }

    public function run(): array
    {
        $realm = $this->realm['zerver_realm'][0] ?? [];
        $this->info('Esportazione Zulip: organizzazione "'.($realm['name'] ?? '?').'" in '.$this->dir);
        $this->indexRealm();

        $this->importUsers();
        foreach ($this->importStreams() as $item) {
            $this->channels[(int) $item['zulip']['stream_id']] = ['channel' => $item['channel'], 'stream' => $item['zulip']];
        }

        $files = glob($this->dir.'/messages-*.json') ?: [];
        sort($files, SORT_NATURAL);
        $this->info(count($files).' file di messaggi da elaborare');
        foreach ($files as $i => $file) {
            $data = $this->decode($file);
            foreach ($data['zerver_message'] ?? [] as $row) {
                try {
                    $this->routeMessage($row);
                } catch (\Throwable $e) {
                    $this->stats['errors']++;
                    $this->warn('  messaggio '.($row['id'] ?? '?').' non importato: '.$e->getMessage());
                }
            }
            unset($data);
            $this->info('  file '.($i + 1).'/'.count($files).' — '.$this->stats['messages'].' messaggi importati finora');
        }
        foreach ($this->channels as $c) {
            $this->dirty[$c['channel']->id] = $c['channel'];
        }
        $this->finish();
        $this->cleanup();

        return $this->stats;
    }

    // ------------------------------------------------------------ indicizzazione

    protected function indexRealm(): void
    {
        foreach ($this->realm['zerver_recipient'] ?? [] as $r) {
            $this->recipients[(int) $r['id']] = ['type' => (int) $r['type'], 'type_id' => (int) $r['type_id']];
        }
        foreach ($this->realm['zerver_subscription'] ?? [] as $s) {
            if (! array_key_exists('active', $s) || $s['active']) {
                $this->recipientMembers[(int) $s['recipient']][] = (int) $s['user_profile'];
            }
        }
        foreach ($this->realm['zerver_stream'] ?? [] as $st) {
            $rid = $st['recipient'] ?? collect($this->recipients)->search(fn ($r) => $r['type'] === self::RECIPIENT_STREAM && $r['type_id'] === (int) $st['id']);
            if ($rid !== null && $rid !== false) {
                $this->streamByRecipient[(int) $rid] = $st;
            }
        }
        foreach ($this->realm['zerver_reaction'] ?? [] as $r) {
            $this->reactions[(int) $r['message']][] = [
                'emoji_name' => $r['emoji_name'] ?? '',
                'emoji_code' => $r['emoji_code'] ?? '',
                'reaction_type' => $r['reaction_type'] ?? 'unicode_emoji',
                'user_id' => (int) $r['user_profile'],
            ];
        }
        $users = array_merge(
            $this->realm['zerver_userprofile'] ?? [],
            $this->realm['zerver_userprofile_mirrordummy'] ?? [],
            $this->realm['zerver_userprofile_crossrealm'] ?? [],
        );
        foreach ($users as $u) {
            $this->usersById[(int) $u['id']] = $u;
        }
    }

    // ------------------------------------------------- sorgenti dati (override)

    protected function fetchUsers(): array
    {
        return array_values(array_map(fn ($u) => [
            'user_id' => (int) $u['id'],
            'email' => $u['email'] ?? '',
            'delivery_email' => $u['delivery_email'] ?? ($u['email'] ?? ''),
            'full_name' => $u['full_name'] ?? '',
            'is_bot' => (bool) ($u['is_bot'] ?? ! empty($u['bot_type'])),
            'is_active' => (bool) ($u['is_active'] ?? true) && ! ($u['is_mirror_dummy'] ?? false),
            'role' => (int) ($u['role'] ?? 400),
        ], $this->usersById));
    }

    protected function fetchStreams(): array
    {
        return array_values(array_map(fn ($s) => [
            'stream_id' => (int) $s['id'],
            'name' => $s['name'],
            'description' => $s['description'] ?? '',
            'invite_only' => (bool) ($s['invite_only'] ?? false),
            'date_created' => isset($s['date_created']) ? (int) $s['date_created'] : null,
            'creator_id' => $s['creator'] ?? null,
            'is_archived' => (bool) ($s['deactivated'] ?? $s['is_archived'] ?? false),
        ], $this->realm['zerver_stream'] ?? []));
    }

    protected function fetchSubscribers(int $streamId): array
    {
        foreach ($this->streamByRecipient as $rid => $st) {
            if ((int) $st['id'] === $streamId) {
                return $this->recipientMembers[$rid] ?? [];
            }
        }

        return [];
    }

    protected function download(string $path): string
    {
        $path = ltrim(urldecode($path), '/');
        $candidates = [$this->dir.'/uploads/'.$path];
        // alcuni server antepongono l'id del realm o salvano con s3_path diverso
        $records = $this->uploadRecords();
        if (isset($records[$path])) {
            $candidates[] = $this->dir.'/uploads/'.$records[$path];
        }
        foreach ($candidates as $file) {
            $real = realpath($file);
            if ($real && str_starts_with($real, realpath($this->dir)) && is_file($real)) {
                return (string) file_get_contents($real);
            }
        }
        throw new RuntimeException('file non presente nell\'esportazione (uploads/'.$path.')');
    }

    // ---------------------------------------------------------------- messaggi

    protected function routeMessage(array $row): void
    {
        $rid = (int) $row['recipient'];
        $recipient = $this->recipients[$rid] ?? null;
        if (! $recipient) {
            $this->stats['skipped']++;

            return;
        }
        $msg = [
            'id' => (int) $row['id'],
            'sender_id' => (int) $row['sender'],
            'sender_email' => $this->usersById[(int) $row['sender']]['delivery_email'] ?? $this->usersById[(int) $row['sender']]['email'] ?? null,
            'sender_full_name' => $this->usersById[(int) $row['sender']]['full_name'] ?? null,
            'subject' => $row['subject'] ?? $row['topic_name'] ?? '',
            'content' => $row['content'] ?? '',
            'timestamp' => (int) $row['date_sent'],
            'last_edit_timestamp' => isset($row['last_edit_time']) ? (int) $row['last_edit_time'] : null,
            'reactions' => $this->reactions[(int) $row['id']] ?? [],
        ];

        if ($recipient['type'] === self::RECIPIENT_STREAM) {
            $streamId = $recipient['type_id'];
            if (! isset($this->channels[$streamId])) {
                $this->stats['skipped']++;   // stream escluso dai filtri

                return;
            }
            $last = $this->lastTopic[$streamId] ?? null;
            $this->storeMessage($this->channels[$streamId]['channel'], $msg, $streamId, $last);
            $this->lastTopic[$streamId] = $last;

            return;
        }
        if (! $this->options['dms']) {
            return;
        }
        $participants = $recipient['type'] === self::RECIPIENT_PERSONAL
            ? [$msg['sender_id'], $recipient['type_id']]
            : array_merge($this->recipientMembers[$rid] ?? [], [$msg['sender_id']]);
        $msg['display_recipient'] = array_map(fn ($id) => ['id' => $id], array_values(array_unique($participants)));
        $this->storeDirectMessage($msg);
    }

    // ------------------------------------------------------------ file e archivi

    protected function resolveExport(string $path): string
    {
        if (is_dir($path)) {
            return $this->findRealmDir($path);
        }
        if (! is_file($path)) {
            throw new RuntimeException("Percorso non trovato: {$path}");
        }
        if (! preg_match('/\.(tar\.gz|tgz|tar)$/i', $path)) {
            throw new RuntimeException('Formato non riconosciuto: indica la cartella estratta oppure il file .tar.gz dell\'esportazione.');
        }
        $this->tmpDir = storage_path('app/zulip-export-'.Str::random(8));
        @mkdir($this->tmpDir, 0775, true);
        $cmd = 'tar -x'.(preg_match('/\.tar$/i', $path) ? '' : 'z').'f '.escapeshellarg($path).' -C '.escapeshellarg($this->tmpDir).' 2>&1';
        $out = [];
        $code = 1;
        if (function_exists('exec')) {
            @exec($cmd, $out, $code);
        }
        if ($code !== 0) {
            // fallback senza comando tar (alcuni hosting disabilitano exec)
            $phar = new \PharData($path);
            $phar->extractTo($this->tmpDir, null, true);
        }

        return $this->findRealmDir($this->tmpDir);
    }

    protected function findRealmDir(string $root): string
    {
        if (is_file($root.'/realm.json')) {
            return rtrim($root, '/');
        }
        foreach (glob(rtrim($root, '/').'/*', GLOB_ONLYDIR) ?: [] as $sub) {
            if (is_file($sub.'/realm.json')) {
                return $sub;
            }
        }
        throw new RuntimeException("realm.json non trovato in {$root}: non sembra un'esportazione Zulip.");
    }

    protected function readJson(string $name): array
    {
        return $this->decode($this->dir.'/'.$name);
    }

    protected function decode(string $file): array
    {
        if (! is_file($file)) {
            throw new RuntimeException('File mancante nell\'esportazione: '.basename($file));
        }
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_BIGINT_AS_STRING);
        if (! is_array($data)) {
            throw new RuntimeException(basename($file).' non è un JSON valido: '.json_last_error_msg());
        }

        return $data;
    }

    /** @return array<string,string> path -> s3_path */
    protected function uploadRecords(): array
    {
        if ($this->uploadMap === null) {
            $map = [];
            $file = $this->dir.'/uploads/records.json';
            if (is_file($file)) {
                foreach ((json_decode((string) file_get_contents($file), true) ?: []) as $r) {
                    if (! empty($r['path'])) {
                        $map[$r['path']] = $r['s3_path'] ?? $r['path'];
                        if (! empty($r['s3_path'])) {
                            $map[$r['s3_path']] = $r['path'];
                        }
                    }
                }
            }
            $this->uploadMap = $map;
        }

        return $this->uploadMap;
    }

    protected function cleanup(): void
    {
        if ($this->tmpDir && is_dir($this->tmpDir)) {
            \Illuminate\Support\Facades\File::deleteDirectory($this->tmpDir);
        }
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
