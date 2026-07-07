<?php

namespace App\Services\Media;

use App\Models\MediaItem;
use App\Models\MediaStack;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AutoStackService
{
    public function candidates(int $spaceId, int $limit = 2000): Collection
    {
        $stackedIds = DB::table('media_stack_items')->pluck('media_item_id');
        $items = MediaItem::with(['variants' => fn ($query) => $query->where('type', 'thumbnail')])
            ->where('gallery_space_id', $spaceId)
            ->where('media_type', 'photo')
            ->where('status', 'ready')
            ->where('is_hidden', false)
            ->whereNull('trashed_at')
            ->whereNotNull('taken_at')
            ->whereNotIn('id', $stackedIds)
            ->orderBy('taken_at')
            ->limit($limit)
            ->get();

        $groups = collect();
        $current = collect();
        foreach ($items as $item) {
            if ($current->isEmpty() || $this->belongsTogether($current->last(), $item)) {
                $current->push($item);
                continue;
            }
            if ($current->count() >= 2) $groups->push($this->formatGroup($current));
            $current = collect([$item]);
        }
        if ($current->count() >= 2) $groups->push($this->formatGroup($current));

        return $groups->sortByDesc('confidence')->values();
    }

    public function apply(int $spaceId, int $userId, ?array $candidateKeys = null): Collection
    {
        $groups = $this->candidates($spaceId)
            ->when($candidateKeys, fn ($items) => $items->whereIn('key', $candidateKeys))
            ->take(100);

        return DB::transaction(function () use ($groups, $spaceId, $userId) {
            return $groups->map(function (array $group) use ($spaceId, $userId) {
                $ids = collect($group['items'])->pluck('id')->all();
                $alreadyStacked = DB::table('media_stack_items')->whereIn('media_item_id', $ids)->exists();
                if ($alreadyStacked) return null;

                $stack = MediaStack::create([
                    'gallery_space_id' => $spaceId,
                    'name' => $group['label'],
                    'stack_type' => $group['type'],
                    'confidence' => $group['confidence'],
                    'is_automatic' => true,
                    'cover_media_id' => $group['cover_id'],
                    'created_by' => $userId,
                ]);
                foreach ($ids as $order => $mediaId) {
                    DB::table('media_stack_items')->insert([
                        'media_stack_id' => $stack->id,
                        'media_item_id' => $mediaId,
                        'sort_order' => $order,
                        'is_cover' => $mediaId === $group['cover_id'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                return $stack;
            })->filter()->values();
        });
    }

    public function chooseCover(Collection $items): MediaItem
    {
        return $items->sortByDesc(fn (MediaItem $item) =>
            ($item->is_favorite ? 10000 : 0)
            + ((int) $item->rating * 1000)
            + (((int) $item->width * (int) $item->height) / 1000000)
            + ($item->is_raw ? 0 : 10)
        )->first();
    }

    private function belongsTogether(MediaItem $previous, MediaItem $current): bool
    {
        $sameBase = pathinfo($previous->original_filename, PATHINFO_FILENAME) === pathinfo($current->original_filename, PATHINFO_FILENAME);
        if ($sameBase && ($previous->is_raw || $current->is_raw || $previous->extension !== $current->extension)) return true;

        $seconds = abs($previous->taken_at->diffInSeconds($current->taken_at, false));
        if ($seconds > 3 || ! $previous->camera_model || $previous->camera_model !== $current->camera_model) return false;

        $previousRatio = $previous->height ? $previous->width / $previous->height : 0;
        $currentRatio = $current->height ? $current->width / $current->height : 0;
        return $previousRatio === 0 || $currentRatio === 0 || abs($previousRatio - $currentRatio) < 0.08;
    }

    private function formatGroup(Collection $items): array
    {
        $cover = $this->chooseCover($items);
        $rawPair = $items->contains('is_raw', true);
        $span = abs($items->first()->taken_at->diffInSeconds($items->last()->taken_at, false));
        $type = $rawPair ? 'raw_pair' : 'burst';

        return [
            'key' => hash('sha256', $items->pluck('id')->join(',')),
            'type' => $type,
            'label' => $rawPair ? 'RAW + upravená verze' : 'Série ' . $items->first()->taken_at->format('j.n. H:i'),
            'confidence' => $rawPair ? 1.0 : round(max(0.75, 0.98 - $span / 100), 4),
            'cover_id' => $cover->id,
            'items' => $items->map(fn (MediaItem $item) => [
                'id' => $item->id,
                'uuid' => $item->uuid,
                'filename' => $item->original_filename,
                'taken_at' => $item->taken_at?->toIso8601String(),
                'rating' => $item->rating,
                'is_favorite' => $item->is_favorite,
                'thumbnail_url' => $item->thumbnail_url,
            ])->values()->all(),
        ];
    }
}

