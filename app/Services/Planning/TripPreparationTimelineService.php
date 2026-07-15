<?php

namespace App\Services\Planning;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TripPreparationTimelineService
{
    private const SOURCE = 'trip_preparation';

    public function snapshot(object $trip): array
    {
        $event = $this->linkedEvent($trip);
        $timezone = $trip->timezone ?: ($event->timezone ?? 'Europe/Prague');
        $tripStart = $event?->starts_at
            ? Carbon::parse($event->starts_at, $timezone)
            : Carbon::parse($trip->start_date . ' 09:00:00', $timezone);
        $tripEnd = $event?->ends_at
            ? Carbon::parse($event->ends_at, $timezone)
            : Carbon::parse($trip->end_date . ' 20:00:00', $timezone);
        $timeline = collect([
            $this->timelineItem('trip_start', 'trip', 'Začátek cesty', $tripStart, $trip->name),
            $this->timelineItem('trip_end', 'trip', 'Návrat z cesty', $tripEnd, $trip->name),
        ]);
        $actions = collect();
        $connectionChecks = collect();
        $choices = Schema::hasTable('trip_travel_choices')
            ? DB::table('trip_travel_choices')->where('trip_id', $trip->id)->where('is_selected', true)->get()
            : collect();
        $transportChoices = $choices->where('kind', 'transport')->values();
        $stayChoices = $choices->where('kind', 'accommodation')->values();

        // A route variant is also a valid transport decision. Date ideas and
        // manually assembled routes should not be reported as "doprava chybí"
        // merely because they were not created through the ticket chooser.
        if ($transportChoices->isEmpty() && Schema::hasTable('trip_route_variants')) {
            $transportChoices = DB::table('trip_route_variants')->where('trip_id', $trip->id)
                ->where('is_selected', true)->get()->map(function ($route) {
                    return (object) [
                        'id' => 'route_'.$route->id,
                        'title' => $route->title,
                        'provider' => 'Plán trasy',
                        'source_url' => null,
                        'details' => $route->data,
                    ];
                });
        }

        if ($transportChoices->isEmpty() && $tripStart->isFuture()) {
            $actions->push($this->action('transport_missing', 'Vybrat dopravu', 'Porovnejte spojení a uložte vybranou variantu přímo do cesty.', $tripStart->copy()->subDays(14), 'high'));
        }
        foreach ($transportChoices as $choice) {
            $details = $this->details($choice->details);
            $departure = $this->parseMoment($details['departure'] ?? null, $trip->start_date, $timezone);
            $arrival = $this->parseMoment($details['arrival'] ?? null, $trip->start_date, $timezone);
            if ($departure) {
                $timeline->push($this->timelineItem("transport_departure_{$choice->id}", 'transport', 'Odjezd: ' . $choice->title, $departure, $choice->provider, $choice->source_url));
                if ($departure->isFuture()) {
                    $actions->push($this->action("verify_transport_{$choice->id}", 'Ověřit spoj, nástupiště a případné změny', $choice->title, $departure->copy()->subHours(6), 'high'));
                }
            } elseif ($tripStart->isFuture()) {
                $actions->push($this->action("transport_time_missing_{$choice->id}", 'Doplnit čas odjezdu', "U varianty „{$choice->title}“ chybí čas, takže nelze připravit odjezdovou připomínku.", $tripStart->copy()->subWeek(), 'high'));
            }
            if ($arrival) {
                $timeline->push($this->timelineItem("transport_arrival_{$choice->id}", 'transport', 'Příjezd: ' . $choice->title, $arrival, $choice->provider, $choice->source_url));
            }
            $legs = collect($details['legs'] ?? [])->filter(fn ($leg) => is_array($leg))->values();
            for ($index = 0; $index < $legs->count() - 1; $index++) {
                $arrivalAt = $this->parseMoment($legs[$index]['arrival'] ?? null, $trip->start_date, $timezone);
                $nextDeparture = $this->parseMoment($legs[$index + 1]['departure'] ?? null, $trip->start_date, $timezone);
                if (! $arrivalAt || ! $nextDeparture) continue;
                $minutes = (int) floor(($nextDeparture->timestamp - $arrivalAt->timestamp) / 60);
                $risk = $minutes < 0 ? 'invalid' : ($minutes < 12 ? 'critical' : ($minutes < 20 ? 'tight' : 'ok'));
                $check = [
                    'key' => "transfer_{$choice->id}_{$index}",
                    'choice_id' => $choice->id,
                    'from' => $legs[$index]['to'] ?? $legs[$index]['from'] ?? null,
                    'to' => $legs[$index + 1]['from'] ?? $legs[$index + 1]['to'] ?? null,
                    'minutes' => $minutes,
                    'risk' => $risk,
                ];
                $connectionChecks->push($check);
                if (in_array($risk, ['critical', 'invalid'], true)) {
                    $actions->push($this->action($check['key'], 'Prověřit krátký přestup', "Na přestup je pouze {$minutes} minut. Zvažte bezpečnější variantu.", ($departure ?? $tripStart)->copy()->subDay(), 'high'));
                }
            }
        }

        $isOvernight = Carbon::parse($trip->start_date)->lt(Carbon::parse($trip->end_date));
        if ($isOvernight && $stayChoices->isEmpty() && $tripStart->isFuture()) {
            $actions->push($this->action('accommodation_missing', 'Vybrat ubytování', 'U vícedenní cesty zatím není uložený pobyt.', $tripStart->copy()->subDays(21), 'high'));
        }
        foreach ($stayChoices as $choice) {
            $details = $this->details($choice->details);
            $checkin = $this->parseMoment($details['checkin'] ?? null, $trip->start_date, $timezone, '15:00:00');
            $checkout = $this->parseMoment($details['checkout'] ?? null, $trip->end_date, $timezone, '10:00:00');
            if ($checkin) {
                $timeline->push($this->timelineItem("checkin_{$choice->id}", 'accommodation', 'Check-in: ' . $choice->title, $checkin, $choice->provider, $choice->source_url));
                if ($checkin->isFuture()) {
                    $actions->push($this->action("confirm_checkin_{$choice->id}", 'Potvrdit check-in a adresu ubytování', $choice->title, $checkin->copy()->subDay(), 'normal'));
                }
            }
            if ($checkout) {
                $timeline->push($this->timelineItem("checkout_{$choice->id}", 'accommodation', 'Check-out: ' . $choice->title, $checkout, $choice->provider, $choice->source_url));
            }
            if (empty($details['reference']) && $tripStart->isFuture()) {
                $actions->push($this->action("booking_reference_missing_{$choice->id}", 'Doplnit kód rezervace ubytování', $choice->title, $tripStart->copy()->subDays(3), 'normal'));
            }
        }

        if (Schema::hasTable('trip_document_checks')) {
            foreach (DB::table('trip_document_checks')->where('trip_id', $trip->id)->get() as $document) {
                if (in_array($document->status, ['required', 'missing'], true)) {
                    $actions->push($this->action("document_missing_{$document->id}", 'Doplnit doklad: ' . $document->title, 'Doklad nebo rezervace ještě není označena jako připravená.', $tripStart->copy()->subWeek(), 'high'));
                }
                if (! $document->expires_on) continue;
                $expiry = Carbon::parse($document->expires_on . ' 23:59:59', $timezone);
                $timeline->push($this->timelineItem("document_expiry_{$document->id}", 'document', 'Konec platnosti: ' . $document->title, $expiry, $document->type));
                if ($expiry->lt($tripEnd)) {
                    $actions->push($this->action("document_expiry_{$document->id}", 'Obnovit doklad před cestou: ' . $document->title, 'Platnost končí před návratem z cesty.', $expiry->copy()->subDays(30), 'high'));
                }
            }
        }

        if (Schema::hasTable('trip_vehicle_costs')) {
            foreach (DB::table('trip_vehicle_costs')->where('trip_id', $trip->id)->where('type', 'vignette')->whereNotNull('valid_until')->get() as $vignette) {
                $expiry = Carbon::parse($vignette->valid_until . ' 23:59:59', $timezone);
                $timeline->push($this->timelineItem("vignette_expiry_{$vignette->id}", 'vehicle', 'Konec známky: ' . $vignette->title, $expiry, 'Dálniční známka'));
                if ($expiry->lt($tripEnd)) {
                    $actions->push($this->action("vignette_expiry_{$vignette->id}", 'Vyřešit dálniční známku: ' . $vignette->title, 'Známka nebude platná po celou cestu.', $expiry->copy()->subDays(7), 'high'));
                }
            }
        }

        $emergencyCard = Schema::hasTable('travel_emergency_cards') ? DB::table('travel_emergency_cards')->where('trip_id', $trip->id)->first() : null;
        if ($stayChoices->isNotEmpty() && (! $emergencyCard || ! $emergencyCard->accommodation_address) && $tripStart->isFuture()) {
            $actions->push($this->action('emergency_address_missing', 'Doplnit adresu pobytu do nouzové karty', 'Adresa pak zůstane dostupná i v offline cestovní kartě.', $tripStart->copy()->subDays(2), 'normal'));
        }

        $timeline = $timeline->sortBy('at')->values();
        $actions = $actions->unique('key')->sortBy('due_at')->values();
        $criticalCount = $actions->where('priority', 'high')->count();
        $next = $timeline->first(fn (array $item) => Carbon::parse($item['at'])->gte(now()));
        $score = max(0, 100 - min(70, $criticalCount * 15) - min(30, $actions->where('priority', 'normal')->count() * 5));

        return [
            'status' => $criticalCount > 0 ? 'attention' : ($actions->isNotEmpty() ? 'in_progress' : 'ready'),
            'score' => $score,
            'event_uuid' => $event?->uuid,
            'trip_start' => $tripStart->toIso8601String(),
            'trip_end' => $tripEnd->toIso8601String(),
            'next_item' => $next,
            'timeline' => $timeline,
            'actions' => $actions,
            'connection_checks' => $connectionChecks,
            'summary' => [
                'actions_total' => $actions->count(),
                'critical_total' => $criticalCount,
                'selected_transport' => $transportChoices->count(),
                'selected_accommodation' => $stayChoices->count(),
                'safe_connections' => $connectionChecks->where('risk', 'ok')->count(),
                'risky_connections' => $connectionChecks->whereIn('risk', ['critical', 'invalid', 'tight'])->count(),
            ],
        ];
    }

