<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Imports a deliberately small, safe subset of ICS without contacting third parties. */
class IcsCalendarImportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'ics' => 'required|string|max:524288']);
        $space = $request->user()->gallerySpaces()->whereKey($data['gallery_space_id'])->firstOrFail();
        $rows = $this->events($data['ics']);
        abort_if(empty($rows), 422, 'Soubor neobsahuje žádnou platnou událost VEVENT.');

        $created = 0; $skipped = 0; $recurrenceWarnings = 0;
        foreach (array_slice($rows, 0, 100) as $row) {
            if (CalendarEvent::where('gallery_space_id', $space->id)->where('metadata->ics_uid', $row['uid'])->exists()) { $skipped++; continue; }
            $recurrence = $this->recurrence($row['rrule'] ?? null, $row['timezone']);
            if (!empty($row['rrule']) && !$recurrence) $recurrenceWarnings++;
            $event = CalendarEvent::create([
                'gallery_space_id' => $space->id, 'created_by' => $request->user()->id,
                'title' => $row['title'], 'description' => $row['description'], 'type' => 'event', 'status' => 'planned',
                'starts_at' => $row['starts_at'], 'ends_at' => $row['ends_at'], 'all_day' => $row['all_day'],
                'timezone' => $row['timezone'], 'place_name' => $row['place_name'], 'recurrence_rule' => $recurrence,
                'metadata' => ['ics_uid' => $row['uid'], 'ics_imported_at' => now()->toIso8601String(), 'ics_rrule' => $row['rrule'] ?? null],
            ]);
            $event->participants()->syncWithoutDetaching([$request->user()->id => ['role' => 'owner', 'response' => 'accepted']]);
            $created++;
        }

        return response()->json(['created' => $created, 'skipped_duplicates' => $skipped, 'recurrence_warnings' => $recurrenceWarnings]);
    }

    /** @return array<int, array{uid:string,title:string,description:?string,place_name:?string,starts_at:Carbon,ends_at:?Carbon,all_day:bool,timezone:string,rrule:?string}> */
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
            $end = isset($fields['DTEND']) ? $this->date($fields['DTEND'], $timezone) : null;
            $allDay = (bool) preg_match('/^\d{8}$/', trim($fields['DTSTART']));
            $events[] = [
                'uid' => trim($fields['UID'] ?? hash('sha256', implode('|', [$fields['SUMMARY'] ?? '', $fields['DTSTART'], $fields['LOCATION'] ?? '']))),
                'title' => $this->text($fields['SUMMARY'] ?? 'Importovaná akce'), 'description' => isset($fields['DESCRIPTION']) ? $this->text($fields['DESCRIPTION']) : null,
                'place_name' => isset($fields['LOCATION']) ? $this->text($fields['LOCATION']) : null,
                'starts_at' => $start, 'ends_at' => $end, 'all_day' => $allDay, 'timezone' => $timezone, 'rrule' => $fields['RRULE'] ?? null,
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
}
