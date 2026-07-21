<?php

namespace App\Notifications;

use App\Models\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(public TeamInvitation $invitation, public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ar = $this->locale === 'ar';
        $url = route('invitations.show', ['token' => $this->token, 'locale' => $this->locale]);

        return (new MailMessage)
            ->subject($ar ? 'دعوة للانضمام إلى إيفو سانديك' : 'Invitation à rejoindre EvoSyndic')
            ->greeting($ar ? 'مرحباً،' : 'Bonjour,')
            ->line($ar ? "تمت دعوتك للانضمام إلى {$this->invitation->organization->name}." : "Vous êtes invité(e) à rejoindre {$this->invitation->organization->name}.")
            ->action($ar ? 'قبول الدعوة' : 'Accepter l’invitation', $url)
            ->line($ar ? 'تنتهي صلاحية هذا الرابط خلال 7 أيام.' : 'Ce lien expire dans 7 jours.');
    }
}
