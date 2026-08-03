<?php

namespace App\Services\Planning;

use App\Models\CalendarEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Keeps restorable, immutable snapshots of editable calendar-event fields. */
class CalendarEventRevisionService
{
    private const FIELDS = [
        'title', 'description', 'type', 'status', 'starts_at', 'ends_at', 'all_day', 'timezone',
        'place_name', 'latitude', 'longitude', 'departure_buffer_minutes', 'recurrence_rule',
        'color', 'is_private', 'trip_id', 'album_id', 'metadata',
    ];

    public function available(): bool
    {
        return Schema::hasTable('calendar_event_revisions');
    }

    public function snapshot(CalendarEvent $event): array
    {
        $snapshot = [];
        foreach (self::FIELDS as $field) {
            $value = $event->getAttribute($field);
            $snapshot[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value;
        }
        return $snapshot;
    }

    public function record(CalendarEvent $event, int $actorId, string $action, array $snapshot, array $changedFields = []): void
    {
        if (! $this->available()) return;
        DB::table('calendar_event_revisions')->insert([
            'uuid' => (string) Str::uuid(),
            'calendar_event_id' => $event->id,
            'created_by' => $actorId,
            'action' => $action,
            'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'changed_fields' => json_encode(array_values($changedFields), JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }

    public function history(CalendarEvent $event): Collection
    {
        if (! $this->available()) return collect();
        return DB::table('calendar_event_revisions as revision')
            ->leftJoin('users as user', 'user.id', '=', 'revision.created_by')
            ->where('revision.calendar_event_id', $event->id)
            ->orderByDesc('revision.created_at')->limit(50)
            ->get(['revision.uuid', 'revision.action', 'revision.snapshot', 'revision.changed_fields', 'revision.created_at', 'user.name as actor_name'])
            ->map(fn ($revision) => [
                'uuid' => $revision->uuid,
                'action' => $revision->action,
                'snapshot' => json_decode($revision->snapshot, true) ?: [],
                'changed_fields' => json_decode($revision->changed_fields, true) ?: [],
                'created_at' => $revision->created_at,
                'actor_name' => $revision->actor_name ?: 'Člen společného prostoru',
            ]);
    }

    public function revision(CalendarEvent $event, string $uuid): ?object
    {
        if (! $this->available()) return null;
        return DB::table('calendar_event_revisions')->where('calendar_event_id', $event->id)->where('uuid', $uuid)->first();
    }

    public function restoreData(object $revision): array
    {
        $snapshot = json_decode((string) $revision->snapshot, true);
        if (! is_array($snapshot)) return [];
        return collect($snapshot)->only(self::FIELDS)->all();
    }
}