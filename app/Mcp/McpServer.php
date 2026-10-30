<?php

namespace App\Mcp;

use App\Events\MessageSent;
use App\Http\Resources\MessageResource;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Server MCP (Model Context Protocol) — trasporto Streamable HTTP, JSON-RPC 2.0.
 *
 * Endpoint: POST /api/mcp   Header: Authorization: Bearer <token Sanctum>
 * Compatibile con Claude.ai / Claude Desktop (connettore MCP remoto), Perplexity e client generici.
 * Metodi: initialize, ping, tools/list, tools/call, resources/list, resources/read, prompts/list.
 */
class McpServer
{
    public const PROTOCOL = '2025-06-18';

    public function handle(Request $request): ?array
    {
        $body = $request->json()->all();
        if (array_is_list($body)) { // batch
            $out = array_values(array_filter(array_map(fn ($b) => $this->one($b, $request->user()), $body)));

            return $out ?: null;
        }

        return $this->one($body, $request->user());
    }

    private function one(array $body, User $user): ?array
    {
        $id = $body['id'] ?? null;
        $method = (string) ($body['method'] ?? '');
        $params = (array) ($body['params'] ?? []);

        try {
            $result = match ($method) {
                'initialize' => [
                    'protocolVersion' => $params['protocolVersion'] ?? self::PROTOCOL,
                    'serverInfo' => ['name' => 'acidtalk', 'version' => '2.0.0'],
                    'capabilities' => ['tools' => ['listChanged' => false], 'resources' => ['listChanged' => false], 'prompts' => ['listChanged' => false]],
                    'instructions' => 'Server MCP di AcidTalk: consente di leggere canali, cercare e inviare messaggi come l\'utente autenticato ('.$user->name.').',
                ],
                'notifications/initialized', 'notifications/cancelled' => null,
                'ping' => (object) [],
                'tools/list' => ['tools' => $this->tools()],
                'tools/call' => $this->callTool((string) ($params['name'] ?? ''), (array) ($params['arguments'] ?? []), $user),
                'resources/list' => ['resources' => Channel::visibleTo($user)->get()->map(fn ($c) => [
                    'uri' => 'acidtalk://channel/'.$c->id, 'name' => '#'.$c->displayNameFor($user), 'description' => $c->topic, 'mimeType' => 'text/plain',
                ])->values()],
                'resources/read' => $this->readResource((string) ($params['uri'] ?? ''), $user),
                'prompts/list' => ['prompts' => [[
                    'name' => 'summarize_channel',
                    'description' => 'Riassumi le ultime discussioni di un canale',
                    'arguments' => [['name' => 'channel', 'description' => 'nome o id del canale', 'required' => true]],
                ]]],
                'prompts/get' => ['messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Usa il tool read_channel sul canale "'.($params['arguments']['channel'] ?? '').'" e riassumi i punti chiave, le decisioni e le azioni aperte.']]]],
                default => throw new McpException("Metodo non supportato: {$method}", -32601),
            };
        } catch (McpException $e) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $e->getCode(), 'message' => $e->getMessage()]];
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'Canale o messaggio non trovato / non accessibile']];
        } catch (\Throwable $e) {
            report($e);

            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => 'Errore interno: '.$e->getMessage()]];
        }

        if ($result === null) {
            return null; // notifica: nessuna risposta
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    public function tools(): array
    {
        $obj = fn (array $props, array $required = []) => ['type' => 'object', 'properties' => $props ?: new \stdClass(), 'required' => $required];

        return [
            ['name' => 'list_channels', 'description' => 'Elenca i canali visibili (pubblici, privati di cui si è membri, messaggi diretti) con id, tipo e argomento.', 'inputSchema' => $obj([
                'include_archived' => ['type' => 'boolean', 'default' => false, 'description' => 'includi anche i canali archiviati (sola lettura)'],
            ])],
            ['name' => 'read_channel', 'description' => 'Legge gli ultimi messaggi di un canale (per id o nome), con autore, data, allegati e numero di risposte nel thread.', 'inputSchema' => $obj([
                'channel' => ['type' => 'string', 'description' => 'id numerico o nome del canale (es. "generale")'],
                'limit' => ['type' => 'integer', 'default' => 30, 'maximum' => 200],
                'before_id' => ['type' => 'integer', 'description' => 'paginazione: messaggi precedenti a questo id'],
            ], ['channel'])],
            ['name' => 'read_thread', 'description' => 'Legge un thread completo (messaggio radice e risposte).', 'inputSchema' => $obj(['message_id' => ['type' => 'integer']], ['message_id'])],
            ['name' => 'search_messages', 'description' => 'Ricerca testuale nei messaggi di tutti i canali accessibili.', 'inputSchema' => $obj([
                'query' => ['type' => 'string'], 'channel' => ['type' => 'string', 'description' => 'opzionale: limita a un canale'], 'limit' => ['type' => 'integer', 'default' => 20],
            ], ['query'])],
            ['name' => 'post_message', 'description' => 'Invia un messaggio (Markdown) in un canale, opzionalmente come risposta in un thread.', 'inputSchema' => $obj([
                'channel' => ['type' => 'string'], 'body' => ['type' => 'string'], 'parent_id' => ['type' => 'integer'],
            ], ['channel', 'body'])],
            ['name' => 'list_users', 'description' => 'Elenca gli utenti dell\'organizzazione (nome, email, ruolo, stato online).', 'inputSchema' => $obj([])],
        ];
    }

    private function callTool(string $name, array $args, User $user): array
    {
        $text = match ($name) {
            'list_channels' => $this->listChannels($user, (bool) ($args['include_archived'] ?? false)),
            'read_channel' => $this->readChannel($this->resolveChannel($args['channel'] ?? '', $user), (int) ($args['limit'] ?? 30), (int) ($args['before_id'] ?? 0)),
            'read_thread' => $this->readThread((int) ($args['message_id'] ?? 0), $user),
            'search_messages' => $this->search((string) ($args['query'] ?? ''), $args['channel'] ?? null, (int) ($args['limit'] ?? 20), $user),
            'post_message' => $this->post($this->resolveChannel($args['channel'] ?? '', $user), (string) ($args['body'] ?? ''), $args['parent_id'] ?? null, $user),
            'list_users' => User::where('organization_id', $user->organization_id)->orderBy('name')->get()
                ->map(fn ($u) => "- {$u->name} <{$u->email}> [{$u->role}]".($u->isOnline() ? ' • online' : ''))->implode("\n"),
            default => throw new McpException("Tool sconosciuto: {$name}", -32602),
        };

        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false];
    }

    private function resolveChannel(string|int $ref, User $user): Channel
    {
        $q = Channel::visibleTo($user, true);   // i canali archiviati restano leggibili
        $ref = is_string($ref) ? ltrim(trim($ref), '#') : $ref;
        $channel = is_numeric($ref) ? $q->find((int) $ref) : $q->where(fn ($w) => $w->where('name', $ref)->orWhere('slug', Str::slug((string) $ref)))->first();
        if (! $channel) {
            throw new McpException("Canale \"{$ref}\" non trovato o non accessibile", -32602);
        }

        return $channel;
    }

    private function listChannels(User $user, bool $includeArchived = false): string
    {
        return Channel::visibleTo($user, $includeArchived)->with('members')->withCount('members')->get()->map(fn (Channel $c) => sprintf(
            '- id=%d  #%s  [%s%s]  membri=%d%s', $c->id, $c->displayNameFor($user), $c->type, $c->isArchived() ? ', archiviato' : '', $c->members_count, $c->topic ? "  — {$c->topic}" : ''
        ))->implode("\n") ?: 'Nessun canale.';
    }

    private function readChannel(Channel $channel, int $limit, int $before): string
    {
        $q = $channel->messages()->whereNull('parent_id')->with(['user', 'attachments'])->orderByDesc('id')->limit(max(1, min($limit, 200)));
        if ($before) {
            $q->where('id', '<', $before);
        }
        $lines = $q->get()->reverse()->map(fn (Message $m) => $this->line($m));

        return "Canale #{$channel->name} (id {$channel->id})\n".($lines->implode("\n") ?: '(nessun messaggio)');
    }

    private function readThread(int $id, User $user): string
    {
        $root = Message::findOrFail($id);
        if (! $root->channel->canRead($user)) {
            throw new McpException('Thread non accessibile', -32602);
        }
        $root = $root->parent_id ? $root->parent : $root;
        $lines = collect([$this->line($root)])->merge($root->replies()->with('user')->orderBy('id')->get()->map(fn ($m) => '    ↳ '.$this->line($m)));

        return $lines->implode("\n");
    }

    private function search(string $query, ?string $channelRef, int $limit, User $user): string
    {
        if (mb_strlen(trim($query)) < 2) {
            throw new McpException('Query troppo corta', -32602);
        }
        $ids = $channelRef ? collect([$this->resolveChannel($channelRef, $user)->id]) : Channel::visibleTo($user, true)->pluck('id');
        $rows = Message::whereIn('channel_id', $ids)->where('body', 'like', '%'.$query.'%')->with(['user', 'channel'])->orderByDesc('id')->limit(max(1, min($limit, 100)))->get();

        return $rows->map(fn (Message $m) => '#'.$m->channel->displayNameFor($user).'  '.$this->line($m))->implode("\n") ?: 'Nessun risultato.';
    }

    private function post(Channel $channel, string $body, $parentId, User $user): string
    {
        if (trim($body) === '') {
            throw new McpException('Il corpo del messaggio è vuoto', -32602);
        }
        if ($channel->isArchived()) {
            throw new McpException("Il canale #{$channel->name} è archiviato (sola lettura)", -32602);
        }
        $message = $channel->messages()->create(['user_id' => $user->id, 'body' => $body, 'parent_id' => $parentId ? (int) $parentId : null, 'meta' => ['via' => 'mcp']]);
        if ($parentId) {
            Message::whereKey($parentId)->increment('reply_count', 1, ['last_reply_at' => now()]);
        }
        $channel->forceFill(['last_message_at' => now()])->save();
        \App\Support\Realtime::send(new MessageSent($message->load(['user', 'attachments', 'reactions', 'linkPreviews'])));
        app(\App\Services\NotificationDispatcher::class)->dispatch($message, $channel->load('members'));
        \App\Services\MessageTasks::schedule($message, $channel);

        return "Messaggio inviato in #{$channel->name} (id {$message->id}).";
    }

    private function readResource(string $uri, User $user): array
    {
        if (! preg_match('~^acidtalk://channel/(\d+)$~', $uri, $m)) {
            throw new McpException('URI non valido', -32602);
        }
        $channel = $this->resolveChannel((int) $m[1], $user);

        return ['contents' => [['uri' => $uri, 'mimeType' => 'text/plain', 'text' => $this->readChannel($channel, 50, 0)]]];
    }

    private function line(Message $m): string
    {
        $who = $m->is_ai ? '🤖 '.($m->meta['bot_name'] ?? 'AI') : ($m->user?->name ?? 'sistema');
        $att = $m->relationLoaded('attachments') && $m->attachments->isNotEmpty() ? ' [allegati: '.$m->attachments->pluck('name')->implode(', ').']' : '';
        $thr = $m->reply_count ? " ({$m->reply_count} risposte nel thread)" : '';

        return sprintf('[%s] (id %d) %s: %s%s%s', $m->created_at->format('Y-m-d H:i'), $m->id, $who, Str::limit(trim((string) $m->body), 600), $att, $thr);
    }
}

class McpException extends \RuntimeException {}