    public function sync(object $trip, ?array $snapshot = null): array
    {
        $snapshot ??= $this->snapshot($trip);
        $event = $this->linkedEvent($trip);
        if (! $event) {
            return ['event_uuid' => null, 'tasks_created' => 0, 'tasks_updated' => 0, 'tasks_completed' => 0, 'reminders_created' => 0, 'reminders_updated' => 0, 'reminders_skipped' => 0];
        }
        if (! $this->canSync()) {
            throw new \RuntimeException('Pro automatickou přípravu dokončete migrace aplikace.');
        }

        $taskKeys = collect($snapshot['actions'])->pluck('key')->all();
        $created = 0;
        $updated = 0;
        foreach ($snapshot['actions'] as $action) {
            $existing = DB::table('event_tasks')->where('event_id', $event->id)->where('automation_key', $action['key'])->first();
            $values = [
                'title' => $action['title'],
                'notes' => $action['message'] . ' Automaticky propojeno s přípravou cesty.',
                'due_at' => $action['due_at'],
                'priority' => $action['priority'],
                'automation_source' => self::SOURCE,
                'automation_key' => $action['key'],
                'updated_at' => now(),
            ];
            if ($existing) {
                DB::table('event_tasks')->where('id', $existing->id)->update($values);
                $updated++;
            } else {
                DB::table('event_tasks')->insert($values + ['event_id' => $event->id, 'sort_order' => ((int) DB::table('event_tasks')->where('event_id', $event->id)->max('sort_order')) + 1, 'created_at' => now()]);
                $created++;
            }
        }
        $obsolete = DB::table('event_tasks')->where('event_id', $event->id)->where('automation_source', self::SOURCE)->whereNull('completed_at');
        if ($taskKeys !== []) $obsolete->whereNotIn('automation_key', $taskKeys);
        $completed = $obsolete->update(['completed_at' => now(), 'updated_at' => now()]);

        $reminderSpecs = $this->reminderSpecs($snapshot);
        $members = DB::table('gallery_space_user')->where('gallery_space_id', $trip->gallery_space_id)->pluck('user_id');
        $reminderKeys = [];
        $remindersCreated = 0;
        $remindersUpdated = 0;
        $remindersSkipped = 0;
        foreach ($members as $memberId) foreach ($reminderSpecs as $spec) {
            $reminderKeys[] = $spec['key'];
            $remindAt = Carbon::parse($spec['remind_at']);
            if ($remindAt->lte(now())) continue;
            $existing = DB::table('event_reminders')->where('event_id', $event->id)->where('user_id', $memberId)->where('automation_key', $spec['key'])->first();
            if ($existing) {
                DB::table('event_reminders')->where('id', $existing->id)->update(['remind_at' => $remindAt, 'status' => 'pending', 'delivered_at' => null, 'last_error' => null, 'updated_at' => now()]);
                $remindersUpdated++;
                continue;
            }
            $manualAtSameTime = DB::table('event_reminders')->where('event_id', $event->id)->where('user_id', $memberId)->whereNull('automation_key')->whereBetween('remind_at', [$remindAt->copy()->subMinute(), $remindAt->copy()->addMinute()])->exists();
            if ($manualAtSameTime) {
                $remindersSkipped++;
                continue;
            }
            DB::table('event_reminders')->insert(['event_id' => $event->id, 'user_id' => $memberId, 'channel' => 'database', 'remind_at' => $remindAt, 'status' => 'pending', 'automation_source' => self::SOURCE, 'automation_key' => $spec['key'], 'created_at' => now(), 'updated_at' => now()]);
            $remindersCreated++;
        }
        $obsoleteReminders = DB::table('event_reminders')->where('event_id', $event->id)->where('automation_source', self::SOURCE)->where('status', 'pending');
        if ($reminderKeys !== []) $obsoleteReminders->whereNotIn('automation_key', array_unique($reminderKeys));
        $obsoleteReminders->delete();

        return ['event_uuid' => $event->uuid, 'tasks_created' => $created, 'tasks_updated' => $updated, 'tasks_completed' => $completed, 'reminders_created' => $remindersCreated, 'reminders_updated' => $remindersUpdated, 'reminders_skipped' => $remindersSkipped];
    }

