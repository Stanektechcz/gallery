<?php

namespace App\Services\Media;

use App\Models\Album;
use App\Models\MediaItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * SmartAlbumService — evaluates smart album rules and returns matching media.
 *
 * Rule format:
 * {
 *   "match": "all|any",
 *   "conditions": [
 *     { "field": "rating",      "op": "gte",  "value": 4 },
 *     { "field": "tag_id",      "op": "in",   "value": [5, 12] },
 *     { "field": "is_favorite", "op": "eq",   "value": true },
 *     { "field": "has_gps",     "op": "eq",   "value": true },
 *     { "field": "media_type",  "op": "eq",   "value": "photo" },
 *     { "field": "date_from",   "op": "gte",  "value": "2026-01-01" },
 *     { "field": "date_to",     "op": "lte",  "value": "2026-12-31" },
 *     { "field": "taken_year",  "op": "eq",   "value": 2026 },
 *     { "field": "person_id",   "op": "in",   "value": [3] },
 *     { "field": "camera_make", "op": "eq",   "value": "Apple" },
 *     { "field": "min_width",   "op": "gte",  "value": 3000 },
 *     { "field": "is_panorama", "op": "eq",   "value": true },
 *     { "field": "is_360",      "op": "eq",   "value": true },
 *     { "field": "extension",   "op": "in",   "value": ["heic","jpg"] },
 *   ]
 * }
 */
class SmartAlbumService
{
    /**
     * Build a query for media matching the album's smart rules.
     */
    public function buildQuery(Album $album, int $spaceId): Builder
    {
        $rules = is_string($album->smart_rules)
            ? json_decode($album->smart_rules, true)
            : $album->smart_rules;

        $q    = MediaItem::where('gallery_space_id', $spaceId)->whereNull('trashed_at')->where('is_hidden', false);
        $mode = ($rules['match'] ?? 'all') === 'any' ? 'or' : 'and';

        $conditions = $rules['conditions'] ?? [];

        if (empty($conditions)) {
            return $q->whereRaw('1 = 0'); // no rules = empty album
        }

        $q->where(function (Builder $query) use ($conditions, $mode) {
            foreach ($conditions as $cond) {
                $fn = fn(Builder $sub) => $this->applyCondition($sub, $cond);
                if ($mode === 'or') {
                    $query->orWhere($fn);
                } else {
                    $query->where($fn);
                }
            }
        });

        return $q;
    }

    /**
     * Count matching media (cheap check).
     */
    public function count(Album $album, int $spaceId): int
    {
        return $this->buildQuery($album, $spaceId)->count();
    }

    /**
     * Get paginated results.
     */
    public function paginate(Album $album, int $spaceId, int $perPage = 60, string $sort = 'taken_at', string $dir = 'desc')
    {
        return $this->buildQuery($album, $spaceId)
            ->orderBy($sort, $dir)
            ->paginate($perPage);
    }

    // ─── Condition applicators ─────────────────────────────────────────────

    private function applyCondition(Builder $q, array $cond): void
    {
        $field = $cond['field'] ?? null;
        $op    = $cond['op'] ?? 'eq';
        $value = $cond['value'] ?? null;

        match ($field) {
            'rating'      => $this->applyScalar($q, 'rating', $op, $value),
            'is_favorite' => $q->where('is_favorite', (bool) $value),
            'has_gps'     => $value ? $q->whereNotNull('latitude') : $q->whereNull('latitude'),
            'media_type'  => $q->where('media_type', $value),
            'date_from'   => $q->whereDate('taken_at', '>=', $value),
            'date_to'     => $q->whereDate('taken_at', '<=', $value),
            'taken_year'  => $q->whereYear('taken_at', $value),
            'camera_make' => $q->where('camera_make', 'like', "%{$value}%"),
            'min_width'   => $q->where('width', '>=', $value),
            'is_panorama' => $q->where('is_panorama', (bool) $value),
            'is_360'      => $q->where('is_360', (bool) $value),
            'is_raw'      => $q->where('is_raw', (bool) $value),
            'extension'   => is_array($value)
                ? $q->whereIn('extension', $value)
                : $q->where('extension', $value),
            'tag_id'      => $q->whereHas(
                'tags',
                fn($tq) =>
                is_array($value) ? $tq->whereIn('tags.id', $value) : $tq->where('tags.id', $value)
            ),
            'person_id'   => $q->whereHas(
                'people',
                fn($pq) =>
                is_array($value) ? $pq->whereIn('people.id', $value) : $pq->where('people.id', $value)
            ),
            default => null,
        };
    }

    private function applyScalar(Builder $q, string $col, string $op, mixed $value): void
    {
        match ($op) {
            'eq'  => $q->where($col, $value),
            'neq' => $q->where($col, '!=', $value),
            'gte' => $q->where($col, '>=', $value),
            'lte' => $q->where($col, '<=', $value),
            'gt'  => $q->where($col, '>', $value),
            'lt'  => $q->where($col, '<', $value),
            'in'  => $q->whereIn($col, (array) $value),
            default => null,
        };
    }

    // ─── Rule validation / labels ──────────────────────────────────────────

    public static function conditionLabel(array $cond): string
    {
        $field = $cond['field'] ?? '';
        $op    = $cond['op']    ?? 'eq';
        $value = $cond['value'] ?? '';

        $opLabel = match ($op) {
            'gte'  => '≥',
            'lte'  => '≤',
            'gt'   => '>',
            'lt'   => '<',
            'neq'  => '≠',
            'in'   => 'je v',
            default => '=',
        };

        $fieldLabel = match ($field) {
            'rating'      => 'Hodnocení',
            'is_favorite' => 'Oblíbené',
            'has_gps'     => 'Má GPS',
            'media_type'  => 'Typ',
            'date_from'   => 'Datum od',
            'date_to'     => 'Datum do',
            'taken_year'  => 'Rok',
            'camera_make' => 'Fotoaparát',
            'min_width'   => 'Min. šířka (px)',
            'is_panorama' => 'Panorama',
            'is_360'      => '360°',
            'is_raw'      => 'RAW',
            'extension'   => 'Formát',
            'tag_id'      => 'Tag',
            'person_id'   => 'Osoba',
            default       => $field,
        };

        if (is_bool($value)) return $fieldLabel . ' = ' . ($value ? 'ano' : 'ne');
        if (is_array($value)) return $fieldLabel . ' ' . $opLabel . ' [' . implode(', ', $value) . ']';
        return $fieldLabel . ' ' . $opLabel . ' ' . $value;
    }
}
