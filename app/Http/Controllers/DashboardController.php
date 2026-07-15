<?php

namespace App\Http\Controllers;

use App\Models\MediaItem;
use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\SavedSearch;
use App\Services\Media\MemoryDiscoveryService;
use App\Services\Media\AlbumCurationAssistantService;
use App\Services\Media\UnassignedAlbumSuggestionService;
use App\Services\Planning\TripPreparationTimelineService;
use App\Services\Planning\CoupleExperienceRecommendationService;
use App\Services\Planning\ExperienceLifecycleService;
use App\Services\Planning\PartnerCoordinationService;
use App\Services\Memories\RelationshipAnniversaryRecapService;
use App\Services\Banking\TripFinancialInsightService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, MemoryDiscoveryService $memoryDiscovery, TripPreparationTimelineService $tripPreparation, AlbumCurationAssistantService $albumCuration, CoupleExperienceRecommendationService $experienceRecommendations, ExperienceLifecycleService $experienceLifecycle, PartnerCoordinationService $partnerCoordination, RelationshipAnniversaryRecapService $anniversaryRecaps, UnassignedAlbumSuggestionService $albumSuggestions, TripFinancialInsightService $bankInsights): Response
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
            ->first(['id', 'uuid', 'gallery_space_id', 'title', 'event_date_start', 'album_type', 'smart_rules']);
        $lastAlbumHealth = $lastAlbum ? $albumCuration->health($lastAlbum) : null;

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
            ->first(['id', 'gallery_space_id', 'name', 'start_date', 'end_date', 'status', 'timezone']);
        if ($upcomingTrip) {
            $totals = DB::table('trip_expenses')->where('trip_id', $upcomingTrip->id)->selectRaw('state, SUM(amount) as total')->groupBy('state')->pluck('total', 'state');
            $upcomingTrip->finance = ['planned' => (float) ($totals['planned'] ?? 0), 'actual' => (float) ($totals['actual'] ?? 0)];
            $packing = DB::table('trip_packing_items')->where('trip_id', $upcomingTrip->id);
            $goal = DB::table('trip_savings_goals')->where('trip_id', $upcomingTrip->id)->first(['target_amount', 'saved_amount', 'monthly_contribution', 'currency']);
            $upcomingTrip->readiness = ['packing_total' => (clone $packing)->count(), 'packing_packed' => (clone $packing)->where('is_packed', true)->count(), 'essential_missing' => (clone $packing)->where('is_essential', true)->where('is_packed', false)->count()];
            $upcomingTrip->savings_goal = $goal ? ['target_amount' => (float) $goal->target_amount, 'saved_amount' => (float) $goal->saved_amount, 'monthly_contribution' => $goal->monthly_contribution !== null ? (float) $goal->monthly_contribution : null, 'currency' => $goal->currency, 'percent' => (int) min(100, round(((float) $goal->saved_amount / max(1, (float) $goal->target_amount)) * 100))] : null;
            $upcomingTrip->preparation = $tripPreparation->snapshot($upcomingTrip);
            $upcomingTrip->bank_finance = $bankInsights->tripPrompt($upcomingTrip);
        }
        $nextSharedEvent = DB::table('calendar_events as e')
            ->where('e.gallery_space_id', $space->id)->where('e.starts_at', '>=', now())
            ->where(fn ($query) => $query->where('e.is_private', false)->orWhere('e.created_by', $user->id)->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('event_participants as ep')->whereColumn('ep.event_id', 'e.id')->where('ep.user_id', $user->id)))
            ->orderBy('e.starts_at')->first(['e.uuid', 'e.title', 'e.starts_at', 'e.place_name', 'e.trip_id']);
        if ($nextSharedEvent) {
            $eventId = DB::table('calendar_events')->where('uuid', $nextSharedEvent->uuid)->value('id');
            $nextSharedEvent->open_tasks_count = DB::table('event_tasks')->where('event_id', $eventId)->whereNull('completed_at')->count();
            $nextSharedEvent->planning_items_count = Schema::hasTable('travel_inbox_items')
                ? DB::table('travel_inbox_items')->where('gallery_space_id', $space->id)->where('state', '!=', 'archived')->where(function ($query) use ($eventId, $nextSharedEvent) { $query->where('event_id', $eventId); if ($nextSharedEvent->trip_id) $query->orWhere('trip_id', $nextSharedEvent->trip_id); })->count()
                : 0;
        }
        $coordination = $partnerCoordination->snapshot($space, $user, 5);
        $nextActions = $coordination['actions'];
        $upcomingMilestones = DB::table('relationship_milestones')
            ->where('gallery_space_id', $space->id)
            ->where('remind_annually', true)
            ->where(fn ($query) => $query->where('visibility', 'shared')->orWhere('created_by', $user->id))
            ->get(['uuid', 'title', 'icon', 'occurred_on', 'kind', 'relationship', 'person_name', 'is_highlighted'])
            ->map(function ($milestone) use ($now) {
                $original = \Carbon\Carbon::parse($milestone->occurred_on);
                $next = \Carbon\Carbon::create($now->year, $original->month, min($original->day, \Carbon\Carbon::create($now->year, $original->month, 1)->daysInMonth))->startOfDay();
                if ($next->lt($now->copy()->startOfDay())) $next->addYear();
                return ['uuid' => $milestone->uuid, 'title' => $milestone->title, 'icon' => $milestone->icon, 'kind' => $milestone->kind, 'relationship' => $milestone->relationship, 'person_name' => $milestone->person_name, 'is_highlighted' => (bool) $milestone->is_highlighted, 'days_until' => (int) $now->copy()->startOfDay()->diffInDays($next), 'next_anniversary' => $next->toDateString()];
            })->sortBy('days_until')->take(3)->values();
        $sharedMoments = DB::table('shared_memory_moments')->where('gallery_space_id', $space->id)->latest('happened_on')->latest()->limit(3)->get(['uuid', 'title', 'happened_on', 'is_favorite']);
        $relationshipAnniversary = (array) (($space->settings ?? [])['relationship_anniversary'] ?? []);
        $anniversaryRecap = $anniversaryRecaps->prompt($space);
        $albumSuggestion = $albumSuggestions->prompt($space, $user);
        $reflectionPrompt = null;
        if (Schema::hasTable('trip_reflections')) {
            $reflectionPrompt = DB::table('trips as t')
                ->leftJoin('trip_reflections as r', 'r.trip_id', '=', 't.id')
                ->where('t.gallery_space_id', $space->id)
                ->whereNull('r.id')
                ->where(fn ($query) => $query->where('t.status', 'completed')->orWhere('t.end_date', '<', now()->toDateString()))
                ->orderByDesc('t.end_date')
                ->first(['t.id', 't.name', 't.end_date']);
        }
        $eventReflectionPrompt = null;
        if (Schema::hasTable('calendar_event_reflections')) {
            $eventReflectionPrompt = DB::table('calendar_events as e')
                ->leftJoin('calendar_event_reflections as r', 'r.calendar_event_id', '=', 'e.id')
                ->where('e.gallery_space_id', $space->id)->whereNull('r.id')->whereNull('e.trip_id')
                ->where('e.starts_at', '<', now())->whereNotIn('e.status', ['cancelled'])
                ->orderByDesc('e.starts_at')->first(['e.uuid', 'e.title', 'e.starts_at']);
        }
        $experienceRecommendation = $experienceRecommendations->recommend($space, [], 1)->first();
        $experienceFollowUp = $experienceLifecycle->pendingFollowUp($space, $user);
        $dateFollowUp = null;
        if (Schema::hasTable('couple_date_ideas') && Schema::hasTable('couple_date_idea_reactions')) {
            $row = DB::table('couple_date_ideas as idea')
                ->join('calendar_events as event', 'event.id', '=', 'idea.calendar_event_id')
                ->leftJoin('couple_date_idea_reactions as reaction', function ($join) use ($user) {
                    $join->on('reaction.date_idea_id', '=', 'idea.id')->where('reaction.user_id', $user->id);
                })
                ->leftJoin('shared_memory_moments as memory', 'memory.calendar_event_id', '=', 'event.id')
                ->where('idea.gallery_space_id', $space->id)->where('event.starts_at', '<=', now())
                ->whereNotIn('event.status', ['cancelled'])
                ->where(fn ($query) => $query->whereNull('reaction.rating')->orWhereNull('memory.id'))
                ->orderByDesc('event.starts_at')
                ->first(['idea.uuid', 'idea.title', 'idea.status', 'event.uuid as event_uuid', 'event.starts_at', 'reaction.rating', 'memory.id as memory_id']);
            if ($row) $dateFollowUp = ['uuid' => $row->uuid, 'title' => $row->title, 'event_uuid' => $row->event_uuid,
                'starts_at' => $row->starts_at, 'needs_feedback' => $row->rating === null, 'needs_memory' => $row->memory_id === null];
        }
        if ($dateFollowUp && $experienceFollowUp && $experienceFollowUp['uuid'] === $dateFollowUp['event_uuid']) $experienceFollowUp = null;
        if ($eventReflectionPrompt && $experienceFollowUp && $eventReflectionPrompt->uuid === $experienceFollowUp['uuid']) $eventReflectionPrompt = null;
        $recipeHub = null;
        if (Schema::hasTable('recipes') && Schema::hasTable('recipe_cooking_sessions')) {
            $plannedRecipe = DB::table('recipe_cooking_sessions as session')
                ->join('recipes as recipe', 'recipe.id', '=', 'session.recipe_id')
                ->where('recipe.gallery_space_id', $space->id)->whereNull('recipe.deleted_at')
                ->where('session.status', 'planned')->where('session.planned_for', '>=', now())
                ->orderBy('session.planned_for')->first(['recipe.uuid', 'recipe.title', 'session.planned_for', 'session.servings']);
            if ($plannedRecipe) {
                $recipeHub = ['kind' => 'planned', 'uuid' => $plannedRecipe->uuid, 'title' => $plannedRecipe->title, 'planned_for' => $plannedRecipe->planned_for, 'servings' => (float) $plannedRecipe->servings];
            } else {
                $favoriteRecipe = DB::table('recipes')->where('gallery_space_id', $space->id)->whereNull('deleted_at')->where('status', 'published')->orderByDesc('is_favorite')->orderByDesc('updated_at')->first(['id', 'uuid', 'title']);
                if ($favoriteRecipe) {
                    $recipeHub = ['kind' => 'suggestion', 'uuid' => $favoriteRecipe->uuid, 'title' => $favoriteRecipe->title,
                        'times_cooked' => DB::table('recipe_cooking_sessions')->where('recipe_id', $favoriteRecipe->id)->where('status', 'completed')->count()];
                }
            }
        }
        $memoryEvening = null;
        if (Schema::hasTable('memory_evenings')) {
            $row = DB::table('memory_evenings as evening')->leftJoin('calendar_events as event', 'event.id', '=', 'evening.calendar_event_id')
                ->where('evening.gallery_space_id', $space->id)->whereIn('evening.status', ['planned', 'active'])
                ->orderByRaw("CASE evening.status WHEN 'active' THEN 0 ELSE 1 END")->orderBy('evening.scheduled_for')
                ->first(['evening.uuid', 'evening.title', 'evening.status', 'evening.scheduled_for', 'evening.curation_board_id', 'event.uuid as event_uuid']);
            if ($row) {
                $itemIds = DB::table('curation_board_items')->where('curation_board_id', $row->curation_board_id)->pluck('id');
                $memoryEvening = ['uuid' => $row->uuid, 'title' => $row->title, 'status' => $row->status, 'scheduled_for' => $row->scheduled_for, 'event_uuid' => $row->event_uuid,
                    'media_count' => $itemIds->count(), 'selected_count' => DB::table('curation_board_votes')->whereIn('curation_board_item_id', $itemIds)->where('is_selected', true)->distinct('curation_board_item_id')->count('curation_board_item_id')];
            }
        }
        $dateIdea = null;
        if (Schema::hasTable('couple_date_ideas')) {
            $row = DB::table('couple_date_ideas')
                ->where('gallery_space_id', $space->id)->whereIn('status', ['saved', 'generated'])
                ->whereNull('calendar_event_id')
                ->orderByRaw("CASE status WHEN 'saved' THEN 0 ELSE 1 END")
                ->latest()->first(['uuid', 'title', 'summary', 'estimated_cost', 'currency', 'suggested_starts_at', 'destination', 'status']);
            if ($row) {
                $destination = is_string($row->destination) ? json_decode($row->destination, true) : (array) $row->destination;
                $dateIdea = ['uuid' => $row->uuid, 'title' => $row->title, 'summary' => $row->summary,
                    'estimated_cost' => (float) $row->estimated_cost, 'currency' => $row->currency,
                    'suggested_starts_at' => $row->suggested_starts_at, 'destination' => $destination['location_name'] ?? null,
                    'status' => $row->status];
            }
        }

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
                    'curation' => $lastAlbumHealth,
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
                'partner_hub'      => ['space_id' => $space->id, 'relationship_started_on' => $relationshipAnniversary['started_on'] ?? null, 'anniversary_recap' => $anniversaryRecap, 'album_suggestion' => $albumSuggestion, 'milestones' => $upcomingMilestones, 'shared_moments' => $sharedMoments, 'next_event' => $nextSharedEvent, 'next_actions' => $nextActions, 'coordination' => $coordination, 'reflection_prompt' => $reflectionPrompt, 'event_reflection_prompt' => $eventReflectionPrompt, 'experience_recommendation' => $experienceRecommendation, 'experience_follow_up' => $experienceFollowUp, 'date_follow_up' => $dateFollowUp, 'recipe' => $recipeHub, 'memory_evening' => $memoryEvening, 'date_idea' => $dateIdea],
            ],
        ]);
    }
}
