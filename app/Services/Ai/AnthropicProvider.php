<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AnthropicProvider implements AiProviderInterface
{
    private const API = 'https://api.anthropic.com/v1';

    private bool $explicitModel;

    public function __construct(private ?string $apiKey = null, private ?string $model = null)
    {
        $this->apiKey = trim((string) ($apiKey ?: config('ai.providers.anthropic.api_key')));
        $this->explicitModel = (bool) $model;
        // se il modello di default non esiste più, usiamo quello trovato automaticamente l'ultima volta
        $this->model = $model ?: (Cache::get('ai.anthropic.auto_model') ?: config('ai.providers.anthropic.model'));
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return (string) $this->model;
    }

    public function chat(array $messages, ?string $systemPrompt = null): string
    {
        if ($this->apiKey === '') {
            throw new AiException('Anthropic: chiave API mancante. Inseriscila in Amministrazione › AI oppure in ANTHROPIC_API_KEY nel file .env.');
        }

        // Anthropic richiede alternanza user/assistant: compatta i messaggi consecutivi dello stesso ruolo
        $merged = [];
        foreach ($messages as $m) {
            $role = $m['role'] === 'assistant' ? 'assistant' : 'user';
            $content = trim((string) $m['content']) !== '' ? $m['content'] : '(messaggio vuoto)';
            if ($merged && end($merged)['role'] === $role) {
                $merged[count($merged) - 1]['content'] .= "\n\n".$content;
            } else {
                $merged[] = ['role' => $role, 'content' => $content];
            }
        }
        if (! $merged || $merged[0]['role'] !== 'user') {
            array_unshift($merged, ['role' => 'user', 'content' => '(inizio conversazione)']);
        }

        $res = $this->send($merged, $systemPrompt);

        // modello inesistente o ritirato: scegliamo automaticamente un modello disponibile
        if ($res->status() === 404 && ($auto = $this->discoverModel()) && $auto !== $this->model) {
            $this->model = $auto;
            Cache::forever('ai.anthropic.auto_model', $auto);
            $res = $this->send($merged, $systemPrompt);
        }
        if (! $res->successful()) {
            throw AiException::fromResponse('Anthropic', $res, $this->model);
        }

        $text = collect($res->json('content') ?? [])->where('type', 'text')->pluck('text')->implode("\n");

        return trim($text) ?: '(nessuna risposta dal modello)';
    }

    private function send(array $messages, ?string $systemPrompt): Response
    {
        try {
            return $this->client()->timeout(120)->post(self::API.'/messages', array_filter([
                'model' => $this->model,
                'max_tokens' => 4096,
                'system' => $systemPrompt ?: null,
                'messages' => $messages,
            ]));
        } catch (ConnectionException $e) {
            throw new AiException('Anthropic: impossibile raggiungere api.anthropic.com ('.$e->getMessage().'). Verifica che l\'hosting consenta connessioni HTTPS in uscita.');
        }
    }

    /** Elenca i modelli disponibili per la chiave e preferisce l'ultimo Sonnet. */
    public function discoverModel(): ?string
    {
        try {
            $res = $this->client()->timeout(20)->get(self::API.'/models', ['limit' => 100]);
        } catch (ConnectionException) {
            return null;
        }
        if (! $res->successful()) {
            return null;
        }
        $ids = collect($res->json('data') ?? [])->pluck('id')->filter()->values(); // dal più recente

        return $ids->first(fn ($id) => str_contains($id, 'sonnet')) ?? $ids->first();
    }

    /** @return array<int,string> */
    public function availableModels(): array
    {
        $res = $this->client()->timeout(20)->get(self::API.'/models', ['limit' => 100]);
        if (! $res->successful()) {
            throw AiException::fromResponse('Anthropic', $res, $this->model);
        }

        return collect($res->json('data') ?? [])->pluck('id')->filter()->values()->all();
    }

    private function client()
    {
        return Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
        ])->acceptJson();
    }
}
