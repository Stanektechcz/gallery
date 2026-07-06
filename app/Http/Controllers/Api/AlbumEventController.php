<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AlbumEventController extends Controller
{
    /**
     * GET /api/v1/albums/{uuid}/event
     * Get event settings for an album.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $album = $this->resolve($uuid, $request);

        return response()->json($this->eventData($album));
    }

    /**
     * PATCH /api/v1/albums/{uuid}/event
     * Update event settings (start/end time, location, GPS).
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $album = $this->resolve($uuid, $request);

        $v = $request->validate([
            'event_mode'       => 'nullable|boolean',
            'event_start_at'   => 'nullable|date',
            'event_end_at'     => 'nullable|date|after_or_equal:event_start_at',
            'event_place_name' => 'nullable|string|max:255',
            'event_latitude'   => 'nullable|numeric|between:-90,90',
            'event_longitude'  => 'nullable|numeric|between:-180,180',
            'event_gps_radius' => 'nullable|integer|min:50|max:50000',
        ]);

        $album->update(array_filter($v, fn($val) => $val !== null));

        return response()->json($this->eventData($album->fresh()));
    }

    /**
     * GET /api/v1/albums/{uuid}/event-media
     * Detect media in the event time window (and GPS radius) not yet in album.
     * Returns counts + sample thumbnails for the offer banner.
     */
    public function detectMedia(Request $request, string $uuid): JsonResponse
    {
        $album = $this->resolve($uuid, $request);
        $space = $request->user()->gallerySpaces()->first();

        if (! $album->event_mode || ! $album->event_start_at || ! $album->event_end_at) {
            return response()->json(['count' => 0, 'photo_count' => 0, 'video_count' => 0, 'samples' => []]);
        }

        $q = $this->buildDetectionQuery($album, $space->id);
        $total      = $q->count();
        $photoCount = (clone $q)->where('media_type', 'photo')->count();
        $videoCount = (clone $q)->where('media_type', 'video')->count();

        $samples = MediaItem::with('variants')
            ->whereIn('id', (clone $q)->select('id')->limit(6)->pluck('id'))
            ->get()
            ->map(fn($m) => ['uuid' => $m->uuid, 'thumbnail_url' => $m->thumbnail_url]);

        return response()->json([
            'count'       => $total,
            'photo_count' => $photoCount,
            'video_count' => $videoCount,
            'samples'     => $samples,
        ]);
    }

    /**
     * POST /api/v1/albums/{uuid}/event-collect
     * Add all detected media into the album.
     */
    public function collect(Request $request, string $uuid): JsonResponse
    {
        $album = $this->resolve($uuid, $request);
        $space = $request->user()->gallerySpaces()->first();
        $user  = $request->user();

        if (! $album->event_mode || ! $album->event_start_at || ! $album->event_end_at) {
            return response()->json(['added' => 0]);
        }

        $mediaIds = $this->buildDetectionQuery($album, $space->id)->pluck('id');

        $now     = now();
        $added   = 0;
        foreach ($mediaIds as $mediaId) {
            $inserted = DB::table('album_media')->insertOrIgnore([
                'album_id'      => $album->id,
                'media_item_id' => $mediaId,
                'added_at'      => $now,
                'added_by'      => $user->id,
            ]);
            if ($inserted) {
                $added++;
            }
        }

        // Update media_count
        $album->update([
            'media_count' => DB::table('album_media')->where('album_id', $album->id)->count(),
        ]);

        return response()->json(['added' => $added]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function resolve(string $uuid, Request $request): Album
    {
        $space = $request->user()->gallerySpaces()->first();
        return Album::where('uuid', $uuid)
            ->where('gallery_space_id', $space->id)
            ->firstOrFail();
    }

    private function eventData(Album $album): array
    {
        return [
            'event_mode'       => (bool) $album->event_mode,
            'event_start_at'   => $album->event_start_at,
            'event_end_at'     => $album->event_end_at,
            'event_place_name' => $album->event_place_name,
            'event_latitude'   => $album->event_latitude,
            'event_longitude'  => $album->event_longitude,
            'event_gps_radius' => $album->event_gps_radius ?? 500,
        ];
    }

    private function buildDetectionQuery(Album $album, int $spaceId)
    {
        // Already in album
        $inAlbum = DB::table('album_media')
            ->where('album_id', $album->id)
            ->pluck('media_item_id');

        $q = MediaItem::where('gallery_space_id', $spaceId)
            ->whereNull('trashed_at')
            ->whereBetween('taken_at', [$album->event_start_at, $album->event_end_at])
            ->whereNotIn('id', $inAlbum);

        // GPS filter (only if album has event GPS and photos also have GPS)
        if ($album->event_latitude && $album->event_longitude) {
            $deg = ($album->event_gps_radius ?? 500) / 111000.0;
            $q->where(function ($sub) use ($album, $deg) {
                $sub->whereNull('latitude') // include non-GPS photos (time match is enough)
                    ->orWhere(function ($gps) use ($album, $deg) {
                        $gps->whereRaw('ABS(latitude  - ?) < ?', [$album->event_latitude,  $deg])
                            ->whereRaw('ABS(longitude - ?) < ?', [$album->event_longitude, $deg * 1.5]);
                    });
            });
        }

        return $q;
    }
}
