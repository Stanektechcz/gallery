<?php

namespace App\Services\Media;

use App\Jobs\Drive\CreateDriveFolderJob;
use App\Models\Album;
use App\Models\AlbumSuggestionDecision;
use App\Models\AuditLog;
use App\Models\CalendarEvent;
use App\Models\EventAttachment;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\Place;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class UnassignedAlbumSuggestionService
{
    private const MIN_ITEMS = 3;
    private const MAX_ITEMS = 200;
    private const SESSION_GAP_HOURS = 8;

    public function available(): bool
    {
        return Schema::hasTable('album_suggestion_decisions')
            && Schema::hasColumn('shared_memory_moments', 'album_id');
    }

    /** @return array<int,array<string,mixed>> */
    public function suggestions(GallerySpace $space, User $viewer, int $limit = 5): array
    {
        if (! $this->available()) return [];

        $media = $this->candidateQuery($space)->with('variants')->limit(800)->get()
            ->sortBy(fn (MediaItem $item) => $this->moment($item)->timestamp)->values();
        if ($media->count() < self::MIN_ITEMS) return [];

        $ignored = AlbumSuggestionDecision::where('gallery_space_id', $space->id)->pluck('fingerprint')->all();

        return $this->clusters($media)
            ->filter(fn (Collection $cluster) => $cluster->count() >= self::MIN_ITEMS)
            ->map(fn (Collection $cluster) => $this->payload($space, $cluster))
            ->reject(fn (array $suggestion) => in_array($suggestion['fingerprint'], $ignored, true))
            ->sortByDesc(fn (array $suggestion) => strtotime($suggestion['ends_at']))
            ->take(max(1, min(10, $limit)))->values()->all();
    }

    /** Lightweight dashboard prompt without loading hundreds of media previews. */
    public function prompt(GallerySpace $space, User $viewer): ?array
    {
        if (! $this->available()) return null;
        $media = $this->candidateQuery($space)->limit(400)->get()
            ->sortBy(fn (MediaItem $item) => $this->moment($item)->timestamp)->values();
        if ($media->count() < self::MIN_ITEMS) return null;
        $ignored = AlbumSuggestionDecision::where('gallery_space_id', $space->id)->pluck('fingerprint')->all();
        foreach ($this->clusters($media)->reverse() as $cluster) {
            if ($cluster->count() < self::MIN_ITEMS) continue;
            $fingerprint = $this->fingerprint($space, $cluster);
            if (in_array($fingerprint, $ignored, true)) continue;
            return collect($this->payload($space, $cluster, false))
                ->only(['fingerprint', 'title', 'reason', 'media_count', 'photo_count', 'video_count', 'context'])->all();
        }
        return null;
    }

    public function find(GallerySpace $space, User $viewer, string $fingerprint): ?array
    {
        return collect($this->suggestions($space, $viewer, 10))->firstWhere('fingerprint', $fingerprint);
    }

    public function decision(GallerySpace $space, string $fingerprint): ?AlbumSuggestionDecision
    {
        return AlbumSuggestionDecision::with('album')->where('gallery_space_id', $space->id)
            ->where('fingerprint', $fingerprint)->first();
    }

    /** @return array<string,mixed> */
    public function accept(GallerySpace $space, User $user, array $suggestion, array $data): array
    {
        $available = collect($suggestion['media'])->keyBy('uuid');
        $requested = collect($data['media_uuids'])->unique()->values();
        abort_unless($requested->count() === count($data['media_uuids']) && $requested->every(fn ($uuid) => $available->has($uuid)), 422,
            'Album může obsahovat pouze média z tohoto aktuálního návrhu.');

        $media = MediaItem::query()->where('gallery_space_id', $space->id)->whereIn('uuid', $requested)
            ->whereNull('trashed_at')->where('is_hidden', false)->with('variants')->get()->keyBy('uuid');
        abort_unless($media->count() === $requested->count(), 422, 'Některé vybrané médium už není dostupné.');
        $ordered = $requested->map(fn ($uuid) => $media->get($uuid));

        $coverUuid = $data['cover_media_uuid'] ?? null;
        abort_if($coverUuid && ! $requested->contains($coverUuid), 422, 'Titulní médium musí být součástí výběru.');
        $cover = $coverUuid ? $media->get($coverUuid) : $ordered->sortByDesc(fn (MediaItem $item) => $this->qualityScore($item))->first();
        $created = false;
        $storyCreated = false;

        [$album, $memory] = DB::transaction(function () use ($space, $user, $suggestion, $data, $ordered, $cover, &$created, &$storyCreated) {
            GallerySpace::whereKey($space->id)->lockForUpdate()->firstOrFail();
            abort_if(AlbumSuggestionDecision::where('gallery_space_id', $space->id)->where('fingerprint', $suggestion['fingerprint'])->exists(), 409,
                'O tomto návrhu už bylo rozhodnuto.');

            $targetId = data_get($suggestion, 'target_album.id');
            $album = $targetId ? Album::whereKey($targetId)->where('gallery_space_id', $space->id)->first() : null;
            $created = ! $album;
            $title = trim((string) ($data['title'] ?? '')) ?: $suggestion['title'];
            $description = trim((string) ($data['description'] ?? '')) ?: $suggestion['description'];
            $start = Carbon::parse($suggestion['starts_at']);
            $end = Carbon::parse($suggestion['ends_at']);

            if (! $album) {
                $album = Album::create([
                    'gallery_space_id' => $space->id, 'created_by' => $user->id, 'updated_by' => $user->id,
                    'title' => $title, 'slug' => Str::slug($title . '-' . $start->format('Ymd')),
                    'description' => $description, 'cover_media_id' => $cover->id,
                    'event_date_start' => $start->toDateString(), 'event_date_end' => $end->toDateString(),
                    'story_mode' => true, 'event_mode' => true, 'event_start_at' => $start, 'event_end_at' => $end,
                    'event_place_name' => data_get($suggestion, 'place.name'),
                    'event_latitude' => data_get($suggestion, 'place.latitude'), 'event_longitude' => data_get($suggestion, 'place.longitude'),
                    'default_place_id' => data_get($suggestion, 'place.id'), 'location_name' => data_get($suggestion, 'place.name'),
                    'latitude' => data_get($suggestion, 'place.latitude'), 'longitude' => data_get($suggestion, 'place.longitude'),
                    'visibility' => 'shared', 'sort_mode' => 'date_taken', 'sort_direction' => 'asc',
                    'sync_status' => 'pending', 'album_type' => 'physical', 'icon' => '✨', 'color' => '#8b5cf6',
                ]);
                $album->rebuildPaths();
            } else {
                $album->update(['updated_by' => $user->id, 'sync_status' => 'pending']);
            }

            $maxSort = (int) (DB::table('album_media')->where('album_id', $album->id)->max('sort_order') ?? -1);
            foreach ($ordered as $index => $item) {
                DB::table('album_media')->insertOrIgnore([
                    'album_id' => $album->id, 'media_item_id' => $item->id, 'sort_order' => $maxSort + $index + 1,
                    'is_cover' => $item->id === $cover->id, 'added_at' => now(), 'added_by' => $user->id,
                ]);
            }
            MediaItem::whereIn('id', $ordered->pluck('id'))->whereNull('primary_album_id')->update(['primary_album_id' => $album->id]);
            if ($created || ! $album->cover_media_id) $album->update(['cover_media_id' => $cover->id]);
            DB::table('album_media')->where('album_id', $album->id)->update(['is_cover' => false]);
            DB::table('album_media')->where('album_id', $album->id)->where('media_item_id', $album->cover_media_id)->update(['is_cover' => true]);
            $album->update([
                'media_count' => DB::table('album_media')->where('album_id', $album->id)->count(),
                'total_size_bytes' => MediaItem::whereIn('id', DB::table('album_media')->where('album_id', $album->id)->pluck('media_item_id'))->sum('size_bytes'),
            ]);
            $this->permissions($space, $album);
            $this->linkContext($album, $ordered, $suggestion, $user);

            $storyCreated = DB::table('album_story_blocks')->where('album_id', $album->id)->doesntExist();
            if ($storyCreated) $this->createStory($album, $ordered, $user->id, $suggestion, $description);
            $memory = ($data['create_memory'] ?? true) ? $this->memory($space, $album, $ordered, $suggestion, $user, $description) : null;

            AlbumSuggestionDecision::create([
                'gallery_space_id' => $space->id, 'decided_by' => $user->id,
                'fingerprint' => $suggestion['fingerprint'], 'action' => 'accepted', 'album_id' => $album->id,
                'metadata' => ['media_count' => $ordered->count(), 'context' => $suggestion['context'] ?? null],
            ]);
            AuditLog::record($created ? 'album.suggestion.create' : 'album.suggestion.merge', $album, [
                'fingerprint' => $suggestion['fingerprint'], 'media_count' => $ordered->count(), 'context' => $suggestion['context'] ?? null,
            ]);

            return [$album->fresh('cover.variants'), $memory];
        });

        if ($created) CreateDriveFolderJob::dispatch($album);

        return [
            'album' => ['uuid' => $album->uuid, 'title' => $album->title, 'media_count' => (int) $album->media_count,
                'cover_url' => $album->cover?->thumbnail_url],
            'memory' => $memory ? ['uuid' => $memory->uuid, 'title' => $memory->title] : null,
            'created' => $created, 'story_created' => $storyCreated,
        ];
    }

    public function dismiss(GallerySpace $space, User $user, array $suggestion): void
    {
        AlbumSuggestionDecision::create([
            'gallery_space_id' => $space->id, 'decided_by' => $user->id,
            'fingerprint' => $suggestion['fingerprint'], 'action' => 'dismissed',
            'metadata' => ['media_count' => $suggestion['media_count'], 'context' => $suggestion['context'] ?? null],
        ]);
        AuditLog::record('album.suggestion.dismiss', null, ['fingerprint' => $suggestion['fingerprint']]);
    }

    private function candidateQuery(GallerySpace $space)
    {
        return MediaItem::query()->where('gallery_space_id', $space->id)
            ->whereNull('primary_album_id')->whereDoesntHave('albums')->whereNull('trashed_at')
            ->where('is_hidden', false)->whereIn('status', ['ready', 'received'])
            ->whereIn('media_type', ['photo', 'video'])->where(fn ($query) => $query->whereNotNull('taken_at')->orWhereNotNull('uploaded_at'))
            ->orderByDesc(DB::raw('COALESCE(taken_at, uploaded_at)'));
    }

    /** @return Collection<int,Collection<int,MediaItem>> */
    private function clusters(Collection $media): Collection
    {
        $clusters = collect();
        $current = collect();
        foreach ($media as $item) {
            $previous = $current->last();
            $newSession = $previous && $this->moment($previous)->diffInHours($this->moment($item)) > self::SESSION_GAP_HOURS;
            if (! $newSession && $previous?->hasGps() && $item->hasGps()) {
                $newSession = $this->distanceKm($previous->latitude, $previous->longitude, $item->latitude, $item->longitude) > 75
                    && $this->moment($previous)->diffInHours($this->moment($item)) > 2;
            }
            if ($newSession) { $clusters->push($current); $current = collect(); }
            $current->push($item);
        }
        if ($current->isNotEmpty()) $clusters->push($current);
        return $clusters;
    }

    private function payload(GallerySpace $space, Collection $cluster, bool $includeMedia = true): array
    {
        $start = $this->moment($cluster->first());
        $end = $this->moment($cluster->last());
        $context = $this->context($space, $cluster, $start, $end);
        $place = $this->place($space, $cluster);
        $context ??= $place ? ['type' => 'place', 'id' => $place->id, 'name' => $place->name, 'uuid' => null] : null;
        $title = $context['name'] ?? ($start->isSameDay($end)
            ? 'Vzpomínky · ' . $start->locale('cs')->translatedFormat('j. F Y')
            : 'Vzpomínky · ' . $start->locale('cs')->translatedFormat('j. F') . ' – ' . $end->locale('cs')->translatedFormat('j. F Y'));
        $reason = match ($context['type'] ?? null) {
            'event' => 'Čas pořízení odpovídá společné události v kalendáři.',
            'trip' => 'Média vznikla během naplánované cesty.',
            'place' => 'Média spojuje stejné uložené místo.',
            default => 'Fotografie a videa vznikla v jednom souvislém časovém úseku.',
        };
        $target = null;
        if (! empty($context['album_id'])) {
            $targetAlbum = Album::whereKey($context['album_id'])->where('gallery_space_id', $space->id)->first(['id', 'uuid', 'title']);
            if ($targetAlbum) $target = ['id' => $targetAlbum->id, 'uuid' => $targetAlbum->uuid, 'title' => $targetAlbum->title];
        }
        $ordered = $cluster->take(self::MAX_ITEMS)->values();
        $fingerprint = $this->fingerprint($space, $ordered);
        $items = $includeMedia ? $ordered->map(fn (MediaItem $item) => $this->mediaPayload($item))->all() : [];

        return [
            'fingerprint' => $fingerprint, 'title' => $target['title'] ?? $title,
            'description' => 'Společný výběr z ' . $start->locale('cs')->translatedFormat('j. F Y') . ($start->isSameDay($end) ? '.' : ' až ' . $end->locale('cs')->translatedFormat('j. F Y') . '.'),
            'reason' => $reason, 'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String(),
            'media_count' => $ordered->count(), 'photo_count' => $ordered->where('media_type', 'photo')->count(),
            'video_count' => $ordered->where('media_type', 'video')->count(), 'context' => $context,
            'target_album' => $target, 'place' => $place ? ['id' => $place->id, 'name' => $place->name, 'latitude' => $place->latitude, 'longitude' => $place->longitude] : null,
            'media' => $items, 'preview' => collect($items)->sortByDesc(fn ($item) => $item['score'])->take(5)->values()->all(),
        ];
    }

    private function fingerprint(GallerySpace $space, Collection $cluster): string
    {
        return hash('sha256', $space->id . ':' . $cluster->take(self::MAX_ITEMS)->pluck('id')->sort()->implode(','));
    }

    private function context(GallerySpace $space, Collection $cluster, Carbon $start, Carbon $end): ?array
    {
        $event = CalendarEvent::query()->where('gallery_space_id', $space->id)->where('is_private', false)
            ->whereBetween('starts_at', [$start->copy()->subDay(), $end->copy()->addDay()])->get()
            ->sortBy(function (CalendarEvent $event) use ($start, $end) {
                $eventEnd = $event->ends_at ?? $event->starts_at->copy()->addHours(3);
                $overlaps = $start->lte($eventEnd->copy()->addHours(8)) && $end->gte($event->starts_at->copy()->subHours(6));
                return $overlaps ? abs($event->starts_at->diffInMinutes($start)) : PHP_INT_MAX;
            })->first(fn (CalendarEvent $event) => $start->lte(($event->ends_at ?? $event->starts_at->copy()->addHours(3))->copy()->addHours(8))
                && $end->gte($event->starts_at->copy()->subHours(6)));
        if ($event) return ['type' => 'event', 'id' => $event->id, 'uuid' => $event->uuid, 'name' => $event->title,
            'album_id' => $event->album_id, 'trip_id' => $event->trip_id];

        $trip = DB::table('trips')->where('gallery_space_id', $space->id)
            ->where('start_date', '<=', $end->toDateString())->where('end_date', '>=', $start->toDateString())
            ->orderBy('start_date')->first(['id', 'name']);
        if ($trip) return ['type' => 'trip', 'id' => $trip->id, 'uuid' => null, 'name' => $trip->name,
            'album_id' => Album::where('gallery_space_id', $space->id)->where('trip_id', $trip->id)->value('id'), 'trip_id' => $trip->id];
        return null;
    }

    private function place(GallerySpace $space, Collection $cluster): ?Place
    {
        $linked = DB::table('media_place')->whereIn('media_item_id', $cluster->pluck('id'))
            ->selectRaw('place_id, COUNT(*) as uses')->groupBy('place_id')->orderByDesc('uses')->first();
        if ($linked) return Place::whereKey($linked->place_id)->where('gallery_space_id', $space->id)->first();
        $gps = $cluster->filter->hasGps();
        if ($gps->isEmpty()) return null;
        $lat = (float) $gps->avg('latitude'); $lng = (float) $gps->avg('longitude');
        return Place::where('gallery_space_id', $space->id)->whereNotNull('latitude')->whereNotNull('longitude')->get()
            ->map(function (Place $place) use ($lat, $lng) { $place->distance_km = $this->distanceKm($lat, $lng, $place->latitude, $place->longitude); return $place; })
            ->filter(fn (Place $place) => $place->distance_km <= max(3, ((int) $place->radius_meters) / 1000))->sortBy('distance_km')->first();
    }

    private function linkContext(Album $album, Collection $media, array $suggestion, User $user): void
    {
        $context = $suggestion['context'] ?? [];
        if (($context['type'] ?? null) === 'event') {
            $event = CalendarEvent::whereKey($context['id'])->where('gallery_space_id', $album->gallery_space_id)->first();
            if ($event) {
                if (! $event->album_id) $event->update(['album_id' => $album->id]);
                foreach ($media as $item) EventAttachment::firstOrCreate(['event_id' => $event->id, 'media_item_id' => $item->id], ['kind' => 'memory']);
            }
        }
        $tripId = $context['trip_id'] ?? (($context['type'] ?? null) === 'trip' ? $context['id'] : null);
        if ($tripId) foreach ($media as $item) DB::table('trip_media')->insertOrIgnore(['trip_id' => $tripId, 'media_item_id' => $item->id, 'added_at' => now()]);
        if ($placeId = data_get($suggestion, 'place.id')) {
            DB::table('album_place')->insertOrIgnore(['album_id' => $album->id, 'place_id' => $placeId, 'is_primary' => true, 'created_at' => now()]);
            foreach ($media as $item) DB::table('media_place')->insertOrIgnore(['media_item_id' => $item->id, 'place_id' => $placeId, 'is_primary' => false, 'created_at' => now()]);
        }
    }

    private function permissions(GallerySpace $space, Album $album): void
    {
        $userIds = DB::table('gallery_space_user')->where('gallery_space_id', $space->id)->pluck('user_id')->push($space->owner_id)->unique();
        $rows = $userIds->map(fn ($id) => ['album_id' => $album->id, 'user_id' => $id, 'role' => 'editor', 'inherited' => false, 'created_at' => now(), 'updated_at' => now()])->all();
        if ($rows) DB::table('album_user_permissions')->upsert($rows, ['album_id', 'user_id'], ['role', 'inherited', 'updated_at']);
    }

    private function createStory(Album $album, Collection $media, int $userId, array $suggestion, string $description): void
    {
        $blocks = [];
        $push = function (string $type, array $content) use (&$blocks, $album, $userId) {
            $blocks[] = ['album_id' => $album->id, 'created_by' => $userId, 'type' => $type,
                'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sort_order' => count($blocks), 'created_at' => now(), 'updated_at' => now()];
        };
        $push('heading', ['text' => $album->title, 'level' => 1]);
        $push('text', ['body' => $description . "\n\n" . $suggestion['reason']]);
        if ($suggestion['place'] ?? null) $push('map', ['latitude' => $suggestion['place']['latitude'], 'longitude' => $suggestion['place']['longitude'], 'label' => $suggestion['place']['name']]);
        $photos = $media->where('media_type', 'photo')->pluck('uuid')->values()->all();
        if ($photos) $push('photo', ['media_uuids' => $photos, 'layout' => count($photos) > 1 ? 'grid' : 'full']);
        foreach ($media->where('media_type', 'video') as $video) $push('video', ['media_uuid' => $video->uuid]);
        DB::table('album_story_blocks')->insert($blocks);
    }

    private function memory(GallerySpace $space, Album $album, Collection $media, array $suggestion, User $user, string $note): object
    {
        $eventId = ($suggestion['context']['type'] ?? null) === 'event' ? $suggestion['context']['id'] : null;
        $memory = DB::table('shared_memory_moments')->where('album_id', $album->id)->when($eventId, fn ($q) => $q->orWhere('calendar_event_id', $eventId))->first();
        $values = ['album_id' => $album->id, 'title' => $album->title, 'note' => $note,
            'happened_on' => Carbon::parse($suggestion['starts_at'])->toDateString(),
            'media_item_ids' => json_encode($media->take(30)->pluck('id')->values()->all()), 'updated_at' => now()];
        if ($memory) DB::table('shared_memory_moments')->where('id', $memory->id)->update($values);
        else DB::table('shared_memory_moments')->insert($values + ['uuid' => (string) Str::uuid(), 'gallery_space_id' => $space->id,
            'created_by' => $user->id, 'calendar_event_id' => $eventId, 'is_favorite' => false, 'created_at' => now()]);
        return DB::table('shared_memory_moments')->where('album_id', $album->id)->firstOrFail();
    }

    private function mediaPayload(MediaItem $item): array
    {
        $variant = collect(['thumbnail', 'small', 'video_poster', 'medium', 'placeholder'])
            ->map(fn ($type) => $item->variants->firstWhere('type', $type))->first();
        return ['uuid' => $item->uuid, 'title' => $item->display_title ?: $item->original_filename, 'media_type' => $item->media_type,
            'thumbnail_url' => $variant?->url, 'taken_at' => $this->moment($item)->toIso8601String(),
            'is_favorite' => (bool) $item->is_favorite, 'rating' => $item->rating, 'score' => $this->qualityScore($item)];
    }

    private function qualityScore(MediaItem $item): int
    {
        return ($item->is_favorite ? 100000 : 0) + ((int) $item->rating * 10000) + ($item->media_type === 'photo' ? 1000 : 500)
            + min(999, (int) floor(((int) $item->width * (int) $item->height) / 100000));
    }

    private function moment(MediaItem $item): Carbon { return $item->taken_at ?? $item->uploaded_at ?? $item->created_at; }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $latDelta = deg2rad($lat2 - $lat1); $lngDelta = deg2rad($lng2 - $lng1);
        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;
        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
