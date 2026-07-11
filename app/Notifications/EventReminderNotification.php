<?php

namespace App\Notifications;

use App\Models\CalendarEvent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventReminderNotification extends Notification
{
    public function __construct(public readonly CalendarEvent $event, public readonly string $channel) {}

    public function via(object $notifiable): array
    {
        return $this->channel === 'email' ? ['database', 'mail'] : ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'calendar.reminder',
            'message' => "Připomínka: {$this->event->title}",
            'link' => '/calendar/events/' . $this->event->uuid,
            'icon' => '⏰',
            'extra' => ['event_uuid' => $this->event->uuid, 'starts_at' => $this->event->starts_at?->toIso8601String(), 'channel' => $this->channel],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Připomínka: {$this->event->title}")
            ->greeting("Ahoj {$notifiable->name},")
            ->line('Blíží se společná akce ' . $this->event->title . '.')
            ->line('Začátek: ' . $this->event->starts_at?->locale('cs')->translatedFormat('j. F Y, H:i'))
            ->action('Otevřít akci', url('/calendar/events/' . $this->event->uuid));
    }
}
