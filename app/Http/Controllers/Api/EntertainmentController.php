<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\EntertainmentTitle;
use App\Models\EntertainmentVote;
use App\Models\GallerySpace;
use App\Services\Entertainment\CinemaCityProgramService;
use App\Services\Entertainment\EntertainmentMetadataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EntertainmentController extends Controller
{
    public function __construct(
        private readonly EntertainmentMetadataService $metadata,
        private readonly CinemaCityProgramService $cinema,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->available();
        $data = $request->validate(['gallery_space_id' => 'nullable|integer', 'status' => 'nullable|string|max:24', 'type' => 'nullable|in:movie,series', 'search' => 'nullable|string|max:120']);
        $space = $this->space($request, isset($data['gallery_space_id']) ? (int) $data['gallery_space_id'] : null);
        $query = EntertainmentTitle::where('gallery_space_id', $space->id)->with(['votes.user:id,name']);
        if (! empty($data['status']) && $data['status'] !== 'all') {
            $query->where('status', $data['status']);
        }
        if (! empty($data['type'])) {
            $query->where('media_type', $data['type']);
        }
        if (! empty($data['search'])) {
            $query->where(fn ($search) => $search->where('title', 'like', '%'.$data['search'].'%')->orWhere('original_title', 'like', '%'.$data['search'].'%'));
        }
        $titles = $query->orderByRaw("CASE status WHEN 'scheduled' THEN 0 WHEN 'proposed' THEN 1 WHEN 'watching' THEN 2 ELSE 3 END")
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")->latest()->limit(100)->get();
        $titleIds = $titles->pluck('id');
        $proposals = DB::table('viewing_date_proposals as proposal')->leftJoin('cinema_showings as showing', 'showing.id', '=', 'proposal.cinema_showing_id')
            ->whereIn('proposal.entertainment_title_id', $titleIds)->where('proposal.starts_at', '>=', now()->subDay())
            ->orderBy('proposal.starts_at')->get(['proposal.*', 'showing.uuid as showing_uuid', 'showing.external_event_id as showing_event_id', 'showing.booking_url']);
        $proposalVotes = DB::table('viewing_proposal_votes')->whereIn('viewing_date_proposal_id', $proposals->pluck('id'))->get()->groupBy('viewing_date_proposal_id');
        $progress = DB::table('entertainment_progress')->whereIn('entertainment_title_id', $titleIds)->get()->groupBy('entertainment_title_id');
        $sessions = DB::table('viewing_sessions')->whereIn('entertainment_title_id', $titleIds)->latest('watched_at')->get()->groupBy('entertainment_title_id');
        $reviews = DB::table('entertainment_reviews as review')
            ->join('users as reviewer', 'reviewer.id', '=', 'review.user_id')
            ->leftJoin('viewing_sessions as session', 'session.id', '=', 'review.viewing_session_id')
            ->whereIn('review.entertainment_title_id', $titleIds)
            ->latest('review.created_at')
            ->get([
                'review.*', 'reviewer.name as reviewer_name', 'session.uuid as session_uuid', 'session.watched_at', 'session.venue', 'session.note as session_note',
            ])->groupBy('entertainment_title_id');

        return response()->json([
            'space' => ['id' => $space->id, 'name' => $space->name], 'members' => $this->members($space),
            'titles' => $titles->map(fn (EntertainmentTitle $title) => $this->titlePayload($title, $proposals, $proposalVotes, $progress, $sessions, $reviews, $request->user()->id)),
            'cinema' => [
                'name' => CinemaCityProgramService::CINEMA_NAME, 'source_url' => CinemaCityProgramService::CINEMA_URL,
                'showings' => $this->showings(),
                'last_sync' => DB::table('cinema_sync_runs')->where('provider', 'cinema_city')->where('cinema_code', CinemaCityProgramService::CINEMA_CODE)->latest()->first(),
            ],
            'integrations' => ['tmdb_configured' => $this->metadata->configured(), 'tmdb_attribution' => 'This product uses the TMDB API but is not endorsed or certified by TMDB.'],
            'summary' => ['proposed' => $titles->where('status', 'proposed')->count(), 'scheduled' => $titles->where('status', 'scheduled')->count(), 'watching' => $titles->where('status', 'watching')->count()],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['query' => 'required|string|min:2|max:120', 'type' => 'nullable|in:multi,movie,tv']);
        $local = EntertainmentTitle::whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))
            ->where('title', 'like', '%'.$data['query'].'%')->limit(8)->get()->map(fn ($title) => $this->basicTitle($title) + ['already_added' => true]);
        try {
            $remote = $this->metadata->search($data['query'], $data['type'] ?? 'multi');
        } catch (\Throwable $exception) {
            report($exception);
            $remote = [];
        }

        return response()->json(['results' => collect($remote)->concat($local)->unique(fn ($item) => ($item['external_source'] ?? 'local').':'.($item['external_id'] ?? $item['title']))->values(), 'tmdb_configured' => $this->metadata->configured()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->write($request);
        $this->available();
        $data = $request->validate([
            'gallery_space_id' => 'required|integer', 'media_type' => 'required|in:movie,series', 'title' => 'required|string|max:255',
            'original_title' => 'nullable|string|max:255', 'external_source' => 'nullable|in:manual,tmdb,cinema_city', 'external_id' => 'nullable|string|max:64',
            'release_date' => 'nullable|date', 'release_year' => 'nullable|integer|between:1880,2200', 'runtime_minutes' => 'nullable|integer|between:1,1440',
            'seasons_count' => 'nullable|integer|between:1,500', 'overview' => 'nullable|string|max:10000',
            'poster_url' => 'nullable|url:https|max:2048', 'backdrop_url' => 'nullable|url:https|max:2048', 'trailer_url' => 'nullable|url:https|max:2048',
            'original_language' => 'nullable|string|max:12', 'genres' => 'nullable|array|max:30', 'genres.*' => 'string|max:80',
            'priority' => 'nullable|in:low,normal,high,urgent', 'watch_provider' => 'nullable|string|max:120', 'notes' => 'nullable|string|max:5000',
        ]);
        $space = $this->space($request, (int) $data['gallery_space_id']);
        if (($data['external_source'] ?? 'manual') === 'tmdb' && ! empty($data['external_id'])) {
            try {
                $data = array_merge($data, $this->metadata->details($data['media_type'] === 'series' ? 'tv' : 'movie', (int) $data['external_id']));
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
        $lookup = ['gallery_space_id' => $space->id, 'external_source' => $data['external_source'] ?? 'manual', 'external_id' => $data['external_id'] ?? null];
        $title = ! empty($lookup['external_id']) ? EntertainmentTitle::firstOrNew($lookup) : new EntertainmentTitle(['gallery_space_id' => $space->id]);
        $title->fill(collect($data)->except('gallery_space_id')->all() + ['external_source' => 'manual']);
        $title->added_by ??= $request->user()->id;
        $title->gallery_space_id = $space->id;
        $title->save();

        return response()->json($this->basicTitle($title->fresh()), $title->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $title = $this->title($request, $uuid);
        $data = $request->validate(['status' => 'nullable|in:proposed,shortlisted,scheduled,watching,watched,paused,dropped', 'priority' => 'nullable|in:low,normal,high,urgent', 'watch_provider' => 'nullable|string|max:120', 'notes' => 'nullable|string|max:5000']);
        if (($data['status'] ?? null) === 'watching' && ! $title->started_at) {
            $data['started_at'] = now();
        }
        if (($data['status'] ?? null) === 'watched') {
            $data['watched_at'] = now();
        }
        $title->update($data);

        return response()->json($this->basicTitle($title->fresh()));
    }

    public function vote(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $title = $this->title($request, $uuid);
        $data = $request->validate(['interest' => 'required|integer|between:1,5', 'cinema_preferred' => 'nullable|boolean', 'note' => 'nullable|string|max:1000']);
        $vote = EntertainmentVote::updateOrCreate(['entertainment_title_id' => $title->id, 'user_id' => $request->user()->id], $data);

        return response()->json($vote->load('user:id,name'));
    }

    public function dateSuggestions(Request $request, string $uuid): JsonResponse
    {
        $title = $this->title($request, $uuid);
        $space = GallerySpace::findOrFail($title->gallery_space_id);
        $showings = DB::table('cinema_showings')->where('starts_at', '>', now())->where(function ($query) use ($title) {
            $query->where('entertainment_title_id', $title->id)->orWhereRaw('LOWER(title) = ?', [mb_strtolower($title->title)]);
        })->orderBy('starts_at')->limit(12)->get()->map(fn ($showing) => $this->showingPayload($showing));
        $home = collect();
        $cursor = now('Europe/Prague')->addDay()->startOfDay();
        while ($home->count() < 8 && $cursor->lte(now('Europe/Prague')->addDays(35))) {
            $start = $cursor->copy()->setTime(in_array($cursor->dayOfWeekIso, [5, 6], true) ? 19 : 19, 30);
            $end = $start->copy()->addMinutes($title->runtime_minutes ?: ($title->media_type === 'series' ? 90 : 150));
            $busy = CalendarEvent::where('gallery_space_id', $space->id)->where('status', '!=', 'cancelled')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists();
            if (! $busy && in_array($cursor->dayOfWeekIso, [3, 5, 6, 7], true)) {
                $home->push(['starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String(), 'venue' => 'home', 'label' => $start->translatedFormat('l j. n. · H:i')]);
            }
            $cursor->addDay();
        }

        return response()->json(['home' => $home, 'cinema' => $showings]);
    }

    public function proposeDate(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $title = $this->title($request, $uuid);
        $data = $request->validate(['starts_at' => 'required_without:showing_uuid|nullable|date|after:now', 'showing_uuid' => 'nullable|uuid', 'venue' => 'nullable|in:home,cinema,other', 'place_name' => 'nullable|string|max:255', 'note' => 'nullable|string|max:2000']);
        $showing = ! empty($data['showing_uuid']) ? DB::table('cinema_showings')->where('uuid', $data['showing_uuid'])->where('starts_at', '>', now())->first() : null;
        abort_if(! empty($data['showing_uuid']) && ! $showing, 422, 'Vybrané promítání už není dostupné.');
        $id = DB::table('viewing_date_proposals')->insertGetId([
            'uuid' => (string) Str::uuid(), 'entertainment_title_id' => $title->id, 'proposed_by' => $request->user()->id,
            'cinema_showing_id' => $showing?->id, 'starts_at' => $showing?->starts_at ?: Carbon::parse($data['starts_at']),
            'venue' => $showing ? 'cinema' : ($data['venue'] ?? 'home'), 'place_name' => $showing?->cinema_name ?: ($data['place_name'] ?? null),
            'note' => $data['note'] ?? null, 'status' => 'proposed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('viewing_proposal_votes')->insert(['viewing_date_proposal_id' => $id, 'user_id' => $request->user()->id, 'response' => 'yes', 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(DB::table('viewing_date_proposals')->where('id', $id)->first(), 201);
    }

    public function voteDate(Request $request, string $proposalUuid): JsonResponse
    {
        $this->write($request);
        $proposal = $this->proposal($request, $proposalUuid);
        $data = $request->validate(['response' => 'required|in:yes,maybe,no']);
        DB::table('viewing_proposal_votes')->updateOrInsert(['viewing_date_proposal_id' => $proposal->id, 'user_id' => $request->user()->id], ['response' => $data['response'], 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['status' => 'saved']);
    }

    public function selectDate(Request $request, string $proposalUuid): JsonResponse
    {
        $this->write($request);
        $proposal = $this->proposal($request, $proposalUuid);
        if ($proposal->calendar_event_id) {
            return response()->json($this->eventPayload(CalendarEvent::findOrFail($proposal->calendar_event_id)));
        }
        $title = EntertainmentTitle::findOrFail($proposal->entertainment_title_id);
        $showing = $proposal->cinema_showing_id ? DB::table('cinema_showings')->find($proposal->cinema_showing_id) : null;
        $event = DB::transaction(function () use ($request, $proposal, $title, $showing) {
            $start = Carbon::parse($proposal->starts_at);
            $duration = $title->runtime_minutes ?: ($title->media_type === 'series' ? 90 : 150);
            $event = CalendarEvent::create([
                'gallery_space_id' => $title->gallery_space_id, 'created_by' => $request->user()->id,
                'title' => ($title->media_type === 'series' ? 'Seriálový večer · ' : 'Filmový večer · ').$title->title,
                'description' => $proposal->note ?: $title->overview, 'type' => $title->media_type === 'series' ? 'series_night' : 'movie_night',
                'status' => 'planned', 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes($duration), 'timezone' => 'Europe/Prague',
                'place_name' => $proposal->place_name, 'color' => '#8b5cf6', 'is_private' => false,
                'metadata' => ['entertainment_uuid' => $title->uuid, 'proposal_uuid' => $proposal->uuid, 'booking_url' => $this->cinemaBookingUrl($showing), 'source' => 'entertainment_planner'],
            ]);
            $members = DB::table('gallery_space_user')->where('gallery_space_id', $title->gallery_space_id)->pluck('user_id');
            foreach ($members as $memberId) {
                DB::table('event_participants')->insertOrIgnore(['event_id' => $event->id, 'user_id' => $memberId, 'role' => (int) $memberId === $request->user()->id ? 'organizer' : 'guest', 'response' => (int) $memberId === $request->user()->id ? 'accepted' : 'pending', 'created_at' => now(), 'updated_at' => now()]);
                foreach ([1440, 120] as $minutes) {
                    if ($start->copy()->subMinutes($minutes)->isFuture()) {
                        DB::table('event_reminders')->insert(['event_id' => $event->id, 'user_id' => $memberId, 'channel' => 'database', 'remind_at' => $start->copy()->subMinutes($minutes), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            }
            DB::table('viewing_date_proposals')->where('id', $proposal->id)->update(['calendar_event_id' => $event->id, 'status' => 'selected', 'updated_at' => now()]);
            DB::table('viewing_date_proposals')->where('entertainment_title_id', $title->id)->where('id', '!=', $proposal->id)->where('status', 'proposed')->update(['status' => 'declined', 'updated_at' => now()]);
            $title->update(['status' => 'scheduled']);

            return $event;
        });

        return response()->json($this->eventPayload($event), 201);
    }

    public function recordSession(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $title = $this->title($request, $uuid);
        $data = $request->validate([
            'watched_at' => 'nullable|date|before_or_equal:now', 'venue' => 'nullable|in:home,cinema,other',
            'season_number' => 'nullable|integer|between:1,999', 'episode_from' => 'nullable|integer|between:1,9999', 'episode_to' => 'nullable|integer|between:1,9999',
            'note' => 'nullable|string|max:5000', 'rating' => 'nullable|numeric|between:0.5,5',
            'story_rating' => 'nullable|numeric|between:0.5,5', 'acting_rating' => 'nullable|numeric|between:0.5,5',
            'visual_rating' => 'nullable|numeric|between:0.5,5', 'sound_rating' => 'nullable|numeric|between:0.5,5',
            'emotion_rating' => 'nullable|numeric|between:0.5,5', 'pace_rating' => 'nullable|numeric|between:0.5,5',
            'recommendation' => 'nullable|in:yes,maybe,no', 'review' => 'nullable|string|max:5000',
            'favorite_moment' => 'nullable|string|max:500', 'watch_again' => 'nullable|boolean',
        ]);
        $sessionId = DB::table('viewing_sessions')->insertGetId(['uuid' => (string) Str::uuid(), 'entertainment_title_id' => $title->id, 'recorded_by' => $request->user()->id, 'watched_at' => Carbon::parse($data['watched_at'] ?? now()), 'venue' => $data['venue'] ?? 'home', 'season_number' => $data['season_number'] ?? null, 'episode_from' => $data['episode_from'] ?? null, 'episode_to' => $data['episode_to'] ?? null, 'note' => $data['note'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        if (! empty($data['rating'])) {
            DB::table('entertainment_reviews')->updateOrInsert(
                ['entertainment_title_id' => $title->id, 'viewing_session_id' => $sessionId, 'user_id' => $request->user()->id],
                [
                    'rating' => $data['rating'],
                    'story_rating' => $data['story_rating'] ?? null,
                    'acting_rating' => $data['acting_rating'] ?? null,
                    'visual_rating' => $data['visual_rating'] ?? null,
                    'sound_rating' => $data['sound_rating'] ?? null,
                    'emotion_rating' => $data['emotion_rating'] ?? null,
                    'pace_rating' => $data['pace_rating'] ?? null,
                    'recommendation' => $data['recommendation'] ?? null,
                    'review' => $data['review'] ?? null,
                    'favorite_moment' => $data['favorite_moment'] ?? null,
                    'watch_again' => $data['watch_again'] ?? false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
        if ($title->media_type === 'series' && ! empty($data['season_number'])) {
            DB::table('entertainment_progress')->updateOrInsert(['entertainment_title_id' => $title->id, 'user_id' => $request->user()->id], ['season_number' => $data['season_number'], 'episode_number' => $data['episode_to'] ?? $data['episode_from'] ?? 0, 'created_at' => now(), 'updated_at' => now()]);
        }
        $title->update(['status' => $title->media_type === 'series' ? 'watching' : 'watched', 'started_at' => $title->started_at ?: now(), 'watched_at' => $title->media_type === 'movie' ? now() : $title->watched_at]);

        return response()->json(['status' => 'recorded'], 201);
    }

    public function syncCinema(Request $request): JsonResponse
    {
        $this->write($request);
        $data = $request->validate(['days' => 'nullable|integer|between:1,14']);
        try {
            return response()->json($this->cinema->sync(now('Europe/Prague')->startOfDay(), (int) ($data['days'] ?? 7)));
        } catch (\Throwable $exception) {
            report($exception);
            $reason = Schema::hasTable('cinema_sync_runs')
                ? DB::table('cinema_sync_runs')->where('provider', 'cinema_city')->where('cinema_code', CinemaCityProgramService::CINEMA_CODE)->latest('id')->value('last_error')
                : 'Nejsou dokončené databázové migrace pro program kina.';

            return response()->json([
                'message' => 'Program kina se nyní nepodařilo obnovit. Poslední uložený program zůstává dostupný.',
                'reason' => $reason,
            ], 502);
        }
    }

    public function importShowing(Request $request, string $showingUuid): JsonResponse
    {
        $this->write($request);
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'propose' => 'nullable|boolean']);
        $space = $this->space($request, (int) $data['gallery_space_id']);
        $showing = DB::table('cinema_showings')->where('uuid', $showingUuid)->where('starts_at', '>', now())->firstOrFail();
        $title = $showing->entertainment_title_id ? EntertainmentTitle::find($showing->entertainment_title_id) : null;
        if (! $title || $title->gallery_space_id !== $space->id) {
            $title = EntertainmentTitle::firstOrCreate(['gallery_space_id' => $space->id, 'external_source' => 'cinema_city', 'external_id' => $showing->external_film_id ?: Str::slug($showing->title)], ['added_by' => $request->user()->id, 'media_type' => 'movie', 'title' => $showing->title, 'release_year' => $showing->release_year, 'runtime_minutes' => $showing->runtime_minutes, 'poster_url' => $showing->poster_url, 'status' => 'proposed']);
        }
        DB::table('cinema_showings')->where('id', $showing->id)->update(['entertainment_title_id' => $title->id, 'updated_at' => now()]);
        if ($data['propose'] ?? true) {
            $this->createShowingProposal($title, $showing, $request->user()->id);
        }

        return response()->json($this->basicTitle($title), 201);
    }

    private function createShowingProposal(EntertainmentTitle $title, object $showing, int $userId): void
    {
        $exists = DB::table('viewing_date_proposals')->where('entertainment_title_id', $title->id)->where('cinema_showing_id', $showing->id)->exists();
        if ($exists) {
            return;
        }
        $id = DB::table('viewing_date_proposals')->insertGetId(['uuid' => (string) Str::uuid(), 'entertainment_title_id' => $title->id, 'proposed_by' => $userId, 'cinema_showing_id' => $showing->id, 'starts_at' => $showing->starts_at, 'venue' => 'cinema', 'place_name' => $showing->cinema_name, 'status' => 'proposed', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('viewing_proposal_votes')->insert(['viewing_date_proposal_id' => $id, 'user_id' => $userId, 'response' => 'yes', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function titlePayload(EntertainmentTitle $title, $proposals, $proposalVotes, $progress, $sessions, $reviews, int $viewerId): array
    {
        $votes = $title->votes->map(fn ($vote) => ['user' => ['id' => $vote->user_id, 'name' => $vote->user?->name], 'interest' => $vote->interest, 'cinema_preferred' => $vote->cinema_preferred, 'note' => $vote->note]);

        $titleReviews = collect($reviews->get($title->id, []))->map(fn ($review) => [
            'uuid' => $review->session_uuid,
            'user' => ['id' => $review->user_id, 'name' => $review->reviewer_name],
            'watched_at' => $review->watched_at ? Carbon::parse($review->watched_at)->toIso8601String() : null,
            'venue' => $review->venue,
            'rating' => (float) $review->rating,
            'story_rating' => isset($review->story_rating) ? (float) $review->story_rating : null,
            'acting_rating' => isset($review->acting_rating) ? (float) $review->acting_rating : null,
            'visual_rating' => isset($review->visual_rating) ? (float) $review->visual_rating : null,
            'sound_rating' => isset($review->sound_rating) ? (float) $review->sound_rating : null,
            'emotion_rating' => isset($review->emotion_rating) ? (float) $review->emotion_rating : null,
            'pace_rating' => isset($review->pace_rating) ? (float) $review->pace_rating : null,
            'recommendation' => $review->recommendation,
            'review' => $review->review,
            'favorite_moment' => $review->favorite_moment,
            'watch_again' => (bool) $review->watch_again,
            'session_note' => $review->session_note,
        ])->values();

        return $this->basicTitle($title) + [
            'votes' => $votes, 'my_vote' => $votes->first(fn ($vote) => $vote['user']['id'] === $viewerId),
            'joint_score' => $votes->count() ? round((float) $votes->avg('interest'), 1) : null,
            'proposals' => $proposals->where('entertainment_title_id', $title->id)->map(function ($proposal) use ($proposalVotes, $viewerId) {
                $votes = collect($proposalVotes->get($proposal->id, []));

                return ['uuid' => $proposal->uuid, 'starts_at' => Carbon::parse($proposal->starts_at)->toIso8601String(), 'venue' => $proposal->venue, 'place_name' => $proposal->place_name, 'note' => $proposal->note, 'status' => $proposal->status, 'showing_uuid' => $proposal->showing_uuid, 'booking_url' => $proposal->showing_event_id ? $this->cinemaBookingUrl((object) ['external_event_id' => $proposal->showing_event_id, 'booking_url' => $proposal->booking_url]) : $proposal->booking_url, 'votes' => $votes->countBy('response'), 'my_response' => $votes->firstWhere('user_id', $viewerId)?->response, 'event_uuid' => $proposal->calendar_event_id ? CalendarEvent::whereKey($proposal->calendar_event_id)->value('uuid') : null];
            })->values(),
            'progress' => collect($progress->get($title->id, []))->values(),
            'sessions' => collect($sessions->get($title->id, []))->take(5)->values(),
            'reviews' => $titleReviews,
            'review_summary' => [
                'count' => $titleReviews->count(),
                'rating' => $titleReviews->count() ? round((float) $titleReviews->avg('rating'), 1) : null,
                'story' => $this->reviewAverage($titleReviews, 'story_rating'),
                'acting' => $this->reviewAverage($titleReviews, 'acting_rating'),
                'visual' => $this->reviewAverage($titleReviews, 'visual_rating'),
                'sound' => $this->reviewAverage($titleReviews, 'sound_rating'),
                'emotion' => $this->reviewAverage($titleReviews, 'emotion_rating'),
                'pace' => $this->reviewAverage($titleReviews, 'pace_rating'),
            ],
        ];
    }

    private function basicTitle(EntertainmentTitle $title): array
    {
        return collect($title->toArray())->except(['id', 'gallery_space_id', 'added_by', 'album_id'])->all();
    }

    private function showings()
    {
        return DB::table('cinema_showings')->where('cinema_code', CinemaCityProgramService::CINEMA_CODE)->where('starts_at', '>', now())->orderBy('starts_at')->limit(500)->get()->map(fn ($item) => $this->showingPayload($item));
    }

    private function showingPayload(object $item): array
    {
        return [
            'uuid' => $item->uuid,
            'external_film_id' => $item->external_film_id,
            'title' => $item->title,
            'release_year' => $item->release_year,
            'starts_at' => Carbon::parse($item->starts_at)->toIso8601String(),
            'cinema_name' => $item->cinema_name,
            'poster_url' => $item->poster_url,
            'runtime_minutes' => $item->runtime_minutes,
            'auditorium' => $item->auditorium,
            'format' => $item->format,
            'original_language' => $item->original_language,
            'dubbed_language' => $item->dubbed_language,
            'subtitles_language' => $item->subtitles_language,
            'sold_out' => (bool) $item->sold_out,
            'availability_ratio' => isset($item->availability_ratio) ? (float) $item->availability_ratio : null,
            'booking_url' => 'https://www.cinemacity.cz/cz/booking-router/launch/'.rawurlencode((string) $item->external_event_id).'?lang=cs',
            'source_url' => $item->source_url,
        ];
    }

    private function reviewAverage($reviews, string $key): ?float
    {
        $values = collect($reviews)->pluck($key)->filter(fn ($value) => $value !== null);

        return $values->isEmpty() ? null : round((float) $values->avg(), 1);
    }

    private function cinemaBookingUrl(?object $showing): ?string
    {
        if (! $showing) {
            return null;
        }
        if (filled($showing->external_event_id ?? null)) {
            return 'https://www.cinemacity.cz/cz/booking-router/launch/'.rawurlencode((string) $showing->external_event_id).'?lang=cs';
        }

        return $showing->booking_url ?? null;
    }

    private function eventPayload(CalendarEvent $event): array
    {
        return ['uuid' => $event->uuid, 'title' => $event->title, 'starts_at' => $event->starts_at, 'href' => '/calendar/events/'.$event->uuid];
    }

    private function members(GallerySpace $space): array
    {
        return $space->members()->where('users.is_active', true)->orderBy('users.name')->get(['users.id', 'users.name'])->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->all();
    }

    private function space(Request $request, ?int $id): GallerySpace
    {
        $query = GallerySpace::whereHas('members', fn ($members) => $members->whereKey($request->user()->id));

        return $id ? $query->findOrFail($id) : $query->orderByDesc('is_default')->firstOrFail();
    }

    private function title(Request $request, string $uuid): EntertainmentTitle
    {
        return EntertainmentTitle::where('uuid', $uuid)->whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))->firstOrFail();
    }

    private function proposal(Request $request, string $uuid): object
    {
        $proposal = DB::table('viewing_date_proposals as proposal')->join('entertainment_titles as title', 'title.id', '=', 'proposal.entertainment_title_id')->where('proposal.uuid', $uuid)->whereIn('title.gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))->select('proposal.*')->first();
        abort_unless($proposal, 404);

        return $proposal;
    }

    private function write(Request $request): void
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze filmový plán měnit.');
    }

    private function available(): void
    {
        abort_unless(Schema::hasTable('entertainment_titles') && Schema::hasTable('viewing_date_proposals'), 503, 'Pro filmový plán dokončete databázové migrace.');
    }
}
