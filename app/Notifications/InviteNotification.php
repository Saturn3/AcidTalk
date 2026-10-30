<?php

namespace App\Notifications;

use App\Models\Invite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Invite $invite, public ?string $personalMessage = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $org = $this->invite->organization ?? \App\Models\Organization::current();
        $mail = (new MailMessage)
            ->subject("Sei stato invitato su AcidTalk — {$org->name}")
            ->greeting('Ciao,')
            ->line(($this->invite->inviter?->name ?? 'Un amministratore')." ti ha invitato a unirti allo spazio di lavoro di {$org->name} su AcidTalk come ".($this->invite->role === 'guest' ? 'ospite' : 'membro').'.');
        if ($this->personalMessage) {
            $mail->line('"'.$this->personalMessage.'"');
        }

        return $mail->action('Accetta l\'invito', $this->invite->url())
            ->line('Il link scade il '.$this->invite->expires_at?->format('d/m/Y').'.');
    }
}
