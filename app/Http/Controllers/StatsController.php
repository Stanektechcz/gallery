<?php

namespace App\Http\Controllers;

use App\Models\MediaItem;
use App\Models\Album;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StatsController extends Controller
{
    public function index(Request $request): Response
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        if (!$space) {
            return Inertia::render('Stats/Index', ['stats' => null]);
        }

        $base = MediaItem::where('gallery_space_id', $space->id)->whereNull('trashed_at');

        // Totals
        $total   = (clone $base)->count();
        $photos  = (clone $base)->where('media_type', 'photo')->count();
        $videos  = (clone $base)->where('media_type', 'video')->count();
        $withGps = (clone $base)->whereNotNull('latitude')->count();
        $totalSize = (clone $base)->sum('size_bytes');
        $albums  = Album::where('gallery_space_id', $space->id)->whereNull('deleted_at')->count();

        // Per year
        $perYear = (clone $base)
            ->whereNotNull('taken_at')
            ->selectRaw("YEAR(taken_at) as year, COUNT(*) as total, SUM(CASE WHEN media_type='photo' THEN 1 ELSE 0 END) as photos, SUM(CASE WHEN media_type='video' THEN 1 ELSE 0 END) as videos")
            ->groupByRaw("YEAR(taken_at)")
            ->orderByDesc('year')
            ->limit(10)
            ->get();

        // Per month (current year)
        $year = now()->year;
        $perMonth = (clone $base)
            ->whereYear('taken_at', $year)
            ->selectRaw("MONTH(taken_at) as month, COUNT(*) as total")
            ->groupByRaw("MONTH(taken_at)")
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        // Top cameras
        $cameras = (clone $base)
            ->whereNotNull('camera_model')
            ->selectRaw("camera_model, COUNT(*) as count")
            ->groupBy('camera_model')
            ->orderByDesc('count')
            ->limit(5)
            ->get();

        // Top formats
        $formats = (clone $base)
            ->whereNotNull('extension')
            ->selectRaw("UPPER(extension) as ext, COUNT(*) as count")
            ->groupBy('extension')
            ->orderByDesc('count')
            ->limit(8)
            ->get();

        // Favorites + archived
        $favorites = (clone $base)->where('is_favorite', true)->count();
        $archived  = MediaItem::where('gallery_space_id', $space->id)->where('is_archived', true)->count();

        return Inertia::render('Stats/Index', [
            'stats' => [
                'total'     => $total,
                'photos'    => $photos,
                'videos'    => $videos,
                'with_gps'  => $withGps,
                'total_size' => $totalSize,
                'albums'    => $albums,
                'favorites' => $favorites,
                'archived'  => $archived,
                'per_year'  => $perYear,
                'per_month' => array_values($perMonth->toArray()),
                'cameras'   => $cameras,
                'formats'   => $formats,
                'year'      => $year,
            ],
        ]);
    }
}
