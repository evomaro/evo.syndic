<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PortalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public array $payload, public array $channels = ['database'], public ?string $eventKey = null)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->payload['title'])->line($this->payload['message'])->action(__('Ouvrir EvoSyndic'), url($this->payload['url'] ?? '/dashboard'));
    }
}
