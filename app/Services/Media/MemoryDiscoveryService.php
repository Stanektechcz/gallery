<?php

namespace App\Services\Media;

use App\Models\MediaItem;
use App\Models\MemoryInteraction;
use App\Models\MemoryPreference;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MemoryDiscoveryService
{
    public const TYPES = ['on_this_day', 'trip_anniversary', 'favorite_flashback', 'place_flashback', 'monthly_highlight'];

    public function discover(User $user): Collection
    {
        $space = $user->gallerySpaces()->first();
        if (! $space) {
            return collect();
        }

        $preferences = MemoryPreference::firstOrCreate(['user_id' => $user->id]);
        $enabledTypes = $preferences->enabled_types ?: self::TYPES;

        $cards = collect()
            ->concat(in_array('on_this_day', $enabledTypes, true) ? $this->onThisDay($space->id, $preferences) : [])
            ->concat(in_array('trip_anniversary', $enabledTypes, true) ? $this->tripAnniversaries($space->id) : [])
            ->concat(in_array('favorite_flashback', $enabledTypes, true) ? $this->favoriteFlashback($space->id, $preferences) : [])
            ->concat(in_array('place_flashback', $enabledTypes, true) ? $this->placeFlashback($space->id, $preferences) : [])
            ->concat(in_array('monthly_highlight', $enabledTypes, true) ? $this->monthlyHighlight($space->id, $preferences) : []);

        $hidden = MemoryInteraction::where('user_id', $user->id)
            ->where(function ($query) {
                $query->where('action', 'dismissed')
                    ->orWhere(fn ($snoozed) => $snoozed->where('action', 'snoozed')->where('snoozed_until', '>', now()));
            })
            ->pluck('fingerprint');

        return $cards
            ->reject(fn ($card) => $hidden->contains($card['fingerprint']))
            ->sortByDesc('score')
            ->values();
    }

    private function onThisDay(int $spaceId, MemoryPreference $preferences): array
    {
        $today = now();
        $cards = [];
        foreach (range(1, 10) as $offset) {
            $date = $today->copy()->subYears($offset);
            $items = $this->baseMedia($spaceId, $preferences)
                ->whereMonth('taken_at', $date->month)
                ->whereDay('taken_at', $date->day)
                ->orderByDesc('is_favorite')
                ->orderByDesc('rating')
                ->limit(16)
                ->get();
            if ($items->isEmpty()) {
                continue;
            }
            $cards[] = $this->card(
                'on_this_day',
                "Před {$offset} " . ($offset === 1 ? 'rokem' : ($offset < 5 ? 'lety' : 'lety')),
                $date->translatedFormat('j. F Y'),
                'Stejný den v minulosti',
                '🕰️',
                '#6c63ff',
                $items,
                "day:{$date->toDateString()}",
                100 - $offset
            );
        }
        return $cards;
    }

    private function tripAnniversaries(int $spaceId): array
    {
        $today = now();
        $trips = DB::table('trips')->where('gallery_space_id', $spaceId)->where('start_date', '<', $today->copy()->subYear())->get();
        $cards = [];
        foreach ($trips as $trip) {
            $start = Carbon::parse($trip->start_date);
            $anniversary = $start->copy()->year($today->year);
            if (abs($anniversary->diffInDays($today, false)) > 14) {
                continue;
            }
            $mediaIds = DB::table('trip_media')->where('trip_id', $trip->id)->pluck('media_item_id');
            $items = MediaItem::with(['variants' => fn ($query) => $query->whereIn('type', ['thumbnail', 'placeholder'])])
                ->whereIn('id', $mediaIds)->whereNull('trashed_at')->orderByDesc('is_favorite')->limit(16)->get();
            if ($items->isEmpty()) {
                continue;
            }
            $years = max(1, $start->diffInYears($today));
            $cards[] = $this->card('trip_anniversary', $trip->name, "Před {$years} lety", 'Výročí vaší cesty', '🗺️', '#14b8a6', $items, "trip:{$trip->id}:{$today->year}", 95);
        }
        return $cards;
    }

    private function favoriteFlashback(int $spaceId, MemoryPreference $preferences): array
    {
        $items = $this->baseMedia($spaceId, $preferences)
            ->where('is_favorite', true)
            ->where('taken_at', '<', now()->subMonths(3))
            ->orderByDesc('rating')
            ->orderBy('taken_at')
            ->limit(16)
            ->get();
        if ($items->isEmpty()) {
            return [];
        }
        return [$this->card('favorite_flashback', 'Oblíbené znovu', 'Výběr, který stojí za návrat', 'Vybráno z vašich oblíbených', '💜', '#ec4899', $items, 'favorites:' . now()->format('o-W'), 80)];
    }

    private function placeFlashback(int $spaceId, MemoryPreference $preferences): array
    {
        $hiddenPlaces = $preferences->hidden_place_ids ?: [];
        $place = DB::table('media_place')
            ->join('places', 'places.id', '=', 'media_place.place_id')
            ->join('media_items', 'media_items.id', '=', 'media_place.media_item_id')
            ->where('places.gallery_space_id', $spaceId)
            ->whereNull('media_items.trashed_at')
            ->when($hiddenPlaces, fn ($query) => $query->whereNotIn('places.id', $hiddenPlaces))
            ->where('media_items.taken_at', '<', now()->subMonths(6))
            ->select('places.id', 'places.name', DB::raw('COUNT(*) as media_count'))
            ->groupBy('places.id', 'places.name')
            ->orderByDesc('media_count')
            ->first();
        if (! $place) {
            return [];
        }
        $mediaIds = DB::table('media_place')->where('place_id', $place->id)->pluck('media_item_id');
        $items = $this->baseMedia($spaceId, $preferences)->whereIn('id', $mediaIds)->orderByDesc('rating')->limit(16)->get();
        if ($items->isEmpty()) {
            return [];
        }
        return [$this->card('place_flashback', "Zpátky na místě {$place->name}", "{$place->media_count} zachycených okamžiků", 'Místo, kam se vracíte ve vzpomínkách', '📍', '#f59e0b', $items, "place:{$place->id}:" . now()->format('Y-m'), 70)];
    }

    private function monthlyHighlight(int $spaceId, MemoryPreference $preferences): array
    {
        $date = now()->subYear();
        $items = $this->baseMedia($spaceId, $preferences)
            ->whereYear('taken_at', $date->year)
            ->whereMonth('taken_at', $date->month)
            ->orderByDesc('is_favorite')
            ->orderByDesc('rating')
            ->limit(16)
            ->get();
        if ($items->isEmpty()) {
            return [];
        }
        return [$this->card('monthly_highlight', $date->translatedFormat('F Y'), 'Měsíční výběr', 'Nejlepší momenty stejného měsíce', '✨', '#3b82f6', $items, "month:{$date->format('Y-m')}", 60)];
    }

    private function baseMedia(int $spaceId, MemoryPreference $preferences)
    {
        return MediaItem::query()
            ->with(['variants' => fn ($query) => $query->whereIn('type', ['thumbnail', 'placeholder'])])
            ->where('gallery_space_id', $spaceId)
            ->whereNull('trashed_at')
            ->where('is_hidden', false)
            ->where('status', 'ready')
            ->when(! $preferences->include_archived, fn ($query) => $query->where('is_archived', false))
            ->when($preferences->hidden_person_ids, fn ($query, $ids) => $query->whereDoesntHave('people', fn ($people) => $people->whereIn('people.id', $ids)));
    }

    private function card(string $type, string $title, string $subtitle, string $reason, string $icon, string $accent, Collection $items, string $key, int $score): array
    {
        return [
            'fingerprint' => hash('sha256', $type . '|' . $key),
            'type' => $type,
            'title' => $title,
            'subtitle' => $subtitle,
            'reason' => $reason,
            'icon' => $icon,
            'accent' => $accent,
            'score' => $score,
            'count' => $items->count(),
            'items' => $items->map(fn (MediaItem $item) => $this->formatItem($item))->values(),
        ];
    }

    private function formatItem(MediaItem $item): array
    {
        return [
            'id' => $item->id,
            'uuid' => $item->uuid,
            'media_type' => $item->media_type,
            'taken_at' => $item->taken_at?->toIso8601String(),
            'width' => $item->width,
            'height' => $item->height,
            'is_favorite' => $item->is_favorite,
            'variants' => $item->variants->map(fn ($variant) => [
                'type' => $variant->type,
                'url' => $variant->url,
                'dominant_color' => $variant->dominant_color,
                'aspect_ratio' => $variant->aspect_ratio,
            ])->values(),
        ];
    }
}

