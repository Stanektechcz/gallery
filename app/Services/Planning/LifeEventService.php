<?php

namespace App\Services\Planning;

use App\Models\LifeEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

/**
 * Records one immutable, module-neutral trace for a shared life event.
 * The original module remains the source of truth; this table only connects it.
 */
class LifeEventService
{
    public function record(
        int $spaceId,
        int $userId,
        string $kind,
        string $title,
        string $source,
        ?string $subjectType = null,
        ?int $subjectId = null,
        CarbonInterface|string|null $occurredAt = null,
        array $metadata = [],
    ): ?LifeEvent {
        if (! Schema::hasTable('life_events')) return null;

        return LifeEvent::create([
            'gallery_space_id' => $spaceId,
            'created_by' => $userId,
            'kind' => $kind,
            'title' => $title,
            'source' => $source,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'occurred_at' => $occurredAt ?? now('Europe/Prague'),
            'metadata' => $metadata,
        ]);
    }
}