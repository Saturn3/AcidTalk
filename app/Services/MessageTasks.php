<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Jobs\FetchLinkPreview;
use App\Jobs\GenerateAiReply;
use App\Models\Channel;
use App\Models\Message;
use App\Services\Ai\AiException;
use App\Services\Ai\AiManager;
use App\Support\Realtime;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Attività lente collegate a un nuovo messaggio: risposta dell'assistente AI,
 * anteprime dei link e webhook dei bot.
 *
 * Ogni attività viene segnata in meta.pending e può essere eseguita da:
 *  1. il client (web, app) che chiama POST /api/messages/{id}/tasks/{task} subito dopo l'invio
 *     (header X-AcidTalk-Tasks: client): metodo più affidabile su hosting condiviso;
 *  2. la coda (QUEUE_WORKER=true) oppure subito dopo la risposta HTTP;
 *  3. il cron (acidtalk:pending) come rete di sicurezza per quelle rimaste in sospeso.
 * L'esecuzione è idempotente e protetta da lock: non si ottengono mai due risposte.
 */
class MessageTasks
{
    public const TASKS = ['ai', 'previews', 'bots'];

    private const WITH = ['user', 'attachments', 'reactions', 'linkPreviews'];

    /** Segna le attività necessarie e, se il client non se ne occupa, le avvia in background. */
    public static function schedule(Message $message, Channel $channel, bool $clientDriven = false): void
    {
        $pending = [];
        if ($message->urls()) {
            $pending[] = 'previews';
        }
        if (AiManager::shouldReply($message, $channel)) {
            $pending[] = 'ai';
        }
        if (app(BotService::class)->targets($message, $channel)->isNotEmpty()) {
            $pending[] = 'bots';
        }
        if (! $pending) {
            return;
        }
        $meta = $message->meta ?? [];
        $meta['pending'] = $pending;
        $message->forceFill(['meta' => $meta])->saveQuietly();

        if ($clientDriven) {
            return; // il client chiama /tasks/{task}; il cron recupera eventuali attività rimaste
        }
        foreach ($pending as $task) {
            match ($task) {
                'ai' => GenerateAiReply::dispatchFor($message),
                'previews' => FetchLinkPreview::dispatchFor($message),
                'bots' => \App\Jobs\RunBotWebhooks::dispatchFor($message),
            };
        }
    }

    /** Dopo una modifica del testo: rigenera solo le anteprime dei link. */
    public static function scheduleEdit(Message $message, bool $clientDriven = false): void
    {
        if (! $message->urls()) {
            return;
        }
        $meta = $message->meta ?? [];
        $meta['pending'] = array_values(array_unique(array_merge($meta['pending'] ?? [], ['previews'])));
        $message->forceFill(['meta' => $meta])->saveQuietly();
        if (! $clientDriven) {
            FetchLinkPreview::dispatchFor($message);
        }
    }

    public static function isPending(Message $message, string $task): bool
    {
        return in_array($task, $message->meta['pending'] ?? [], true);
    }

    private static function done(Message $message, string $task): void
    {
        $message->refresh();
        $meta = $message->meta ?? [];
        $meta['pending'] = array_values(array_diff($meta['pending'] ?? [], [$task]));
        if (! $meta['pending']) {
            unset($meta['pending']);
        }
        $message->forceFill(['meta' => $meta ?: null])->saveQuietly();
    }

    /** Esegue un'attività; restituisce i messaggi creati (risposte AI o dei bot). */
    public function run(Message $message, string $task): array
    {
        return match ($task) {
            'ai' => array_filter([$this->ai($message)]),
            'previews' => tap([], fn () => $this->previews($message)),
            'bots' => $this->bots($message),
            default => [],
        };
    }

