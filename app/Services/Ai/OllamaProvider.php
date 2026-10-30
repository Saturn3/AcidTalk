<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OllamaProvider implements AiProviderInterface
{
    public function __construct(private ?string $baseUrl = null, private ?string $model = null)
    {
        $this->baseUrl = rtrim($baseUrl ?: config('ai.providers.ollama.base_url'), '/');
        $this->model = $model ?: config('ai.providers.ollama.model');
    }

    public function name(): string
    {
        return 'ollama';
    }

    public function model(): string
    {
        return (string) $this->model;
    }

    public function chat(array $messages, ?string $systemPrompt = null): string
    {
        $payload = $systemPrompt ? [['role' => 'system', 'content' => $systemPrompt]] : [];
        $payload = array_merge($payload, $messages);

        try {
            $res = Http::timeout(180)->post($this->baseUrl.'/api/chat', ['model' => $this->model, 'messages' => $payload, 'stream' => false]);
            if ($res->status() === 404 && ($tags = Http::timeout(10)->get($this->baseUrl.'/api/tags')->json('models'))) {
                $this->model = $tags[0]['name'] ?? $this->model;
                $res = Http::timeout(180)->post($this->baseUrl.'/api/chat', ['model' => $this->model, 'messages' => $payload, 'stream' => false]);
            }
        } catch (ConnectionException $e) {
            throw new AiException("Ollama: server non raggiungibile su {$this->baseUrl}. Su un hosting condiviso Ollama non è disponibile: usa un server Ollama raggiungibile da internet oppure OpenAI/Anthropic.");
        }
        if (! $res->successful()) {
            throw AiException::fromResponse('Ollama', $res, $this->model);
        }

        return trim((string) $res->json('message.content')) ?: '(nessuna risposta dal modello)';
    }
}