    private function reminderSpecs(array $snapshot): array
    {
        $specs = [];
        foreach ($snapshot['timeline'] as $item) {
            $at = Carbon::parse($item['at']);
            $offsets = match ($item['kind']) {
                'trip' => $item['key'] === 'trip_start' ? [10080, 1440, 120] : [],
                'transport' => str_starts_with($item['key'], 'transport_departure_') ? [1440, 120] : [],
                'accommodation' => str_starts_with($item['key'], 'checkin_') ? [1440] : [],
                'document' => [43200],
                'vehicle' => [10080],
                default => [],
            };
            foreach ($offsets as $minutes) {
                $specs[] = ['key' => $item['key'] . '_' . $minutes, 'remind_at' => $at->copy()->subMinutes($minutes)->toIso8601String()];
            }
        }

        return collect($specs)->unique('key')->values()->all();
    }

    private function linkedEvent(object $trip): ?object
    {
        if (! Schema::hasTable('calendar_events')) return null;
        return DB::table('calendar_events')->where('gallery_space_id', $trip->gallery_space_id)->where(function ($query) use ($trip) {
            $query->where('trip_id', $trip->id);
            if (Schema::hasColumn('calendar_events', 'source_trip_id')) $query->orWhere('source_trip_id', $trip->id);
        })->where('status', '!=', 'cancelled')
            // A trip can also own reservation events. General preparation tasks
            // and reminders belong to its main calendar card, not to the last
            // imported train ticket or hotel check-in.
            ->orderByRaw("CASE WHEN type = 'trip' THEN 0 ELSE 1 END")
            ->orderByDesc('id')->first();
    }

