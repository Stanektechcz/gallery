<?php

namespace App\Console\Commands;

use App\Models\EventReminder;
use App\Models\User;
use App\Notifications\EventReminderNotification;
use App\Notifications\GalleryNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeliverPlanningRemindersCommand extends Command
{
    protected $signature = 'gallery:deliver-reminders {--limit=100 : Maximum number of due reminders}';
    protected $description = 'Deliver due calendar reminders and release due time capsules.';

    public function handle(): int
    {
        $reminders = EventReminder::with('event')->where('status', 'pending')->where('remind_at', '<=', now())
            ->orderBy('remind_at')->limit((int) $this->option('limit'))->get();

        foreach ($reminders as $reminder) {
            $claimed = EventReminder::whereKey($reminder->id)->where('status', 'pending')->update(['status' => 'processing', 'updated_at' => now()]);
            if (!$claimed) continue;
            try {
                $recipient = $reminder->user ?: $reminder->event?->creator;
                if (!$recipient || !$reminder->event) throw new \RuntimeException('Chybí příjemce nebo akce.');
                $recipient->notify(new EventReminderNotification($reminder->event, $reminder->channel, $reminder->id));

                // Push is the convenience on top: the in-app notification above is the
                // reliable channel, so a push failure must never fail the reminder.
                app(\App\Services\Notifications\WebPushService::class)->sendToUser($recipient, [
                    'title' => $reminder->event->title ?: 'Připomínka',
                    'body'  => $this->pushBody($reminder->event),
                    'url'   => '/calendar/events/' . $reminder->event->uuid,
                    'tag'   => 'event-' . $reminder->event->uuid,
                ]);
                $reminder->update(['status' => 'delivered', 'delivered_at' => now(), 'last_error' => null]);
                DB::table('reminder_delivery_logs')->insert(['event_reminder_id' => $reminder->id, 'channel' => $reminder->channel, 'status' => 'delivered', 'created_at' => now()]);
            } catch (\Throwable $exception) {
                report($exception);
                $reminder->update(['status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 2000)]);
                DB::table('reminder_delivery_logs')->insert(['event_reminder_id' => $reminder->id, 'channel' => $reminder->channel, 'status' => 'failed', 'detail' => mb_substr($exception->getMessage(), 0, 2000), 'created_at' => now()]);
            }
        }

        $todoReminders = collect();
        if (Schema::hasTable('shared_todos')) {
            $todoReminders = DB::table('shared_todos')->whereNotIn('status', ['completed', 'cancelled'])
                ->whereNull('calendar_event_id')->whereNotNull('remind_at')->where('remind_at', '<=', now())
                ->where(fn ($query) => $query->whereNull('last_reminded_at')->orWhereColumn('last_reminded_at', '<', 'remind_at'))
                ->orderBy('remind_at')->limit((int) $this->option('limit'))->get();
            foreach ($todoReminders as $todo) {
                $claimed = DB::table('shared_todos')->where('id', $todo->id)
                    ->where(fn ($query) => $query->whereNull('last_reminded_at')->orWhereColumn('last_reminded_at', '<', 'remind_at'))
                    ->update(['last_reminded_at' => now(), 'updated_at' => now()]);
                if (! $claimed) continue;
                $recipient = User::find($todo->assigned_to ?: $todo->created_by);
                $recipient?->notify(new GalleryNotification('todo.reminder', 'Připomínka úkolu: ' . $todo->title, '/planning#todos', '✅', ['todo_uuid' => $todo->uuid]));
            }
        }

        $capsules = DB::table('time_capsules')->where('status', 'sealed')->where('deliver_at', '<=', now())->limit((int) $this->option('limit'))->get();
        foreach ($capsules as $capsule) {
            if (!DB::table('time_capsules')->where('id', $capsule->id)->where('status', 'sealed')->update(['status' => 'delivered', 'delivered_at' => now(), 'updated_at' => now()])) continue;
            $recipient = User::find($capsule->recipient_user_id ?: $capsule->created_by);
            $eventUuid = $capsule->event_id ? DB::table('calendar_events')->where('id', $capsule->event_id)->value('uuid') : null;
            $url = $eventUuid ? "/calendar/events/{$eventUuid}?capsule={$capsule->uuid}" : '/memories';
            if ($recipient) $recipient->notify(new \App\Notifications\GalleryNotification('memory.capsule', "Časová kapsle je připravená: {$capsule->title}", $url, '💌', ['capsule_uuid' => $capsule->uuid]));
        }

        $this->info("Zpracováno připomínek: {$reminders->count()}, úkolů: {$todoReminders->count()}, kapslí: {$capsules->count()}.");
        return self::SUCCESS;
    }

    /**
     * Short line for the notification. Only the time and place — a push shows on a lock
     * screen, so it deliberately carries no private detail beyond what is already in the
     * event's own title.
     */
    private function pushBody(mixed $event): string
    {
        $parts = [];

        if ($event->starts_at) {
            $parts[] = $event->starts_at->timezone('Europe/Prague')->format('j. n. H:i');
        }
        if (! empty($event->place_name)) {
            $parts[] = $event->place_name;
        }

        return $parts === [] ? 'Blíží se naplánovaná akce.' : implode(' · ', $parts);
    }
}
