<?php

namespace App\Services\Planning;

use App\Models\Album;
use App\Models\CalendarEvent;
use App\Models\MediaItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Keeps one experience connected from the calendar through uploads, trips and
 * the final shared memory. It deliberately works inside existing albums and
 * calendar events instead of introducing another standalone media workspace.
 */
class ExperienceMediaService
{
    public function suggestions(CalendarEvent $event, int $limit = 48): Collection
    {
        $from = $event->starts_at->copy()->subHours(6);
        $to = ($event->ends_at ?? $event->starts_at)->copy()->addHours(12);
        $attachedIds = $event->attachments()->whereNotNull('media_item_id')->pluck('media_item_id');
        $tripMediaIds = $event->trip_id && Schema::hasTable('trip_media')
            ? DB::table('trip_media')->where('trip_id', $event->trip_id)->pluck('media_item_id')->flip()
            : collect();

        return MediaItem::query()
            ->where('gallery_space_id', $event->gallery_space_id)
            ->whereNull('trashed_at')
            ->where('is_hidden', false)
            ->ready()
            ->whereBetween('taken_at', [$from, $to])
            ->whereNotIn('id', $attachedIds)
            ->with('variants')
            ->orderBy('taken_at')
            ->limit(max($limit * 3, 96))
            ->get([
                'id', 'uuid', 'display_title', 'original_filename', 'media_type',
                'taken_at', 'latitude', 'longitude', 'primary_album_id',
            ])
            ->map(fn (MediaItem $media) => $this->scoreCandidate($event, $media, $tripMediaIds->has($media->id)))
            ->filter()
            ->sortByDesc(fn (array $candidate) => [$candidate['score'], $candidate['taken_at']])
            ->take($limit)
            ->values()
            ->map(fn (array $candidate) => collect($candidate)->except('score')->all());
    }

    public function ensureAlbum(CalendarEvent $event, int $actorId): Album
    {
        $album = DB::transaction(function () use ($event, $actorId) {
            $locked = CalendarEvent::query()->lockForUpdate()->findOrFail($event->id);
            $album = $locked->album_id
                ? Album::withTrashed()->whereKey($locked->album_id)->where('gallery_space_id', $locked->gallery_space_id)->first()
                : null;

            if (! $album && $locked->trip_id && Schema::hasColumn('albums', 'trip_id')) {
                $album = Album::withTrashed()
                    ->where('gallery_space_id', $locked->gallery_space_id)
                    ->where('trip_id', $locked->trip_id)
                    ->first();
            }

            if ($album?->trashed()) $album->restore();

            if ($album && $locked->trip_id && ! $album->trip_id) {
                $album->update(['trip_id' => $locked->trip_id, 'updated_by' => $actorId]);
            }

            if (! $album) {
                $albumData = [
                    'gallery_space_id' => $locked->gallery_space_id,
                    'title' => $locked->title,
                    'slug' => Str::slug($locked->title),
                    'description' => $locked->description,
                    'event_date_start' => $locked->starts_at->toDateString(),
                    'event_date_end' => ($locked->ends_at ?? $locked->starts_at)->toDateString(),
                    'event_mode' => true,
                    'event_start_at' => $locked->starts_at,
                    'event_end_at' => $locked->ends_at,
                    'event_place_name' => $locked->place_name,
                    'event_latitude' => $locked->latitude,
                    'event_longitude' => $locked->longitude,
                    'location_name' => $locked->place_name,
                    'latitude' => $locked->latitude,
                    'longitude' => $locked->longitude,
                    'visibility' => 'shared',
                    'inherit_permissions' => false,
                    'sort_mode' => 'date_taken',
                    'sort_direction' => 'asc',
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                    'sync_status' => 'pending',
                ];
                if (Schema::hasColumn('albums', 'trip_id')) $albumData['trip_id'] = $locked->trip_id;
                $album = Album::create($albumData);
                $album->rebuildPaths();
            }

            if ($locked->trip_id) {
                CalendarEvent::query()
                    ->where('gallery_space_id', $locked->gallery_space_id)
                    ->where('trip_id', $locked->trip_id)
                    ->update(['album_id' => $album->id, 'updated_at' => now()]);
            } elseif ((int) $locked->album_id !== (int) $album->id) {
                $locked->update(['album_id' => $album->id]);
            }

            $this->syncPermissions($album, $actorId);

            return $album;
        });

        $event->refresh();

        return $album->fresh();
    }

