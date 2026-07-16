<?php

use App\Services\Entertainment\CinemaCityProgramService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cinema_showings')) {
            return;
        }

        DB::table('cinema_showings')
            ->where('provider', 'cinema_city')
            ->orderBy('id')
            ->chunkById(100, function ($showings): void {
                foreach ($showings as $showing) {
                    $originalStart = $this->localDateTime((string) $showing->starts_at);
                    if (! $originalStart) {
                        continue;
                    }

                    $utcStart = $originalStart->utc();
                    DB::transaction(function () use ($showing, $originalStart, $utcStart): void {
                        DB::table('cinema_showings')->where('id', $showing->id)->update([
                            'starts_at' => $utcStart->format('Y-m-d H:i:s'),
                            'booking_url' => $showing->sold_out
                                ? null
                                : CinemaCityProgramService::programUrl($utcStart, $showing->external_film_id),
                            'updated_at' => now(),
                        ]);

                        if (! Schema::hasTable('viewing_date_proposals')) {
                            return;
                        }

                        $proposals = DB::table('viewing_date_proposals')
                            ->where('cinema_showing_id', $showing->id)
                            ->get(['id', 'calendar_event_id']);

                        foreach ($proposals as $proposal) {
                            DB::table('viewing_date_proposals')->where('id', $proposal->id)->update([
                                'starts_at' => $utcStart->format('Y-m-d H:i:s'),
                                'updated_at' => now(),
                            ]);

                            if ($proposal->calendar_event_id) {
                                $this->normalizeCalendarEvent((int) $proposal->calendar_event_id, $originalStart, $utcStart);
                            }
                        }
                    });
                }
            });
    }

    public function down(): void
    {
        // This migration repairs previously misinterpreted instants. Reversing
        // it would deliberately restore incorrect cinema and calendar times.
    }

    private function normalizeCalendarEvent(int $eventId, CarbonImmutable $originalStart, CarbonImmutable $utcStart): void
    {
        if (! Schema::hasTable('calendar_events')) {
            return;
        }

        $event = DB::table('calendar_events')->where('id', $eventId)->first(['id', 'ends_at']);
        if (! $event) {
            return;
        }

        $values = ['starts_at' => $utcStart->format('Y-m-d H:i:s'), 'updated_at' => now()];
        $localEnd = $event->ends_at ? $this->localDateTime((string) $event->ends_at) : null;
        if ($localEnd) {
            $duration = max(0, $originalStart->diffInSeconds($localEnd, false));
            $values['ends_at'] = $utcStart->addSeconds($duration)->format('Y-m-d H:i:s');
        }
        DB::table('calendar_events')->where('id', $eventId)->update($values);

        if (Schema::hasTable('event_reminders')) {
            DB::table('event_reminders')->where('event_id', $eventId)->orderBy('id')->get(['id', 'remind_at'])
                ->each(function ($reminder): void {
                    $localReminder = $this->localDateTime((string) $reminder->remind_at);
                    if ($localReminder) {
                        DB::table('event_reminders')->where('id', $reminder->id)->update([
                            'remind_at' => $localReminder->utc()->format('Y-m-d H:i:s'),
                            'updated_at' => now(),
                        ]);
                    }
                });
        }
    }

    private function localDateTime(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value, 'Europe/Prague');
        } catch (Throwable) {
            return null;
        }
    }
};
