<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationNotification extends Notification
{
    public function __construct(public readonly string $inviteUrl, public readonly string $inviterName) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Pozvánka do Stanektech Gallery')
            ->greeting("Ahoj {$notifiable->name},")
            ->line("{$this->inviterName} vás pozval(a) do soukromé galerie.")
            ->line('Dokončete aktivaci účtu bezpečným nastavením hesla.')
            ->action('Přijmout pozvánku', $this->inviteUrl)
            ->line('Pokud pozvánku neočekáváte, tento e-mail můžete ignorovat.');
    }
}
