<?php

namespace App\Services\Ai;

use App\Models\Channel;
use App\Models\Message;
use App\Models\Organization;

class AiManager
{
    /** Provider attivo: impostazioni organizzazione (admin) > config/ai.php (.env). */
    public function driver(?Organization $org = null, array $override = []): AiProviderInterface
    {
        $provider = ($override['ai_provider'] ?? null) ?: ($org?->setting('ai_provider') ?: config('ai.default', 'ollama'));
        $model = self::modelFor($provider, ($override['ai_model'] ?? null) ?: $org?->setting('ai_model'));
        $secret = fn (string $k) => ($v = $override[$k] ?? null) && ! str_starts_with($v, '••') ? $v : $org?->setting($k);

        return match ($provider) {
            'openai' => new OpenAiProvider($secret('openai_api_key'), $model),
            'anthropic' => new AnthropicProvider($secret('anthropic_api_key'), $model),
            default => new OllamaProvider(($override['ollama_base_url'] ?? null) ?: $org?->setting('ollama_base_url'), $model),
        };
    }

    /**
     * Ignora un modello salvato che appartiene a un altro provider
     * (es. "llama3.1" rimasto impostato dopo il passaggio ad Anthropic).
     */
    public static function modelFor(string $provider, ?string $model): ?string
    {
        $model = trim((string) $model);
        if ($model === '') {
            return null;
        }
        $isClaude = str_starts_with($model, 'claude');
        $isOpenAi = (bool) preg_match('/^(gpt-|chatgpt|o\d|ft:)/', $model);

        return match ($provider) {
            'anthropic' => $isClaude ? $model : null,
            'openai' => $isOpenAi ? $model : null,
            default => ($isClaude || $isOpenAi) ? null : $model,
        };
    }

    public function systemPrompt(Organization $org): string
    {
        $base = $org->setting('ai_system_prompt') ?: config('ai.system_prompt');

        return $base."\nOrganizzazione: {$org->name}. Rispondi nella lingua dell'utente (di default italiano).";
    }

    /**
     * Genera la risposta del bot nel canale (o nel thread) del messaggio che l'ha attivato.
     */
    public function reply(Message $trigger, int $historyLimit = 24): Message
    {
        $channel = $trigger->channel;
        $org = $channel->organization;
        $botName = $org->setting('ai_bot_name', 'AI');

        $scope = Message::where('channel_id', $channel->id)->where('id', '<=', $trigger->id);
        // nei thread usiamo come contesto il messaggio radice + le risposte
        $scope = $trigger->parent_id
            ? $scope->where(fn ($q) => $q->where('id', $trigger->parent_id)->orWhere('parent_id', $trigger->parent_id))
            : $scope->whereNull('parent_id');

        $history = $scope->latest('id')->limit($historyLimit)->with('user:id,name')->get()->reverse()
            ->map(fn (Message $m) => [
                'role' => $m->is_ai ? 'assistant' : 'user',
                'content' => $m->is_ai ? ($m->body ?? '') : (($m->user?->name ?? 'Utente').': '.$this->stripMention($m->body ?? '')),
            ])->values()->all();

        $driver = $this->driver($org);
        $text = $driver->chat($history, $this->systemPrompt($org));

        $reply = Message::create([
            'channel_id' => $channel->id,
            'user_id' => null,
            'parent_id' => $trigger->parent_id,
            'body' => $text,
            'kind' => 'ai',
            'is_ai' => true,
            'meta' => ['provider' => $driver->name(), 'model' => $driver->model(), 'bot_name' => $botName, 'in_reply_to' => $trigger->id],
        ]);

        if ($trigger->parent_id) {
            Message::whereKey($trigger->parent_id)->increment('reply_count', 1, ['last_reply_at' => now()]);
        }
        $channel->forceFill(['last_message_at' => now()])->save();

        return $reply;
    }

    public static function shouldReply(Message $m, Channel $channel): bool
    {
        if ($m->is_ai || ! $channel->ai_enabled) {
            return false;
        }

        return $channel->type === 'ai_dm' || $m->mentionsAi();
    }

    private function stripMention(string $body): string
    {
        return trim(preg_replace('/(^|\s)@ai\b/iu', '$1', $body));
    }
}
