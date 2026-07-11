<?php

namespace App\Console\Commands;

use App\Models\EventReminder;
use App\Models\User;
use App\Notifications\EventReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
                // Web Push needs a VAPID sender configured by the deployment. Until then the
                // PWA receives the same secure in-app reminder and can show a local notification.
                $recipient->notify(new EventReminderNotification($reminder->event, $reminder->channel));
                $reminder->update(['status' => 'delivered', 'delivered_at' => now(), 'last_error' => null]);
                DB::table('reminder_delivery_logs')->insert(['event_reminder_id' => $reminder->id, 'channel' => $reminder->channel, 'status' => 'delivered', 'created_at' => now()]);
            } catch (\Throwable $exception) {
                report($exception);
                $reminder->update(['status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 2000)]);
                DB::table('reminder_delivery_logs')->insert(['event_reminder_id' => $reminder->id, 'channel' => $reminder->channel, 'status' => 'failed', 'detail' => mb_substr($exception->getMessage(), 0, 2000), 'created_at' => now()]);
            }
        }

        $capsules = DB::table('time_capsules')->where('status', 'sealed')->where('deliver_at', '<=', now())->limit((int) $this->option('limit'))->get();
        foreach ($capsules as $capsule) {
            if (!DB::table('time_capsules')->where('id', $capsule->id)->where('status', 'sealed')->update(['status' => 'delivered', 'delivered_at' => now(), 'updated_at' => now()])) continue;
            $recipient = User::find($capsule->recipient_user_id ?: $capsule->created_by);
            if ($recipient) $recipient->notify(new \App\Notifications\GalleryNotification('memory.capsule', "Časová kapsle je připravená: {$capsule->title}", '/memories', '💌', ['capsule_uuid' => $capsule->uuid]));
        }

        $this->info("Zpracováno připomínek: {$reminders->count()}, kapslí: {$capsules->count()}.");
        return self::SUCCESS;
    }
}
