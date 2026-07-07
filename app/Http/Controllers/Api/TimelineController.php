<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TimelineController extends Controller
{
    /**
     * GET /api/v1/timeline
     * Returns paginated media items grouped by date for virtual scroll timeline.
     */
    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $query = MediaItem::query()
            ->where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->where('is_archived', false)
            ->where('is_hidden', false)
            ->where('status', 'ready')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('media_stack_items')
                    ->whereColumn('media_stack_items.media_item_id', 'media_items.id')
                    ->where('media_stack_items.is_cover', false);
            })
            ->with([
                'variants'     => fn($q) => $q->whereIn('type', ['thumbnail', 'placeholder']),
                'primaryAlbum' => fn($q) => $q->select('id', 'uuid', 'title', 'slug', 'materialized_path', 'parent_id'),
                'stacks' => fn($q) => $q->withCount('items'),
            ])
            ->select([
                'id',
                'uuid',
                'media_type',
                'taken_at',
                'width',
                'height',
                'is_favorite',
                'rating',
                'primary_album_id',
            ]);

        // Apply filters
        if ($mediaType = $request->input('media_type')) {
            $query->where('media_type', $mediaType);
        }
        if ($albumId = $request->input('album_id')) {
            $query->where('primary_album_id', $albumId);
        }
        if ($dateFrom = $request->input('date_from')) {
            $query->where('taken_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->input('date_to')) {
            $query->where('taken_at', '<=', $dateTo);
        }
        if ($request->boolean('favorites_only')) {
            $query->where('is_favorite', true);
        }

        $query->orderBy('taken_at', 'desc')
            ->orderBy('id', 'desc');

        $perPage  = min((int) $request->input('per_page', 60), 200);
        $paginated = $query->cursorPaginate($perPage);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'next_cursor' => $paginated->nextCursor()?->encode(),
                'has_more'    => $paginated->hasMorePages(),
            ],
        ]);
    }

    /**
     * GET /api/v1/timeline/buckets
     * Returns year/month buckets for fast navigation.
     */
    public function buckets(Request $request): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $buckets = MediaItem::query()
            ->where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->where('is_archived', false)
            ->where('is_hidden', false)
            ->where('status', 'ready')
            ->whereNotNull('taken_at')
            ->selectRaw("YEAR(taken_at) as year, MONTH(taken_at) as month, COUNT(*) as count")
            ->groupByRaw("YEAR(taken_at), MONTH(taken_at)")
            ->orderByRaw("year DESC, month DESC")
            ->get();

        return response()->json(['buckets' => $buckets]);
    }

    /**
     * GET /api/v1/timeline/map
     * Returns GPS-tagged items for the map view.
     */
    public function mapPoints(Request $request): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $points = MediaItem::query()
            ->where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->where('is_hidden', false)
            ->where('status', 'ready')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->select(['id', 'uuid', 'latitude', 'longitude', 'taken_at', 'media_type', 'primary_album_id', 'original_filename'])
            ->with([
                'variants'     => fn($q) => $q->whereIn('type', ['thumbnail', 'placeholder']),
                'primaryAlbum' => fn($q) => $q->select('id', 'uuid', 'title'),
            ])
            ->limit(5000)
            ->get();

        // Albums with location set (wrapped in try-catch - requires 2026_07_06_130000 migration)
        $albums = collect();
        try {
            $albums = \App\Models\Album::where('gallery_space_id', $space->id)
                ->whereNull('deleted_at')
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->select(['id', 'uuid', 'title', 'latitude', 'longitude', 'location_name', 'location_country', 'event_date_start', 'event_date_end', 'color', 'icon', 'media_count'])
                ->with(['cover' => fn($q) => $q->with(['variants' => fn($q2) => $q2->where('type', 'thumbnail')])])
                ->get()
                ->map(fn($a) => [
                    'id'             => $a->id,
                    'uuid'           => $a->uuid,
                    'title'          => $a->title,
                    'latitude'       => $a->latitude,
                    'longitude'      => $a->longitude,
                    'location_name'  => $a->location_name,
                    'location_country' => $a->location_country,
                    'event_date_start' => $a->event_date_start?->toDateString(),
                    'event_date_end'   => $a->event_date_end?->toDateString(),
                    'color'          => $a->color,
                    'media_count'    => $a->media_count,
                    'cover_thumb'    => null, // URL set via getVariant which needs disk context
                ]);
        } catch (\Throwable) {
            // Migration not yet applied — return empty albums list
        }

        return response()->json(['points' => $points, 'albums' => $albums]);
    }

    /**
     * GET /api/v1/timeline/memories
     * Returns "memories" — items from same day in past years.
     */
    public function memories(Request $request): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $today = now();
        $month = $today->month;
        $day   = $today->day;

        $memories = MediaItem::query()
            ->where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->where('is_archived', false)
            ->where('is_hidden', false)
            ->where('status', 'ready')
            ->whereNotNull('taken_at')
            ->whereRaw("MONTH(taken_at) = ? AND DAY(taken_at) = ? AND YEAR(taken_at) < ?", [$month, $day, $today->year])
            ->with(['variants' => fn($q) => $q->where('type', 'thumbnail')])
            ->orderByRaw("YEAR(taken_at) DESC")
            ->limit(50)
            ->get();

        $grouped = $memories->groupBy(fn($m) => $m->taken_at->year);

        return response()->json([
            'date'     => $today->format('d.m.'),
            'memories' => $grouped,
        ]);
    }

    /**
     * GET /api/v1/timeline/calendar?year=2026&month=7
     * Returns per-day counts for calendar view.
     */
    public function calendar(Request $request): JsonResponse
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        $year  = (int) $request->input('year',  now()->year);
        $month = (int) $request->input('month', now()->month);

        $days = MediaItem::query()
            ->where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->where('is_archived', false)
            ->where('is_hidden', false)
            ->whereNotNull('taken_at')
            ->whereYear('taken_at',  $year)
            ->whereMonth('taken_at', $month)
            ->selectRaw("DAY(taken_at) as day,
                COUNT(*) as total,
                SUM(CASE WHEN media_type='photo' THEN 1 ELSE 0 END) as photos,
                SUM(CASE WHEN media_type='video' THEN 1 ELSE 0 END) as videos")
            ->groupByRaw("DAY(taken_at)")
            ->orderBy('day')
            ->get();

        // Attach one thumbnail per day
        $thumbs = [];
        foreach ($days as $d) {
            $item = MediaItem::where('gallery_space_id', $space->id)
                ->whereNull('trashed_at')
                ->where('is_hidden', false)
                ->whereYear('taken_at', $year)
                ->whereMonth('taken_at', $month)
                ->whereDay('taken_at', $d->day)
                ->with(['variants' => fn($q) => $q->where('type', 'thumbnail')])
                ->first(['id', 'uuid']);
            $thumbs[$d->day] = $item ? [
                'uuid' => $item->uuid,
                'thumb' => $item->variants->first()?->url,
            ] : null;
        }

        return response()->json([
            'year'  => $year,
            'month' => $month,
            'days'  => $days->map(fn($d) => array_merge($d->toArray(), ['thumb' => $thumbs[$d->day]])),
        ]);
    }
}
