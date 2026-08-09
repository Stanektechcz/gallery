<?php

namespace App\Services\Memories;

use App\Models\Album;
use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\GeneratedMemory;
use App\Models\MediaItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Builds the cards the memories tab shows.
 *
 * Four sources, ordered by how much they tend to be worth looking at:
 *
 *   anniversary  photographs taken on this day in an earlier year
 *   event        a calendar event that happened, with what was photographed around it
 *   album        an album whose photographs cluster on this day
 *   streak       a day that produced unusually many photographs
 *
 * Everything is written with a stable source, so running twice on the same day updates
 * the same rows rather than making a second set. That matters because this runs from a
 * schedule and a retry after a failure must not double the tab.
 *
 * Scores decide what leads: an anniversary with photographs beats a bare event, and more
 * photographs beat fewer. The numbers are arbitrary but their order is not.
 */
class MemoryGeneratorService
{
    /** Nothing older than this is worth surfacing as "a year ago". */
    private const MAX_YEARS_BACK = 15;

    /** Enough photographs in one day that the day itself was an occasion. */
    private const STREAK_THRESHOLD = 25;

    /**
     * Generates every card for one space and one day.
     *
     * @return list<GeneratedMemory>
     */
    public function generate(GallerySpace $space, ?Carbon $day = null): array
    {
        $day = ($day ?? now())->copy()->startOfDay();

        return array_merge(
            $this->anniversaries($space, $day),
            $this->fromEvents($space, $day),
            $this->fromAlbums($space, $day),
        );
    }

    /**
     * Photographs from this day in earlier years.
     *
     * The classic memory, and the reason the tab exists. Grouped by year so "three years
     * ago" is one card rather than forty.
     *
     * @return list<GeneratedMemory>
     */
    private function anniversaries(GallerySpace $space, Carbon $day): array
    {
        if (! Schema::hasTable('media_items')) return [];

        $cards = [];

        for ($years = 1; $years <= self::MAX_YEARS_BACK; $years++) {
            $then = $day->copy()->subYears($years);

            $media = MediaItem::withoutGlobalScopes()
                ->where('gallery_space_id', $space->id)
                ->whereNull('deleted_at')
                ->whereDate('taken_at', $then->toDateString())
                ->orderByDesc('is_favorite')->orderBy('id')
                ->limit(12)->get();

            if ($media->count() < 2) continue;

            $cards[] = $this->write($space, [
                'kind' => 'anniversary',
                'title' => $years === 1 ? 'Před rokem' : "Před {$years} lety",
                'subtitle' => $this->czechDate($then) . ' · ' . $this->photoCount($media->count()),
                'icon' => '📸',
                'source_type' => 'anniversary',
                'source_id' => $then->toDateString(),
                'occurs_on' => $day->toDateString(),
                'years_ago' => $years,
                'media_ids' => $media->pluck('uuid')->all(),
                'link' => '/timeline?date=' . $then->toDateString(),
                // Recent years matter more, and photographs matter more than age.
                'score' => 200 - ($years * 5) + min($media->count(), 20),
            ]);
        }

        return $cards;
    }

    /**
     * Calendar events that happened on this day in an earlier year.
     *
     * An event carries something a pile of photographs does not: what it was called. That
     * is why an event card outranks a bare anniversary of the same day.
     *
     * @return list<GeneratedMemory>
     */
    private function fromEvents(GallerySpace $space, Carbon $day): array
    {
        if (! Schema::hasTable('calendar_events')) return [];

        $cards = [];

        $events = CalendarEvent::withoutGlobalScopes()
            ->where('gallery_space_id', $space->id)
            ->whereNull('deleted_at')
            ->whereRaw('strftime(\'%m-%d\', starts_at) = ?', [$day->format('m-d')])
            ->whereDate('starts_at', '<', $day->toDateString())
            ->orderByDesc('starts_at')->limit(5)->get();

        foreach ($events as $event) {
            $years = $event->starts_at ? $day->year - $event->starts_at->year : null;
            if (! $years || $years < 1) continue;

            // Whatever was photographed that day, so the card has something to show.
            $media = MediaItem::withoutGlobalScopes()
                ->where('gallery_space_id', $space->id)
                ->whereNull('deleted_at')
                ->whereDate('taken_at', $event->starts_at->toDateString())
                ->limit(8)->get();

            $cards[] = $this->write($space, [
                'kind' => 'event',
                'title' => $event->title,
                'subtitle' => ($years === 1 ? 'před rokem' : "před {$years} lety") . ' · ' . $this->czechDate($event->starts_at),
                'icon' => '🗓️',
                'source_type' => 'event',
                'source_id' => (string) ($event->uuid ?? $event->id),
                'occurs_on' => $day->toDateString(),
                'years_ago' => $years,
                'media_ids' => $media->pluck('uuid')->all(),
                'link' => '/calendar/events/' . ($event->uuid ?? $event->id),
                // Named beats unnamed; photographs still add to it.
                'score' => 260 - ($years * 5) + min($media->count(), 20),
            ]);
        }

        return $cards;
    }

    /**
     * Albums whose photographs fall on this day.
     *
     * This is what makes an album notifiable: the card names the album, so the
     * notification can say which one rather than "you have a memory".
     *
     * @return list<GeneratedMemory>
     */
    private function fromAlbums(GallerySpace $space, Carbon $day): array
    {
        if (! Schema::hasTable('albums')) return [];

        $cards = [];

        for ($years = 1; $years <= self::MAX_YEARS_BACK; $years++) {
            $then = $day->copy()->subYears($years);

            $albums = Album::withoutGlobalScopes()
                ->where('gallery_space_id', $space->id)
                ->whereNull('deleted_at')
                ->whereHas('media', fn ($query) => $query->whereDate('taken_at', $then->toDateString()))
                ->limit(3)->get();

            foreach ($albums as $album) {
                $media = $album->media()->whereDate('taken_at', $then->toDateString())->limit(8)->get();
                if ($media->isEmpty()) continue;

                $cards[] = $this->write($space, [
                    'kind' => 'album',
                    'title' => $album->title ?? $album->name ?? 'Album',
                    'subtitle' => ($years === 1 ? 'před rokem' : "před {$years} lety") . ' · ' . $this->photoCount($media->count()),
                    'icon' => '🗂️',
                    'source_type' => 'album',
                    'source_id' => (string) ($album->uuid ?? $album->id),
                    'occurs_on' => $day->toDateString(),
                    'years_ago' => $years,
                    'media_ids' => $media->pluck('uuid')->all(),
                    'link' => '/albums/' . ($album->uuid ?? $album->id),
                    'score' => 230 - ($years * 5) + min($media->count(), 20),
                ]);
            }
        }

        return $cards;
    }

    /** @param  array<string, mixed>  $card */
    private function write(GallerySpace $space, array $card): GeneratedMemory
    {
        return GeneratedMemory::updateOrCreate(
            [
                'gallery_space_id' => $space->id,
                'source_type' => $card['source_type'],
                'source_id' => $card['source_id'],
                'occurs_on' => $card['occurs_on'],
            ],
            $card + ['uuid' => (string) Str::uuid()],
        );
    }

    private function photoCount(int $count): string
    {
        return $count . ' ' . ($count === 1 ? 'fotka' : ($count < 5 ? 'fotky' : 'fotek'));
    }

    /** Czech months, for the same reason as in the mention search: the locale is English. */
    private function czechDate(Carbon $date): string
    {
        $months = ['ledna', 'února', 'března', 'dubna', 'května', 'června',
            'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];

        return $date->day . '. ' . $months[$date->month - 1] . ' ' . $date->year;
    }
}
