<?php

namespace App\Services\Planning;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** One authoritative creation and lifecycle path for gift ideas. */
class GiftIdeaService
{
    public function __construct(private readonly LifeEventService $lifeEvents) {}

    public function create(int $spaceId, int $actorId, array $attributes, string $source = 'manual'): object
    {
        $now = now();
        $isPrivate = (bool) ($attributes['is_private'] ?? false);
        $row = [
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $spaceId,
            'created_by' => $actorId,
            'person_id' => $attributes['person_id'] ?? null,
            'assigned_to' => $attributes['assigned_to'] ?? null,
            'title' => $attributes['title'],
            'occasion' => $attributes['occasion'] ?? null,
            'due_date' => $attributes['due_date'] ?? null,
            'budget' => $attributes['budget'] ?? null,
            'currency' => strtoupper($attributes['currency'] ?? 'CZK'),
            'source_url' => $attributes['source_url'] ?? null,
            'status' => $attributes['status'] ?? 'idea',
            'reminder_days' => json_encode($attributes['reminder_days'] ?? [30, 7, 1]),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (! Schema::hasColumn('gift_ideas', 'person_id')) unset($row['person_id']);
        if (! Schema::hasColumn('gift_ideas', 'assigned_to')) unset($row['assigned_to']);
        if (! Schema::hasColumn('gift_ideas', 'source_url')) unset($row['source_url']);
        if (Schema::hasColumn('gift_ideas', 'visibility') && Schema::hasColumn('gift_ideas', 'private_to_user_id')) {
            $row['visibility'] = $isPrivate ? 'private' : 'shared';
            $row['private_to_user_id'] = $isPrivate ? $actorId : null;
        }
        if (Schema::hasColumn('gift_ideas', 'lifecycle')) {
            $row['lifecycle'] = json_encode([['stage' => 'idea', 'at' => now('Europe/Prague')->toIso8601String(), 'by' => $actorId]]);
        }
        if (Schema::hasColumn('gift_ideas', 'created_from')) $row['created_from'] = $source;
        if (Schema::hasColumn('gift_ideas', 'source_reference')) $row['source_reference'] = $attributes['source_reference'] ?? null;

        $id = DB::table('gift_ideas')->insertGetId($row);
        $gift = DB::table('gift_ideas')->find($id);
        if (!$isPrivate) $this->lifeEvents->record($spaceId, $actorId, 'gift.idea.created', $gift->title, $source, 'gift_idea', $id, $gift->due_date, ['occasion' => $gift->occasion, 'budget' => $gift->budget]);

        return $gift;
    }

    public function transition(object $gift, int $actorId, string $stage, string $source = 'manual'): object
    {
        $data = ['updated_at' => now()];
        if (Schema::hasColumn('gift_ideas', 'lifecycle')) {
            $lifecycle = json_decode($gift->lifecycle ?? '[]', true) ?: [];
            if (($lifecycle[array_key_last($lifecycle)]['stage'] ?? null) !== $stage) {
                $lifecycle[] = ['stage' => $stage, 'at' => now('Europe/Prague')->toIso8601String(), 'by' => $actorId];
            }
            $data['lifecycle'] = json_encode($lifecycle);
        }
        $data['status'] = match ($stage) { 'idea' => 'idea', 'planned' => 'planned', default => 'purchased' };
        DB::table('gift_ideas')->where('id', $gift->id)->update($data);
        $updated = DB::table('gift_ideas')->find($gift->id);
        if (($updated->visibility ?? 'shared') !== 'private') $this->lifeEvents->record((int) $updated->gallery_space_id, $actorId, 'gift.lifecycle.' . $stage, $updated->title, $source, 'gift_idea', $updated->id, now('Europe/Prague'));

        return $updated;
    }
}
