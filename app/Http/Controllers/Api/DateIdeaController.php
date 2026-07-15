<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CoupleDateIdea;
use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Planning\DateIdeaGeneratorService;
use App\Services\Planning\DateIdeaLifecycleService;
use App\Services\Planning\DateIdeaPlanningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DateIdeaController extends Controller
{
    public function __construct(
        private readonly DateIdeaGeneratorService $generator,
        private readonly DateIdeaPlanningService $planning,
        private readonly DateIdeaLifecycleService $lifecycle,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['gallery_space_id' => 'required|integer', 'limit' => 'nullable|integer|between:1,30']);
        $space = $this->space($request->user(), (int) $data['gallery_space_id']);
        $ideas = CoupleDateIdea::query()->where('gallery_space_id', $space->id)
            ->with(['reactions.user:id,name', 'event:id,uuid'])->latest()->limit((int) ($data['limit'] ?? 12))->get();

        return response()->json(['ideas' => $ideas->map(fn (CoupleDateIdea $idea) => $this->payload($idea, $request->user()))]);
    }

    public function generate(Request $request): JsonResponse
    {
        $this->write($request);
        $data = $request->validate([
            'gallery_space_id' => 'required|integer',
            'count' => 'nullable|integer|between:1,6',
            'theme' => ['nullable', Rule::in(['surprise','romantic','food','nature','culture','creative','adventure','relax','low_cost'])],
            'budget_max' => 'nullable|numeric|min:0|max:1000000',
            'currency' => 'nullable|string|size:3',
            'travel_scope' => ['nullable', Rule::in(['home','nearby','city','day_trip','weekend'])],
            'transport_mode' => ['nullable', Rule::in(['walk','bike','transit','car','train'])],
            'duration' => ['nullable', Rule::in(['quick','evening','half_day','full_day','weekend'])],
            'time_of_day' => ['nullable', Rule::in(['any','morning','afternoon','evening'])],
            'preferred_date' => 'nullable|date|after_or_equal:today',
            'setting' => ['nullable', Rule::in(['any','indoor','outdoor'])],
            'energy' => ['nullable', Rule::in(['low','medium','high'])],
            'food' => ['nullable', Rule::in(['any','none','cafe','dinner','picnic'])],
            'surprise_level' => 'nullable|integer|between:0,3',
            'accessible_only' => 'nullable|boolean',
            'weather_aware' => 'nullable|boolean',
            'new_places_only' => 'nullable|boolean',
            'destination' => 'nullable|array',
            'destination.location_name' => 'nullable|string|max:255',
            'destination.latitude' => 'nullable|numeric|between:-90,90',
            'destination.longitude' => 'nullable|numeric|between:-180,180',
            'destination.location_country' => 'nullable|string|max:100',
            'destination.location_country_code' => 'nullable|string|max:10',
        ]);
        $space = $this->space($request->user(), (int) $data['gallery_space_id']);
        $ideas = $this->generator->generate($space, $request->user(), $data, (int) ($data['count'] ?? 4));

        return response()->json([
            'ideas' => $ideas->map(fn (CoupleDateIdea $idea) => $this->payload($idea->load(['reactions.user:id,name']), $request->user())),
            'generated_count' => $ideas->count(),
            'message' => $ideas->isEmpty()
                ? 'Pro zadaný rozpočet a omezení nelze sestavit poctivý program. Zvyšte rozpočet, dosah nebo povolte více prostředí.'
                : null,
        ], 201);
    }

    public function react(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $data = $request->validate([
            'reaction' => ['required', Rule::in(['love','maybe','pass'])],
            'rating' => 'nullable|integer|between:1,5',
            'note' => 'nullable|string|max:500',
        ]);
        $idea = $this->idea($request->user(), $uuid);
        $this->lifecycle->recordReaction($idea, $request->user(), $data);

        return response()->json($this->payload($idea->fresh()->load(['reactions.user:id,name', 'event:id,uuid']), $request->user()));
    }

    public function plan(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $data = $request->validate([
            'starts_at' => 'nullable|date|after:now',
            'create_trip' => 'nullable|boolean',
            'reminder_minutes' => 'nullable|integer|between:15,10080',
        ]);
        $idea = $this->idea($request->user(), $uuid);
        $event = $this->planning->plan($idea, $request->user(), $data);

        return response()->json([
            'idea' => $this->payload($idea->fresh()->load(['reactions.user:id,name', 'event:id,uuid']), $request->user()),
            'event_uuid' => $event->uuid,
            'trip_id' => $event->trip_id,
        ], $event->wasRecentlyCreated ? 201 : 200);
    }

    private function payload(CoupleDateIdea $idea, User $viewer): array
    {
        $plan = $idea->plan ?? [];
        $reactions = $idea->relationLoaded('reactions') ? $idea->reactions : collect();
        return [
            'uuid' => $idea->uuid,
            'title' => $idea->title,
            'summary' => $idea->summary,
            'theme' => $idea->theme,
            'status' => $idea->status,
            'travel_scope' => $idea->travel_scope,
            'transport_mode' => $idea->transport_mode,
            'estimated_cost' => $idea->estimated_cost,
            'currency' => $idea->currency,
            'estimated_minutes' => $idea->estimated_minutes,
            'novelty_percent' => $idea->novelty_percent,
            'suggested_starts_at' => $idea->suggested_starts_at?->toIso8601String(),
            'destination' => $idea->destination,
            'parameters' => $idea->parameters,
            'plan' => $plan,
            'is_trip_recommended' => (bool) ($plan['is_trip_recommended'] ?? false),
            'reactions' => $reactions->map(fn ($reaction) => [
                'user_id' => $reaction->user_id,
                'user_name' => $reaction->user?->name,
                'reaction' => $reaction->reaction,
                'rating' => $reaction->rating,
                'note' => $reaction->note,
            ])->values(),
            'my_reaction' => $reactions->firstWhere('user_id', $viewer->id)?->reaction,
            'event_uuid' => $idea->event?->uuid,
            'trip_id' => $idea->trip_id,
            'created_at' => $idea->created_at?->toIso8601String(),
        ];
    }

    private function space(User $user, int $id): GallerySpace
    {
        return $user->gallerySpaces()->whereKey($id)->firstOrFail();
    }

    private function idea(User $user, string $uuid): CoupleDateIdea
    {
        return CoupleDateIdea::query()->where('uuid', $uuid)
            ->whereIn('gallery_space_id', $user->gallerySpaces()->pluck('gallery_spaces.id'))
            ->with(['space', 'event'])->firstOrFail();
    }

    private function write(Request $request): void
    {
        abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze randíčka měnit.');
    }
}
