<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Notifications\GalleryNotification;
use App\Services\Planning\CalendarEventTripService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Imports a deliberately small, safe subset of ICS without contacting third parties. */
class IcsCalendarImportController extends Controller
{
    public function __construct(private readonly CalendarEventTripService $tripService) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gallery_space_id' => 'required|integer',
            'ics' => 'required|string|max:524288',
            'share_with_space' => 'sometimes|boolean',
            'reminder_minutes' => 'nullable|integer|min:0|max:525600',
            'create_trips' => 'sometimes|boolean',
        ]);
        $space = $request->user()->gallerySpaces()->whereKey($data['gallery_space_id'])->firstOrFail();
        $rows = $this->events($data['ics']);
        abort_if(empty($rows), 422, 'Soubor neobsahuje žádnou platnou událost VEVENT.');

        $share = (bool) ($data['share_with_space'] ?? false);
        $memberIds = $share
            ? $space->members()->pluck('users.id')->map(fn ($id) => (int) $id)->push($request->user()->id)->unique()->values()
            : collect([$request->user()->id]);

        $stats = DB::transaction(function () use ($rows, $space, $request, $data, $memberIds) {
            $created = 0;
            $skipped = 0;
            $recurrenceWarnings = 0;
            $tripsCreated = 0;
            $remindersCreated = 0;

            foreach (array_slice($rows, 0, 100) as $row) {
                if (CalendarEvent::where('gallery_space_id', $space->id)->where('metadata->ics_uid', $row['uid'])->exists()) {
                    $skipped++;
                    continue;
                }

                $recurrence = $this->recurrence($row['rrule'] ?? null, $row['timezone']);
                if (! empty($row['rrule']) && ! $recurrence) {
                    $recurrenceWarnings++;
                }

                $event = CalendarEvent::create([
                    'gallery_space_id' => $space->id,
                    'created_by' => $request->user()->id,
                    'title' => $row['title'],
                    'description' => $row['description'],
                    'type' => $row['type'],
                    'status' => $row['status'],
                    'starts_at' => $row['starts_at'],
                    'ends_at' => $row['ends_at'],
                    'all_day' => $row['all_day'],
                    'timezone' => $row['timezone'],
                    'place_name' => $row['place_name'],
                    'recurrence_rule' => $recurrence,
                    'metadata' => [
                        'kind' => 'ics_import',
                        'ics_uid' => $row['uid'],
                        'ics_imported_at' => now()->toIso8601String(),
                        'ics_rrule' => $row['rrule'] ?? null,
                        'ics_categories' => $row['categories'],
                    ],
                ]);

                foreach ($memberIds as $memberId) {
                    $isOwner = (int) $memberId === (int) $request->user()->id;
                    $event->participants()->attach($memberId, [
                        'role' => $isOwner ? 'owner' : 'guest',
                        'response' => $isOwner ? 'accepted' : 'pending',
                    ]);

                    if (array_key_exists('reminder_minutes', $data) && $data['reminder_minutes'] !== null) {
                        $event->reminders()->create([
                            'user_id' => $memberId,
                            'channel' => 'database',
                            'remind_at' => $event->starts_at->copy()->subMinutes((int) $data['reminder_minutes']),
                            'status' => 'pending',
                        ]);
                        $remindersCreated++;
                    }
                }

                if ($row['source_url']) {
                    $event->attachments()->create([
                        'label' => $row['type'] === 'reservation' ? 'Odkaz na rezervaci' : 'Odkaz z importovaného kalendáře',
                        'external_url' => $row['source_url'],
                        'kind' => $row['type'] === 'reservation' ? 'reservation' : 'link',
                    ]);
                }

                if (($data['create_trips'] ?? false) && $this->isTripCandidate($row, $recurrence)) {
                    [, $tripCreated] = $this->tripService->createFromEvent($event, $request->user()->id);
                    $tripsCreated += $tripCreated ? 1 : 0;
                }

                $created++;
            }

            return compact('created', 'skipped', 'recurrenceWarnings', 'tripsCreated', 'remindersCreated');
        });

        if ($share && $stats['created'] > 0) {
            GalleryNotification::notifySpace(
                $space,
                $request->user()->id,
                'calendar.imported',
                "Do společného kalendáře bylo importováno {$stats['created']} událostí.",
                '/calendar',
                ['created' => $stats['created'], 'trips_created' => $stats['tripsCreated']],
            );
        }

        return response()->json([
            'created' => $stats['created'],
            'skipped_duplicates' => $stats['skipped'],
            'recurrence_warnings' => $stats['recurrenceWarnings'],
            'trips_created' => $stats['tripsCreated'],
            'reminders_created' => $stats['remindersCreated'],
            'shared_member_count' => max(0, $memberIds->count() - 1),
        ]);
    }

    /** @return array<int, array{uid:string,title:string,description:?string,place_name:?string,starts_at:Carbon,ends_at:?Carbon,all_day:bool,timezone:string,rrule:?string,type:string,status:string,categories:array<int,string>,source_url:?string}> */
    private function events(string $ics): array
    {
        $ics = preg_replace("/\r?\n[ \t]/", '', $ics) ?? $ics; // RFC 5545 line folding
        preg_match_all('/BEGIN:VEVENT\s*(.*?)\s*END:VEVENT/is', $ics, $matches);
        $events = [];
        foreach ($matches[1] ?? [] as $block) {
            $fields = []; $parameters = [];
            foreach (preg_split('/\r?\n/', trim($block)) ?: [] as $line) {
                if (!str_contains($line, ':')) continue;
                [$name, $value] = explode(':', $line, 2); $parts = explode(';', $name); $key = strtoupper(array_shift($parts));
                $fields[$key] = $value; $parameters[$key] = implode(';', $parts);
            }
            if (empty($fields['DTSTART'])) continue;
            $timezone = 'Europe/Prague';
            if (!empty($parameters['DTSTART']) && preg_match('/TZID=([^;:]+)/i', $parameters['DTSTART'], $match)) {
                try { new \DateTimeZone($match[1]); $timezone = $match[1]; } catch (\Throwable) { /* use Czech default */ }
            }
            $start = $this->date($fields['DTSTART'], $timezone); if (!$start) continue;
            $allDay = (bool) preg_match('/^\d{8}$/', trim($fields['DTSTART']));
            $end = isset($fields['DTEND']) ? $this->date($fields['DTEND'], $timezone) : null;
            // RFC 5545 stores an all-day DTEND as an exclusive date. Internally we use an inclusive end.
            if ($allDay && $end?->gt($start)) {
                $end->subSecond();
            }
            $categories = isset($fields['CATEGORIES'])
                ? array_values(array_filter(array_map(fn ($category) => $this->text($category), explode(',', $fields['CATEGORIES']))))
                : [];
            $title = Str::limit($this->text($fields['SUMMARY'] ?? 'Importovaná akce'), 160, '');
            $events[] = [
                'uid' => Str::limit(trim($fields['UID'] ?? hash('sha256', implode('|', [$fields['SUMMARY'] ?? '', $fields['DTSTART'], $fields['LOCATION'] ?? '']))), 512, ''),
                'title' => $title,
                'description' => isset($fields['DESCRIPTION']) ? Str::limit($this->text($fields['DESCRIPTION']), 10000, '') : null,
                'place_name' => isset($fields['LOCATION']) ? Str::limit($this->text($fields['LOCATION']), 255, '') : null,
                'starts_at' => $start, 'ends_at' => $end, 'all_day' => $allDay, 'timezone' => $timezone, 'rrule' => $fields['RRULE'] ?? null,
                'type' => $this->eventType($categories, $title),
                'status' => strtoupper(trim($fields['STATUS'] ?? '')) === 'CANCELLED' ? 'cancelled' : 'planned',
                'categories' => $categories,
                'source_url' => $this->safeUrl($fields['URL'] ?? null),
            ];
        }
        return $events;
    }

    private function date(string $value, string $timezone): ?Carbon
    {
        $value = trim($value);
        try {
            if (preg_match('/^\d{8}$/', $value)) return Carbon::createFromFormat('!Ymd', $value, $timezone);
            if (preg_match('/^\d{8}T\d{6}Z$/', $value)) return Carbon::createFromFormat('!Ymd\\THis\\Z', $value, 'UTC')->setTimezone($timezone);
            if (preg_match('/^\d{8}T\d{6}$/', $value)) return Carbon::createFromFormat('!Ymd\\THis', $value, $timezone);
            if (preg_match('/^\d{8}T\d{4}$/', $value)) return Carbon::createFromFormat('!Ymd\\THi', $value, $timezone);
        } catch (\Throwable) { return null; }
        return null;
    }

    private function recurrence(?string $rule, string $timezone): ?array
    {
        if (!$rule || str_contains(strtoupper($rule), 'COUNT=')) return null; // count needs per-occurrence state; do not misrepresent it
        parse_str(str_replace(';', '&', strtoupper($rule)), $parts);
        $frequency = ['DAILY' => 'daily', 'WEEKLY' => 'weekly', 'MONTHLY' => 'monthly', 'YEARLY' => 'yearly'][$parts['FREQ'] ?? ''] ?? null;
        if (!$frequency) return null;
        $result = ['frequency' => $frequency, 'interval' => min(52, max(1, (int) ($parts['INTERVAL'] ?? 1)))];
        if (!empty($parts['UNTIL']) && ($until = $this->date($parts['UNTIL'], $timezone))) $result['until'] = $until->toDateString();
        return $result;
    }

    private function text(string $value): string
    {
        return trim(str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $value));
    }

    /** @param array<int, string> $categories */
    private function eventType(array $categories, string $title): string
    {
        $haystack = Str::lower(implode(' ', [...$categories, $title]));
        if (Str::contains($haystack, ['reservation', 'booking', 'rezervace', 'letenka', 'jízdenka'])) return 'reservation';
        if (Str::contains($haystack, ['travel', 'trip', 'journey', 'holiday', 'vacation', 'cesta', 'výlet', 'dovolená'])) return 'trip';
        if (Str::contains($haystack, ['anniversary', 'výročí'])) return 'anniversary';
        if (Str::contains($haystack, ['birthday', 'narozeniny'])) return 'birthday';
        return 'event';
    }

    private function safeUrl(?string $url): ?string
    {
        if (! $url) return null;
        $url = Str::limit(trim($url), 2048, '');
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! Str::startsWith(Str::lower($url), ['https://', 'http://'])) return null;
        return $url;
    }

    /** @param array{starts_at:Carbon,ends_at:?Carbon,rrule:?string} $row */
    private function isTripCandidate(array $row, ?array $recurrence): bool
    {
        if ($recurrence || ! empty($row['rrule']) || ! $row['ends_at']) return false;
        $days = $row['starts_at']->copy()->startOfDay()->diffInDays($row['ends_at']->copy()->startOfDay());
        return $days >= 1 && $days <= 90;
    }
}
