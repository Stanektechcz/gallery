<?php

namespace App\Services\Planning;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Central creation path for shared milestones and anniversaries. */
class RelationshipMilestoneService
{
    public function __construct(private readonly LifeEventService $lifeEvents) {}

    public function create(int $spaceId, int $actorId, array $attributes, string $source = 'manual'): object
    {
        $row = [
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $spaceId,
            'created_by' => $actorId,
            'title' => $attributes['title'],
            'kind' => $attributes['kind'] ?? 'milestone',
            'person_name' => $attributes['person_name'] ?? null,
            'relationship' => $attributes['relationship'] ?? null,
            'is_highlighted' => $attributes['is_highlighted'] ?? false,
            'description' => $attributes['description'] ?? null,
            'occurred_on' => $attributes['occurred_on'],
            'icon' => $attributes['icon'] ?? '❤️',
            'visibility' => $attributes['visibility'] ?? 'shared',
            'remind_annually' => $attributes['remind_annually'] ?? true,
            'media_item_id' => $attributes['media_item_id'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('relationship_milestones', 'created_from')) $row['created_from'] = $source;
        if (Schema::hasColumn('relationship_milestones', 'source_reference')) $row['source_reference'] = $attributes['source_reference'] ?? null;
        $id = DB::table('relationship_milestones')->insertGetId($row);
        $milestone = DB::table('relationship_milestones')->find($id);
        $this->lifeEvents->record($spaceId, $actorId, 'milestone.created', $milestone->title, $source, 'relationship_milestone', $id, $milestone->occurred_on, ['remind_annually' => (bool) $milestone->remind_annually, 'visibility' => $milestone->visibility]);

        return $milestone;
    }
}