<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Odkaz na nové heslo — česky.
 *
 * Laravel posílal svůj anglický e-mail („Reset Password Notification")
 * a aplikace nemá překlady, takže by ho tak dostal každý, kdo heslo zapomene.
 */
class ObnovaHeslaNotification extends Notification
{
    public function __construct(public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $odkaz = url(route('password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()], false));
        $platnost = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject('Nové heslo do galerie')
            ->greeting('Ahoj '.$notifiable->name.',')
            ->line('někdo (nejspíš vy) požádal o nové heslo k vašemu účtu v galerii.')
            ->action('Nastavit nové heslo', $odkaz)
            ->line('Odkaz platí '.$platnost.' minut. Po změně hesla se odhlásí všechna zařízení, na kterých jste přihlášení.')
            ->line('Pokud jste o nové heslo nežádali, e-mail můžete ignorovat — dosavadní heslo platí dál.')
            ->salutation('Galerie');
    }
}
