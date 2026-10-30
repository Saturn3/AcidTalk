<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use App\Services\Push\FcmSender;
use Illuminate\Support\Str;

/**
 * Decide chi notificare per ogni nuovo messaggio, rispettando preferenze utente e del canale.
 */
class NotificationDispatcher
{
    public function dispatch(Message $message, Channel $channel): void
    {
        $authorId = $message->user_id;
        $mentions = $message->mentions();
        $recipients = $channel->type === 'public'
            ? User::where('organization_id', $channel->organization_id)->where('role', '!=', 'guest')
                ->where(fn ($q) => $q->whereHas('channels', fn ($c) => $c->where('channels.id', $channel->id))->orWhereIn(DB::raw('lower(name)'), $mentions))->get()
            : $channel->members;

        $threadParticipants = $message->parent_id
            ? Message::where('parent_id', $message->parent_id)->orWhere('id', $message->parent_id)->pluck('user_id')->filter()->unique()
            : collect();

        $push = [];
        foreach ($recipients as $user) {
            if ($user->id === $authorId) {
                continue;
            }
            $prefs = $user->prefs();
            $pivot = $channel->members->firstWhere('id', $user->id)?->pivot;
            $level = $pivot?->notify ?? 'all';
            $isMention = in_array(strtolower($user->name), $mentions) || in_array(strtolower(explode(' ', $user->name)[0]), $mentions);

            $reason = match (true) {
                $isMention => 'mention',
                $channel->isDirect() => 'dm',
                $threadParticipants->contains($user->id) => 'thread',
                default => 'message',
            };

            $send = match ($reason) {
                'mention' => $prefs['mentions'] && $level !== 'none',
                'dm' => $prefs['dms'],
                'thread' => $level !== 'none',
                default => $level === 'all' && ($prefs['channels'] ?? 'all') === 'all',
            };

            if ($send && ! $user->is_bot) {
                try {
                    Notification::send($user, new NewMessageNotification($message, $reason));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Notifica non inviata: '.$e->getMessage(), ['user_id' => $user->id]);
                }
                if ($prefs['push'] ?? true) {
                    $push[$reason][] = $user->id;
                }
            }
        }

        $this->push($message, $channel, $push);
    }

    /** Notifiche push alle app (Firebase): una richiesta per dispositivo, in parallelo. */
    private function push(Message $message, Channel $channel, array $byReason): void
    {
        $fcm = app(FcmSender::class);
        if (! $byReason || ! $fcm->configured()) {
            return;
        }
        $from = $message->is_ai ? ($message->meta['bot_name'] ?? 'AI') : ($message->user?->name ?? 'AcidTalk');
        $excerpt = Str::limit(html_entity_decode(trim(strip_tags($message->html())), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: ($message->kind === 'voice' ? 'Messaggio vocale' : 'Allegato'), 160);
        foreach ($byReason as $reason => $ids) {
            $title = $channel->isDirect() ? $from : $from.' in #'.$channel->name;
            if ($reason === 'mention') {
                $title = $from.' ti ha menzionato'.($channel->isDirect() ? '' : ' in #'.$channel->name);
            }
            try {
                $fcm->sendToUsers($ids, $title, $excerpt, [
                    'channel_id' => $channel->id, 'message_id' => $message->id,
                    'parent_id' => $message->parent_id, 'reason' => $reason, 'type' => 'message',
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Push FCM non riuscito: '.$e->getMessage());
                \Illuminate\Support\Facades\Cache::put('acidtalk.last_push_error', ['at' => now()->toIso8601String(), 'error' => $e->getMessage()], now()->addDays(7));
            }
        }
    }
}
