<?php

use App\Models\CalendarEvent;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            CalendarEvent::query()
                ->where('starts_at', '>=', '2026-08-01 00:00:00')
                ->where('starts_at', '<', '2026-09-01 00:00:00')
                ->orderBy('id')
                ->eachById(function (CalendarEvent $event): void {
                    $event->starts_at = $event->starts_at->copy()->subMonthNoOverflow();
                    $event->ends_at = $event->ends_at?->copy()->subMonthNoOverflow();
                    $event->save();

                    DB::table('event_reminders')
                        ->where('event_id', $event->id)
                        ->where('status', 'pending')
                        ->whereNull('snoozed_until')
                        ->orderBy('id')
                        ->get()
                        ->each(function (object $reminder) use ($event): void {
                            $timezone = $event->timezone ?: 'Europe/Prague';
                            $remindAt = Carbon::parse($reminder->remind_at, $timezone)->subMonthNoOverflow();
                            $originalRemindAt = $reminder->original_remind_at
                                ? Carbon::parse($reminder->original_remind_at, $timezone)->subMonthNoOverflow()
                                : null;

                            DB::table('event_reminders')->where('id', $reminder->id)->update([
                                'remind_at' => $remindAt,
                                'original_remind_at' => $originalRemindAt,
                                'updated_at' => now(),
                            ]);
                        });
                });
        });
    }

    public function down(): void
    {
    }
};
