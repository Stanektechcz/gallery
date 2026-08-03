<?php

namespace App\Services\Planning;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Stores travel notes in one place regardless of whether they come from a form or the assistant. */
class TravelInboxService
{
    public function __construct(private readonly LifeEventService $lifeEvents) {}

    public function create(int $spaceId, int $actorId, array $attributes, string $source = 'manual'): object
    {
        $metadata = $attributes['metadata'] ?? [];
        if (is_string($metadata)) $metadata = json_decode($metadata, true) ?: [];
        $metadata['source'] ??= $source;
        $state = $attributes['state'] ?? ((! empty($attributes['trip_id']) || ! empty($attributes['event_id'])) ? 'assigned' : 'inbox');
        $row = [
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $spaceId,
            'added_by' => $actorId,
            'trip_id' => $attributes['trip_id'] ?? null,
            'trip_day_id' => $attributes['trip_day_id'] ?? null,
            'trip_activity_id' => $attributes['trip_activity_id'] ?? null,
            'event_id' => $attributes['event_id'] ?? null,
            'title' => $attributes['title'],
            'notes' => $attributes['notes'] ?? null,
            'source_url' => $attributes['source_url'] ?? null,
            'kind' => $attributes['kind'] ?? 'idea',
            'state' => $state,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('travel_inbox_items', 'created_from')) $row['created_from'] = $source;
        if (Schema::hasColumn('travel_inbox_items', 'source_reference')) $row['source_reference'] = $attributes['source_reference'] ?? null;
        $id = DB::table('travel_inbox_items')->insertGetId($row);
        $item = DB::table('travel_inbox_items')->find($id);
        $kind = $item->kind === 'itinerary' ? 'trip.itinerary.drafted' : 'travel.inbox.created';
        $this->lifeEvents->record($spaceId, $actorId, $kind, $item->title, $source, 'travel_inbox_item', $id, now('Europe/Prague'), ['trip_id' => $item->trip_id, 'event_id' => $item->event_id]);

        return $item;
    }
}