    public function sync(CalendarEvent $event, int $actorId, Collection $media): Album
    {
        $album = $this->ensureAlbum($event, $actorId);
        $media = $media
            ->filter(fn ($item) => $item instanceof MediaItem && (int) $item->gallery_space_id === (int) $event->gallery_space_id && ! $item->trashed_at)
            ->unique('id')
            ->values();

        DB::transaction(function () use ($event, $album, $actorId, $media) {
            $nextOrder = ((int) DB::table('album_media')->where('album_id', $album->id)->max('sort_order')) + 1;

            foreach ($media as $item) {
                $event->attachments()->firstOrCreate(['media_item_id' => $item->id], ['kind' => 'memory']);
                $inserted = DB::table('album_media')->insertOrIgnore([
                    'album_id' => $album->id,
                    'media_item_id' => $item->id,
                    'sort_order' => $nextOrder,
                    'is_cover' => false,
                    'added_at' => now(),
                    'added_by' => $actorId,
                ]);
                if ($inserted) $nextOrder++;

                if ($event->trip_id && Schema::hasTable('trip_media')) {
                    DB::table('trip_media')->insertOrIgnore([
                        'trip_id' => $event->trip_id,
                        'media_item_id' => $item->id,
                        'added_at' => now(),
                    ]);
                }
            }

            if ($media->isNotEmpty()) {
                MediaItem::query()
                    ->whereIn('id', $media->pluck('id'))
                    ->whereNull('primary_album_id')
                    ->update(['primary_album_id' => $album->id]);
            }

            $stats = DB::table('album_media')
                ->join('media_items', 'media_items.id', '=', 'album_media.media_item_id')
                ->where('album_media.album_id', $album->id)
                ->selectRaw('COUNT(*) as media_count, COALESCE(SUM(media_items.size_bytes), 0) as total_size_bytes, MIN(media_items.id) as first_media_id')
                ->first();

            $album->update([
                'cover_media_id' => $album->cover_media_id ?: $stats?->first_media_id,
                'media_count' => (int) ($stats?->media_count ?? 0),
                'total_size_bytes' => (int) ($stats?->total_size_bytes ?? 0),
                'updated_by' => $actorId,
            ]);
        });

        return $album->fresh();
    }

    public function payload(CalendarEvent $event, Album $album): array
    {
        return [
            'album' => ['id' => $album->id, 'uuid' => $album->uuid, 'title' => $album->title],
            'attached_media_count' => $event->attachments()->whereNotNull('media_item_id')->count(),
            'trip_id' => $event->trip_id,
        ];
    }

    private function syncPermissions(Album $album, int $actorId): void
    {
        $memberIds = DB::table('gallery_space_user')
            ->where('gallery_space_id', $album->gallery_space_id)
            ->pluck('user_id')
            ->push($actorId)
            ->unique();
        $rows = $memberIds->map(fn ($userId) => [
            'album_id' => $album->id,
            'user_id' => $userId,
            'role' => 'editor',
            'inherited' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();
        if ($rows) {
            DB::table('album_user_permissions')->upsert(
                $rows,
                ['album_id', 'user_id'],
                ['role', 'inherited', 'updated_at'],
            );
        }
    }

    private function scoreCandidate(CalendarEvent $event, MediaItem $media, bool $alreadyInTrip): ?array
    {
        $eventEnd = $event->ends_at ?? $event->starts_at;
        $exactTime = $media->taken_at->betweenIncluded($event->starts_at, $eventEnd);
        $hoursFromEvent = $exactTime
            ? 0.0
            : min(
                abs($media->taken_at->floatDiffInHours($event->starts_at)),
                abs($media->taken_at->floatDiffInHours($eventEnd)),
            );
        $score = $exactTime ? 75 : max(25, 60 - ($hoursFromEvent * 4));
        $reasons = [$exactTime ? 'pořízeno během akce' : 'časově blízko akci'];
        $distance = null;

        if ($event->latitude !== null && $event->longitude !== null && $media->latitude !== null && $media->longitude !== null) {
            $distance = $this->distanceKm(
                (float) $event->latitude,
                (float) $event->longitude,
                (float) $media->latitude,
                (float) $media->longitude,
            );
            if ($distance > 75) return null;
            if ($distance <= 2) {
                $score += 25;
                $reasons[] = 'stejné místo';
            } elseif ($distance <= 15) {
                $score += 15;
                $reasons[] = 'blízko místa akce';
            } else {
                $reasons[] = 'v širším okolí';
            }
        } elseif ($event->latitude !== null && $event->longitude !== null) {
            $reasons[] = 'bez GPS, spárováno podle času';
        }

        if ($alreadyInTrip) {
            $score += 20;
            $reasons[] = 'už patří k této cestě';
        }

        return [
            'id' => $media->id,
            'uuid' => $media->uuid,
            'display_title' => $media->display_title,
            'original_filename' => $media->original_filename,
            'media_type' => $media->media_type,
            'taken_at' => $media->taken_at?->toIso8601String(),
            'thumbnail_url' => $media->thumbnail_url,
            'confidence' => $score >= 85 ? 'high' : ($score >= 60 ? 'medium' : 'low'),
            'match_reasons' => $reasons,
            'distance_km' => $distance === null ? null : round($distance, 1),
            'score' => round($score, 2),
        ];
    }

    private function distanceKm(float $latA, float $lngA, float $latB, float $lngB): float
    {
        $earthRadius = 6371;
        $latDelta = deg2rad($latB - $latA);
        $lngDelta = deg2rad($lngB - $lngA);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($lngDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
