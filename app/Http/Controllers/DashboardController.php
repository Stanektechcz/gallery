<?php

namespace App\Http\Controllers;

use App\Models\MediaItem;
use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\SavedSearch;
use App\Services\Media\MemoryDiscoveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, MemoryDiscoveryService $memoryDiscovery): Response
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
            ->where('is_hidden', false)
            ->with(['variants' => fn($q) => $q->whereIn('type', ['thumbnail', 'placeholder'])])
            ->inRandomOrder()
            ->limit(8)
            ->get();

        // Random memory (any time, random)
        $randomMemory = MediaItem::where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->where('is_hidden', false)
            ->where('status', 'ready')
            ->with(['variants' => fn($q) => $q->whereIn('type', ['thumbnail', 'placeholder'])])
            ->inRandomOrder()
            ->limit(1)
            ->first();

        // Most recent media (for "Naše poslední vzpomínky")
        $recentMedia = MediaItem::where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->where('is_hidden', false)
            ->where('status', 'ready')
            ->with(['variants' => fn($q) => $q->whereIn('type', ['thumbnail', 'placeholder'])])
            ->orderByDesc('taken_at')
            ->limit(10)
            ->get();

        // Last visited place (most recent media with GPS)
        $lastPlace = MediaItem::where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->where('is_hidden', false)
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
            ->where('is_hidden', false)
            ->whereYear('taken_at', $now->year)
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN media_type='video' THEN 1 ELSE 0 END) as videos, SUM(CASE WHEN media_type='photo' THEN 1 ELSE 0 END) as photos")
            ->first();

        // GPS / map stats
        $gpsCount     = MediaItem::where('gallery_space_id', $space->id)
            ->whereNull('trashed_at')
            ->where('is_hidden', false)
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

        $forYou = $memoryDiscovery->discover($user)->take(3)->values();
        $pinnedViews = SavedSearch::where('gallery_space_id', $space->id)
            ->where('user_id', $user->id)
            ->where('is_pinned', true)
            ->orderByDesc('last_used_at')
            ->limit(6)
            ->get(['id', 'name', 'icon', 'filters_json', 'view_type']);
        $upcomingTrip = DB::table('trips')
            ->where('gallery_space_id', $space->id)
            ->where('end_date', '>=', now()->toDateString())
            ->orderBy('start_date')
            ->first(['id', 'name', 'start_date', 'end_date', 'status']);
        if ($upcomingTrip) {
            $totals = DB::table('trip_expenses')->where('trip_id', $upcomingTrip->id)->selectRaw('state, SUM(amount) as total')->groupBy('state')->pluck('total', 'state');
            $upcomingTrip->finance = ['planned' => (float) ($totals['planned'] ?? 0), 'actual' => (float) ($totals['actual'] ?? 0)];
            $packing = DB::table('trip_packing_items')->where('trip_id', $upcomingTrip->id);
            $goal = DB::table('trip_savings_goals')->where('trip_id', $upcomingTrip->id)->first(['target_amount', 'saved_amount', 'monthly_contribution', 'currency']);
            $upcomingTrip->readiness = ['packing_total' => (clone $packing)->count(), 'packing_packed' => (clone $packing)->where('is_packed', true)->count(), 'essential_missing' => (clone $packing)->where('is_essential', true)->where('is_packed', false)->count()];
            $upcomingTrip->savings_goal = $goal ? ['target_amount' => (float) $goal->target_amount, 'saved_amount' => (float) $goal->saved_amount, 'monthly_contribution' => $goal->monthly_contribution !== null ? (float) $goal->monthly_contribution : null, 'currency' => $goal->currency, 'percent' => (int) min(100, round(((float) $goal->saved_amount / max(1, (float) $goal->target_amount)) * 100))] : null;
        }
        $nextSharedEvent = DB::table('calendar_events as e')
            ->where('e.gallery_space_id', $space->id)->where('e.starts_at', '>=', now())
            ->where(fn ($query) => $query->where('e.is_private', false)->orWhere('e.created_by', $user->id)->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('event_participants as ep')->whereColumn('ep.event_id', 'e.id')->where('ep.user_id', $user->id)))
            ->orderBy('e.starts_at')->first(['e.uuid', 'e.title', 'e.starts_at', 'e.place_name', 'e.trip_id']);
        $upcomingMilestones = DB::table('relationship_milestones')
            ->where('gallery_space_id', $space->id)
            ->where('remind_annually', true)
            ->where(fn ($query) => $query->where('visibility', 'shared')->orWhere('created_by', $user->id))
            ->get(['uuid', 'title', 'icon', 'occurred_on'])
            ->map(function ($milestone) use ($now) {
                $original = \Carbon\Carbon::parse($milestone->occurred_on);
                $next = \Carbon\Carbon::create($now->year, $original->month, min($original->day, \Carbon\Carbon::create($now->year, $original->month, 1)->daysInMonth))->startOfDay();
                if ($next->lt($now->copy()->startOfDay())) $next->addYear();
                return ['uuid' => $milestone->uuid, 'title' => $milestone->title, 'icon' => $milestone->icon, 'days_until' => (int) $now->copy()->startOfDay()->diffInDays($next), 'next_anniversary' => $next->toDateString()];
            })->sortBy('days_until')->take(3)->values();
        $sharedMoments = DB::table('shared_memory_moments')->where('gallery_space_id', $space->id)->latest('happened_on')->latest()->limit(3)->get(['uuid', 'title', 'happened_on', 'is_favorite']);

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
                'for_you'          => $forYou,
                'pinned_views'     => $pinnedViews,
                'upcoming_trip'    => $upcomingTrip,
                'partner_hub'      => ['space_id' => $space->id, 'milestones' => $upcomingMilestones, 'shared_moments' => $sharedMoments, 'next_event' => $nextSharedEvent],
            ],
        ]);
    }
}