    /** Risposta dell'assistente AI: restituisce quella già esistente se presente. */
    public function ai(Message $trigger): ?Message
    {
        $existing = fn () => Message::where('channel_id', $trigger->channel_id)->where('is_ai', true)
            ->where('id', '>', $trigger->id)->where('meta->in_reply_to', $trigger->id)->first();

        if ($found = $existing()) {
            return $found;
        }
        if (! self::isPending($trigger->fresh() ?? $trigger, 'ai')) {
            return null;
        }

        $lock = Cache::lock('ai-reply-'.$trigger->id, 240);
        if (! $lock->get()) {
            // un'altra richiesta la sta generando: attendiamo il risultato (max ~3 minuti)
            for ($i = 0; $i < 180; $i++) {
                sleep(1);
                if ($found = $existing()) {
                    return $found;
                }
            }

            return null;
        }

        try {
            if ($found = $existing()) {
                return $found;
            }
            @set_time_limit(240);
            @ignore_user_abort(true);
            try {
                $reply = app(AiManager::class)->reply($trigger);
            } catch (\Throwable $e) {
                Log::warning('Risposta AI non riuscita: '.$e->getMessage(), ['message_id' => $trigger->id]);
                Cache::put('acidtalk.last_ai_error', ['at' => now()->toIso8601String(), 'error' => mb_substr($e->getMessage(), 0, 500)], now()->addDays(7));
                $reason = $e instanceof AiException ? $e->getMessage() : class_basename($e).': '.mb_substr($e->getMessage(), 0, 200);
                $reply = Message::create([
                    'channel_id' => $trigger->channel_id,
                    'parent_id' => $trigger->parent_id,
                    'body' => "**Non riesco a rispondere in questo momento.**\n\n".$reason."\n\nUn amministratore può verificare la configurazione in Amministrazione › AI con il pulsante \"Prova connessione\".",
                    'kind' => 'ai', 'is_ai' => true, 'meta' => ['error' => true, 'in_reply_to' => $trigger->id],
                ]);
            }
            self::done($trigger, 'ai');
            Cache::put('acidtalk.last_ai_reply', now()->toIso8601String(), now()->addDays(30));
            $reply->load(self::WITH);
            Realtime::send(new MessageSent($reply));

            return $reply;
        } finally {
            $lock->release();
        }
    }

    /** Anteprime dei link (max 3 per messaggio). */
    public function previews(Message $message): bool
    {
        if (! self::isPending($message->fresh() ?? $message, 'previews')) {
            return false;
        }
        $lock = Cache::lock('previews-'.$message->id, 60);
        if (! $lock->get()) {
            return false;
        }
        try {
            $created = false;
            $fetcher = app(LinkPreviewFetcher::class);
            $existing = $message->linkPreviews()->pluck('url')->all();
            foreach (array_slice($message->urls(), 0, 3) as $url) {
                if (in_array($url, $existing, true)) {
                    continue;
                }
                $data = $fetcher->fetch($url);
                if (! $data || ($data['kind'] === 'link' && ! $data['title'] && ! $data['description'])) {
                    continue; // niente anteprima vuota per link senza metadati o non raggiungibili
                }
                $message->linkPreviews()->create($data);
                $created = true;
            }
            self::done($message, 'previews');
            $message->touch();
            if ($created) {
                Realtime::send(new MessageUpdated($message->fresh(self::WITH)));
            }

            return $created;
        } finally {
            $lock->release();
        }
    }

    /** Webhook dei bot menzionati o destinatari del messaggio diretto. */
    public function bots(Message $message): array
    {
        if (! self::isPending($message->fresh() ?? $message, 'bots')) {
            return [];
        }
        $lock = Cache::lock('bots-'.$message->id, 60);
        if (! $lock->get()) {
            return [];
        }
        try {
            self::done($message, 'bots');

            return app(BotService::class)->deliver($message);
        } finally {
            $lock->release();
        }
    }

    /** Attività rimaste in sospeso (client chiuso, after-response non eseguito): le esegue il cron. */
    public function sweep(int $olderThanSeconds = 30, int $limit = 20): int
    {
        $count = 0;
        $items = Message::whereNotNull('meta')->where('meta', 'like', '%"pending"%')
            ->where('created_at', '<=', now()->subSeconds($olderThanSeconds))
            ->where('created_at', '>=', now()->subDay())
            ->orderBy('id')->limit($limit)->get();
        foreach ($items as $m) {
            foreach ($m->meta['pending'] ?? [] as $task) {
                try {
                    $this->run($m, $task);
                    $count++;
                } catch (\Throwable $e) {
                    Log::warning("Attività {$task} del messaggio {$m->id} non riuscita: ".$e->getMessage());
                    self::done($m, $task);
                }
            }
        }

        return $count;
    }
}
