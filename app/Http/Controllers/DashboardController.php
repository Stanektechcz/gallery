<?php

namespace App\Http\Controllers;

use App\Models\MediaItem;
use App\Models\Album;
use App\Models\GallerySpace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user  = $request->user();
        $space = $user->gallerySpaces()->first();

        if (!$space) {
            return Inertia::render('Dashboard/Index', ['data' => null]);
        }

        $now   = now();
        $hour  = $now->hour;
        $name  = $user->name;

        $greeting = match (true) {
            $hour < 5  => "Dobrou noc",
            $hour < 12 => "Dobré ráno",
            $hour < 17 => "Dobré odpoledne",
            $hour < 21 => "Dobrý večer",
            default    => "Dobrou noc",
        };

        // This day last year
        $lastYearStart = $now->copy()->subYear()->startOfDay();
        $lastYearEnd   = $now->copy()->subYear()->endOfDay();
        $thisTimeLastYear = MediaItem::where('gallery_space_id', $space->id)
            ->whereBetween('taken_at', [$lastYearStart, $lastYearEnd])
            ->whereNull('trashed_at')
            ->with(['variants' => fn($q) => $q->whereIn('type', ['thumbnail', 'placeholder'])])
            ->orderByRaw('RAND()')
            ->limit(8)
            ->get();

        // Random memory (any time, random)
        $randomMemory = MediaItem::where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->where('status', 'ready')
            ->with(['variants' => fn($q) => $q->whereIn('type', ['thumbnail', 'placeholder'])])
            ->orderByRaw('RAND()')
            ->limit(1)
            ->first();

        // Most recent media (for "Naše poslední vzpomínky")
        $recentMedia = MediaItem::where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->where('status', 'ready')
            ->with(['variants' => fn($q) => $q->whereIn('type', ['thumbnail', 'placeholder'])])
            ->orderByDesc('taken_at')
            ->limit(10)
            ->get();

        // Last visited place (most recent media with GPS)
        $lastPlace = MediaItem::where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderByDesc('taken_at')
            ->first(['latitude', 'longitude', 'taken_at']);

        // Most recently active album
        $lastAlbum = Album::where('gallery_space_id', $space->id)
            ->whereNull('deleted_at')
            ->whereHas('primaryMedia')
            ->withMax('primaryMedia', 'uploaded_at')
            ->orderByDesc('primary_media_max_uploaded_at')
            ->first(['id', 'uuid', 'title', 'event_date_start']);

        // Stats for current year
        $yearStats = MediaItem::where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->whereYear('taken_at', $now->year)
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN media_type='video' THEN 1 ELSE 0 END) as videos, SUM(CASE WHEN media_type='photo' THEN 1 ELSE 0 END) as photos")
            ->first();

        // GPS / map stats
        $gpsCount     = MediaItem::where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->whereNotNull('latitude')
            ->count();

        // Estimate unique countries (rough: distinct lat/lng buckets)
        $countryEstimate = min(10, (int) ceil($gpsCount / 20));

        // Pending uploads (upload sessions not completed)
        $pendingCount = DB::table('upload_sessions')
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'assembling'])
            ->where('expires_at', '>', now())
            ->count();

        return Inertia::render('Dashboard/Index', [
            'data' => [
                'greeting'         => $greeting,
                'user_name'        => $name,
                'this_time_last_year' => [
                    'count' => $thisTimeLastYear->count(),
                    'date'  => $lastYearStart->format('j. n.'),
                    'items' => $thisTimeLastYear,
                ],
                'recent_media'     => $recentMedia,
                'random_memory'    => $randomMemory,
                'last_album'       => $lastAlbum ? [
                    'uuid'  => $lastAlbum->uuid,
                    'title' => $lastAlbum->title,
                    'date'  => $lastAlbum->event_date_start?->format('j. n.'),
                ] : null,
                'pending_uploads'  => $pendingCount,
                'map_stats'        => [
                    'locations' => $gpsCount,
                    'countries' => $countryEstimate,
                ],
                'year_stats'       => [
                    'year'   => $now->year,
                    'photos' => (int) ($yearStats?->photos ?? 0),
                    'videos' => (int) ($yearStats?->videos ?? 0),
                ],
            ],
        ]);
    }
}
