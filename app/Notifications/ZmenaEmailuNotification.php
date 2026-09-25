<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Zpráva na adresu, ze které účet právě odešel.
 *
 * E-mail je klíč k účtu — na něj chodí odkaz na nové heslo. Kdo adresu
 * přepsal na svou, mohl si pak heslo obnovit sám; majitel se to bez téhle
 * zprávy nedozvěděl. Posílá se na starou adresu, ne na novou.
 */
class ZmenaEmailuNotification extends Notification
{
    public function __construct(
        public readonly string $jmeno,
        public readonly string $novy,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('E-mail účtu v galerii se změnil')
            ->greeting('Ahoj '.$this->jmeno.',')
            ->line('přihlašovací e-mail vašeho účtu v galerii se právě změnil na '.$this->novy.'.')
            ->line('Pokud jste to byli vy, nemusíte nic dělat — tahle adresa už k účtu nepatří.')
            ->line('Pokud ne, ozvěte se správci galerie co nejdřív: kdo adresu změnil, zná vaše heslo a může si nastavit nové.')
            ->salutation('Galerie');
    }
}
