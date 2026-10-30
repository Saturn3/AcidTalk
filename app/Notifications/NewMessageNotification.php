<?php

namespace App\Notifications;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class NewMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Message $message, public string $reason = 'message') {}

    public function via(object $notifiable): array
    {
        $via = ['database', 'broadcast'];
        if (($notifiable->prefs()['email'] ?? false) && in_array($this->reason, ['mention', 'dm'])) {
            $via[] = 'mail';
        }

        return $via;
    }

    /**
     * Notifica in app e realtime subito (sync), solo l'email passa dalla coda:
     * così le notifiche funzionano anche senza cron/worker attivi.
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync', 'broadcast' => 'sync'];
    }

    public function payload(object $notifiable): array
    {
        $channel = $this->message->channel;

        return [
            'message_id' => $this->message->id,
            'channel_id' => $channel->id,
            'parent_id' => $this->message->parent_id,
            'channel_name' => $channel->displayNameFor($notifiable),
            'channel_type' => $channel->type,
            'from' => $this->message->is_ai ? ($this->message->meta['bot_name'] ?? 'AI') : ($this->message->user?->name ?? 'Sistema'),
            // testo già interpretato (emoji :shortcode:, niente markdown grezzo)
            'excerpt' => Str::limit(html_entity_decode(trim(strip_tags($this->message->html())), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: ($this->message->kind === 'voice' ? 'Messaggio vocale' : '[allegato]'), 140),
            'reason' => $this->reason, // mention | dm | thread | message
        ];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->payload($notifiable);
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload($notifiable));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $p = $this->payload($notifiable);
        $label = $this->reason === 'dm' ? 'Nuovo messaggio diretto' : 'Sei stato menzionato';

        return (new MailMessage)
            ->subject("[AcidTalk] {$label} da {$p['from']}")
            ->greeting("Ciao {$notifiable->name},")
            ->line("{$p['from']} in #{$p['channel_name']}:")
            ->line('"'.$p['excerpt'].'"')
            ->action('Apri la conversazione', url('/app/c/'.$p['channel_id']));
    }
}
