<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Support\Realtime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Utenti bot: scrivono con il proprio token API e, se hanno un webhook, ricevono i messaggi
 * in cui sono menzionati (@nomebot) o i messaggi diretti. La risposta JSON {"text": "..."}
 * del webhook viene pubblicata come risposta del bot.
 */
class BotService
{
    /** Bot da avvisare per questo messaggio. */
    public function targets(Message $message, Channel $channel): Collection
    {
        $author = $message->user;
        if (! $author || $author->is_bot || $message->is_ai) {
            return collect(); // niente catene bot → bot
        }
        $bots = User::where('organization_id', $channel->organization_id)->where('is_bot', true)
            ->whereNotNull('bot_webhook_url')->where('bot_webhook_url', '!=', '')->get();
        if ($bots->isEmpty()) {
            return collect();
        }
        $memberIds = $channel->members()->pluck('users.id');
        $mentions = $message->mentions();

        return $bots->filter(function (User $bot) use ($channel, $memberIds, $mentions) {
            $member = $memberIds->contains($bot->id);
            if ($channel->type === 'dm') {
                return $member;
            }
            $mentioned = in_array($bot->handle(), $mentions, true) || in_array(strtolower(explode(' ', $bot->name)[0]), $mentions, true);

            return $mentioned && ($member || $channel->type === 'public');
        })->values();
    }

    public static function sign(string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    /** @return array<int,Message> risposte pubblicate dai bot */
    public function deliver(Message $message): array
    {
        $channel = $message->channel;
        $message->loadMissing(['user', 'attachments', 'reactions', 'linkPreviews']);
        $replies = [];
        foreach ($this->targets($message, $channel) as $bot) {
            $payload = [
                'event' => 'message',
                'bot' => ['id' => $bot->id, 'name' => $bot->name, 'handle' => $bot->handle()],
                'channel' => ['id' => $channel->id, 'name' => $channel->name, 'type' => $channel->type],
                'user' => ['id' => $message->user?->id, 'name' => $message->user?->name, 'email' => $message->user?->email],
                'message' => [
                    'id' => $message->id, 'parent_id' => $message->parent_id, 'body' => $message->body,
                    'kind' => $message->kind, 'created_at' => $message->created_at?->toIso8601String(),
                    'attachments' => $message->attachments->map(fn ($a) => ['id' => $a->id, 'name' => $a->name ?? null, 'mime' => $a->mime ?? null])->values(),
                ],
                'reply_url' => url('/api/channels/'.$channel->id.'/messages'),
                'sent_at' => now()->toIso8601String(),
            ];
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $status = ['at' => now()->toIso8601String(), 'message_id' => $message->id];
            try {
                $res = Http::withHeaders([
                    'X-AcidTalk-Event' => 'message',
                    'X-AcidTalk-Signature' => self::sign($body, (string) $bot->bot_webhook_secret),
                    'User-Agent' => 'AcidTalk-Bot/'.\App\Support\ClientConfig::VERSION,
                ])->withBody($body, 'application/json')->timeout(15)->post($bot->bot_webhook_url);
                $status['status'] = $res->status();
                if ($res->successful() && is_string($text = $res->json('text')) && trim($text) !== '') {
                    $replies[] = $this->postAs($bot, $channel, $text, $res->json('thread') === false ? null : $message->parent_id);
                }
            } catch (\Throwable $e) {
                $status['error'] = mb_substr($e->getMessage(), 0, 300);
                Log::warning("Webhook del bot {$bot->name} non riuscito: ".$e->getMessage());
            }
            Cache::put('bot.'.$bot->id.'.last_delivery', $status, now()->addDays(30));
        }

        return $replies;
    }

    /** Pubblica un messaggio come bot (usato dalle risposte ai webhook). */
    public function postAs(User $bot, Channel $channel, string $text, ?int $parentId = null): Message
    {
        $channel->members()->syncWithoutDetaching([$bot->id => []]);
        $reply = $channel->messages()->create(['user_id' => $bot->id, 'parent_id' => $parentId, 'body' => mb_substr($text, 0, 20000), 'kind' => 'text', 'meta' => ['via' => 'bot']]);
        if ($parentId) {
            Message::whereKey($parentId)->increment('reply_count', 1, ['last_reply_at' => now()]);
        }
        $channel->forceFill(['last_message_at' => now()])->save();
        $reply->load(['user', 'attachments', 'reactions', 'linkPreviews']);
        Realtime::send(new MessageSent($reply));
        app(NotificationDispatcher::class)->dispatch($reply, $channel->load('members'));
        MessageTasks::schedule($reply, $channel);

        return $reply;
    }
}
