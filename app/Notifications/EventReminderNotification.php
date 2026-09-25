<?php

namespace App\Notifications;

use App\Models\CalendarEvent;
use App\Support\Cas;
use App\Support\Tabulky;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class EventReminderNotification extends Notification
{
    public function __construct(
        public readonly CalendarEvent $event,
        public readonly string $channel,
        public readonly ?int $reminderId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->channel === 'email' ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'calendar.reminder',
            'message' => "Připomínka: {$this->event->title}",
            'link' => '/calendar/events/'.$this->event->uuid,
            'icon' => '⏰',
            'category' => 'planning',
            'priority' => 'high',
            'context_key' => 'event:'.$this->event->uuid,
            'extra' => [
                'event_uuid' => $this->event->uuid,
                'starts_at' => $this->event->starts_at?->toIso8601String(),
                'channel' => $this->channel,
                'reminder_id' => $this->reminderId,
                'actionable' => $this->reminderId !== null,
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Připomínka: {$this->event->title}")
            ->greeting("Ahoj {$notifiable->name},")
            ->line('Blíží se společná akce '.$this->event->title.'.')
            ->line('Začátek: '.self::zacatek($this->event)?->locale('cs')->translatedFormat('j. F Y, H:i'))
            ->action('Otevřít akci', url('/calendar/events/'.$this->event->uuid));
    }

    /**
     * Začátek akce tak, jak ho vidí hodiny dvojice — pro e-mail i push.
     *
     * `starts_at` se ukládá tak, jak ho člověk zadal (prototyp, kalendář i import
     * rezervací posílají místní čas bez pásma; viz `App\Support\Cas::zHodin`), takže
     * se nepřevádí — `->timezone('Europe/Prague')` by akci v 18:00 ohlásil na 20:00.
     *
     * Výjimkou jsou akce z promítání Cinema City: ty migrace
     * `2026_07_16_110000_normalize_cinema_showing_times_and_booking_links` a
     * `CinemaCityProgramService::startsAt()` ukládají jako okamžik v UTC, a ty se
     * na pražské hodiny převést musí. Poznají se podle návrhu termínu, který
     * nese promítání.
     */
    public static function zacatek(CalendarEvent $event): ?CarbonImmutable
    {
        if (! $event->starts_at) {
            return null;
        }

        return self::zPromitani($event) ? Cas::mistni($event->starts_at) : Cas::zHodin($event->starts_at);
    }

    private static function zPromitani(CalendarEvent $event): bool
    {
        return Tabulky::je('viewing_date_proposals')
            && DB::table('viewing_date_proposals')
                ->where('calendar_event_id', $event->id)
                ->whereNotNull('cinema_showing_id')
                ->exists();
    }
}
