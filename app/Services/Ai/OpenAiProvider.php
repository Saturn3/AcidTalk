<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class OpenAiProvider implements AiProviderInterface
{
    private const API = 'https://api.openai.com/v1';

    public function __construct(private ?string $apiKey = null, private ?string $model = null)
    {
        $this->apiKey = trim((string) ($apiKey ?: config('ai.providers.openai.api_key')));
        $this->model = $model ?: (Cache::get('ai.openai.auto_model') ?: config('ai.providers.openai.model'));
    }

    public function name(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return (string) $this->model;
    }

    public function chat(array $messages, ?string $systemPrompt = null): string
    {
        if ($this->apiKey === '') {
            throw new AiException('OpenAI: chiave API mancante. Inseriscila in Amministrazione › AI oppure in OPENAI_API_KEY nel file .env.');
        }
        $payload = $systemPrompt ? [['role' => 'system', 'content' => $systemPrompt]] : [];
        $payload = array_merge($payload, $messages);

        $res = $this->send($payload);
        if ($res->status() === 404 && ($auto = $this->discoverModel()) && $auto !== $this->model) {
            $this->model = $auto;
            Cache::forever('ai.openai.auto_model', $auto);
            $res = $this->send($payload);
        }
        if (! $res->successful()) {
            throw AiException::fromResponse('OpenAI', $res, $this->model);
        }

        return trim((string) $res->json('choices.0.message.content')) ?: '(nessuna risposta dal modello)';
    }

    private function send(array $payload): Response
    {
        try {
            return Http::withToken($this->apiKey)->acceptJson()->timeout(120)
                ->post(self::API.'/chat/completions', ['model' => $this->model, 'messages' => $payload]);
        } catch (ConnectionException $e) {
            throw new AiException('OpenAI: impossibile raggiungere api.openai.com ('.$e->getMessage().').');
        }
    }

    public function discoverModel(): ?string
    {
        try {
            $res = Http::withToken($this->apiKey)->acceptJson()->timeout(20)->get(self::API.'/models');
        } catch (ConnectionException) {
            return null;
        }
        $models = collect($res->json('data') ?? [])->sortByDesc('created');
        $chat = $models->filter(fn ($m) => preg_match('/^gpt-[\d.o]+(-mini)?$/', $m['id'] ?? ''));

        return data_get($chat->first(fn ($m) => str_ends_with($m['id'], '-mini')), 'id') ?? data_get($chat->first(), 'id');
    }
}