    private function timelineItem(string $key, string $kind, string $title, Carbon $at, ?string $detail = null, ?string $sourceUrl = null): array
    {
        $status = $at->isPast() ? 'past' : ($at->isToday() ? 'today' : ($at->lte(now()->addDays(7)) ? 'soon' : 'future'));
        return array_filter(['key' => $key, 'kind' => $kind, 'title' => $title, 'at' => $at->toIso8601String(), 'status' => $status, 'detail' => $detail, 'source_url' => $sourceUrl], fn ($value) => $value !== null);
    }

    private function action(string $key, string $title, string $message, Carbon $dueAt, string $priority): array
    {
        if ($dueAt->isPast()) $dueAt = now()->addHours(2);
        return ['key' => $key, 'title' => $title, 'message' => $message, 'due_at' => $dueAt->toIso8601String(), 'priority' => $priority];
    }

    private function parseMoment(mixed $value, string $fallbackDate, string $timezone, string $fallbackTime = '09:00:00'): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') return null;
        $value = trim($value);
        try {
            if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) return Carbon::parse($fallbackDate . ' ' . $value, $timezone);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return Carbon::parse($value . ' ' . $fallbackTime, $timezone);
            return Carbon::parse($value, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    private function details(mixed $details): array
    {
        if (is_array($details)) return $details;
        if (! is_string($details) || $details === '') return [];
        $decoded = json_decode($details, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function canSync(): bool
    {
        return Schema::hasColumn('event_tasks', 'automation_source')
            && Schema::hasColumn('event_tasks', 'automation_key')
            && Schema::hasColumn('event_reminders', 'automation_source')
            && Schema::hasColumn('event_reminders', 'automation_key');
    }
}
