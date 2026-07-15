<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CalendarEvent;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\RecipeCookingSession;
use App\Models\User;
use App\Services\Recipes\MealPlanService;
use App\Services\Recipes\RecipeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MealPlanController extends Controller
{
    public function __construct(private readonly MealPlanService $mealPlans, private readonly RecipeService $recipes) {}

    public function eventIndex(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->mealPlans->forEvent($this->event($request, $uuid), $request->user()));
    }

    public function eventStore(Request $request, string $uuid): JsonResponse
    {
        $this->write($request); $event = $this->event($request, $uuid); $data = $this->validated($request);
        $recipe = $this->recipe($request->user(), $event->gallery_space_id, $data['recipe_uuid']);
        $plannedFor = Carbon::parse($data['planned_for'] ?? $event->starts_at);
        $album = $this->recipes->ensureAlbum($recipe, $request->user());
        $meal = DB::transaction(function () use ($request, $event, $recipe, $album, $data, $plannedFor) {
            $session = RecipeCookingSession::create([
                'recipe_id' => $recipe->id, 'created_by' => $request->user()->id, 'calendar_event_id' => $event->id,
                'album_id' => $album->id, 'status' => 'planned', 'planned_for' => $plannedFor,
                'servings' => $data['servings'], 'currency' => $recipe->currency,
                'notes' => $data['notes'] ?? null, 'recipe_snapshot' => $this->snapshot($recipe, (float) $data['servings']),
            ]);
            return PlannedMeal::create($this->mealRow($recipe, $request->user()->id, $data, $plannedFor) + [
                'gallery_space_id' => $event->gallery_space_id, 'calendar_event_id' => $event->id,
                'trip_id' => $event->trip_id, 'cooking_session_id' => $session->id,
            ]);
        });
        AuditLog::record('meal-plan.event.add', $meal, ['event_uuid' => $event->uuid, 'recipe_uuid' => $recipe->uuid]);
        return response()->json($this->mealPlans->forEvent($event, $request->user()), 201);
    }

    public function tripIndex(Request $request, int $tripId): JsonResponse
    {
        return response()->json($this->mealPlans->forTrip($this->trip($request, $tripId), $request->user()));
    }

    public function tripStore(Request $request, int $tripId): JsonResponse
    {
        $this->write($request); $trip = $this->trip($request, $tripId); $data = $this->validated($request, true);
        $recipe = $this->recipe($request->user(), $trip->gallery_space_id, $data['recipe_uuid']);
        $day = DB::table('trip_days')->where('id', $data['trip_day_id'])->where('trip_id', $trip->id)->firstOrFail();
        $plannedFor = Carbon::parse($data['planned_for'] ?? ($day->date . ' 18:00:00'));
        abort_unless($plannedFor->toDateString() === $day->date, 422, 'Čas jídla musí odpovídat zvolenému dni cesty.');
        $album = $this->recipes->ensureAlbum($recipe, $request->user());
        $meal = DB::transaction(function () use ($request, $trip, $day, $recipe, $album, $data, $plannedFor) {
            $meal = PlannedMeal::create($this->mealRow($recipe, $request->user()->id, $data, $plannedFor) + [
                'gallery_space_id' => $trip->gallery_space_id, 'trip_id' => $trip->id, 'trip_day_id' => $day->id,
            ]);
            $duration = max(30, (int) $recipe->prep_minutes + (int) $recipe->cook_minutes + (int) $recipe->rest_minutes);
            $event = CalendarEvent::create([
                'gallery_space_id' => $trip->gallery_space_id, 'created_by' => $request->user()->id, 'trip_id' => $trip->id,
                'album_id' => $album->id, 'title' => 'Jídlo na cestě · ' . $recipe->title,
                'description' => 'Součást jídelního plánu cesty „' . $trip->name . '“ pro ' . $data['servings'] . ' porcí.' . (! empty($data['notes']) ? "\n\n" . $data['notes'] : ''),
                'type' => 'meal', 'status' => 'planned', 'starts_at' => $plannedFor, 'ends_at' => $plannedFor->copy()->addMinutes($duration),
                'timezone' => 'Europe/Prague', 'color' => '#f59e0b', 'is_private' => false,
                'metadata' => ['kind' => 'trip_recipe_meal', 'source' => 'meal_plan', 'recipe_uuid' => $recipe->uuid, 'planned_meal_uuid' => $meal->uuid, 'trip_id' => $trip->id, 'href' => '/recipes/' . $recipe->uuid],
            ]);
            $session = RecipeCookingSession::create([
                'recipe_id' => $recipe->id, 'created_by' => $request->user()->id, 'calendar_event_id' => $event->id,
                'album_id' => $album->id, 'status' => 'planned', 'planned_for' => $plannedFor, 'servings' => $data['servings'],
                'currency' => $recipe->currency, 'notes' => $data['notes'] ?? null, 'recipe_snapshot' => $this->snapshot($recipe, (float) $data['servings']),
            ]);
            $startTime = $plannedFor->format('H:i'); $endTime = $plannedFor->copy()->addMinutes($duration)->format('H:i');
            $activityId = DB::table('trip_activities')->insertGetId([
                'trip_day_id' => $day->id, 'created_by' => $request->user()->id, 'type' => 'activity',
                'title' => '🍳 ' . $recipe->title, 'description' => $data['notes'] ?? 'Společné jídlo z naší kuchařky.',
                'starts_at' => $startTime, 'ends_at' => $endTime, 'status' => 'planned', 'currency' => $trip->currency ?: 'CZK',
                'sort_order' => ((int) DB::table('trip_activities')->where('trip_day_id', $day->id)->max('sort_order')) + 1,
                'metadata' => json_encode(['kind' => 'recipe_meal', 'recipe_uuid' => $recipe->uuid, 'planned_meal_uuid' => $meal->uuid, 'calendar_event_uuid' => $event->uuid]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $meal->update(['calendar_event_id' => $event->id, 'cooking_session_id' => $session->id, 'trip_activity_id' => $activityId]);
            $members = DB::table('gallery_space_user')->where('gallery_space_id', $trip->gallery_space_id)->pluck('user_id');
            foreach ($members as $memberId) {
                DB::table('event_participants')->insertOrIgnore(['event_id' => $event->id, 'user_id' => $memberId, 'role' => (int) $memberId === (int) $request->user()->id ? 'organizer' : 'guest', 'response' => (int) $memberId === (int) $request->user()->id ? 'accepted' : 'pending', 'created_at' => now(), 'updated_at' => now()]);
                if ($plannedFor->isAfter(now()->addHours(2))) DB::table('event_reminders')->insert(['event_id' => $event->id, 'user_id' => $memberId, 'channel' => 'database', 'remind_at' => $plannedFor->copy()->subHours(2), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            }
            return $meal;
        });
        AuditLog::record('meal-plan.trip.add', $meal, ['trip_id' => $trip->id, 'recipe_uuid' => $recipe->uuid]);
        return response()->json($this->mealPlans->forTrip($trip, $request->user()), 201);
    }

    public function eventShopping(Request $request, string $uuid, string $key): JsonResponse
    {
        $this->write($request); $event = $this->event($request, $uuid);
        $item = $this->mealPlans->itemForEvent($event, $request->user(), $key); abort_unless($item, 404);
        $this->saveShopping($request, $item, $event->gallery_space_id, $event, null);
        return response()->json($this->mealPlans->forEvent($event->fresh(), $request->user()));
    }

    public function tripShopping(Request $request, int $tripId, string $key): JsonResponse
    {
        $this->write($request); $trip = $this->trip($request, $tripId);
        $item = $this->mealPlans->itemForTrip($trip, $request->user(), $key); abort_unless($item, 404);
        $this->saveShopping($request, $item, $trip->gallery_space_id, null, $trip);
        return response()->json($this->mealPlans->forTrip($trip, $request->user()));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->write($request);
        $meal = PlannedMeal::where('uuid', $uuid)->whereIn('gallery_space_id', $this->spaceIds($request->user()))->firstOrFail();
        $event = $meal->calendar_event_id ? CalendarEvent::find($meal->calendar_event_id) : null;
        $generatedEvent = $event && (($event->metadata['kind'] ?? null) === 'trip_recipe_meal');
        $trip = $meal->trip_id ? DB::table('trips')->find($meal->trip_id) : null;
        DB::transaction(function () use ($meal, $event, $generatedEvent) {
            if ($meal->trip_activity_id) DB::table('trip_activities')->where('id', $meal->trip_activity_id)->delete();
            if ($meal->cooking_session_id) RecipeCookingSession::whereKey($meal->cooking_session_id)->where('status', '!=', 'completed')->delete();
            if ($generatedEvent) $event->delete();
            $meal->delete();
        });
        if ($event && ! $generatedEvent && $event->exists) {
            $this->reconcileShopping($meal->gallery_space_id, $event->id, null, $this->mealPlans->forEvent($event->fresh(), $request->user())['shopping']);
        }
        if ($trip) {
            $this->reconcileShopping($meal->gallery_space_id, null, $trip->id, $this->mealPlans->forTrip($trip, $request->user())['shopping']);
        }
        return response()->json(['status' => 'deleted']);
    }

    private function reconcileShopping(int $spaceId, ?int $eventId, ?int $tripId, iterable $activeItems): void
    {
        $active = collect($activeItems)->keyBy('key');
        $states = DB::table('meal_shopping_states')->where('gallery_space_id', $spaceId)
            ->where('calendar_event_id', $eventId)->where('trip_id', $tripId)->get();
        foreach ($states as $state) {
            $item = $active->get($state->item_key);
            if (! $item) {
                if ($state->event_task_id) DB::table('event_tasks')->where('id', $state->event_task_id)->delete();
                if ($state->packing_item_id) DB::table('trip_packing_items')->where('id', $state->packing_item_id)->delete();
                DB::table('meal_shopping_states')->where('id', $state->id)->delete();
                continue;
            }
            $title = $this->shoppingTitle($item);
            if ($state->event_task_id) DB::table('event_tasks')->where('id', $state->event_task_id)->update(['title' => $title, 'updated_at' => now()]);
            if ($state->packing_item_id) DB::table('trip_packing_items')->where('id', $state->packing_item_id)->update(['title' => $title, 'updated_at' => now()]);
        }
    }

    private function saveShopping(Request $request, array $item, int $spaceId, ?CalendarEvent $event, ?object $trip): void
    {
        $data = $request->validate(['is_checked' => 'nullable|boolean', 'assigned_to' => 'nullable|integer', 'note' => 'nullable|string|max:1000']);
        if (! empty($data['assigned_to'])) abort_unless(DB::table('gallery_space_user')->where('gallery_space_id', $spaceId)->where('user_id', $data['assigned_to'])->exists(), 422, 'Položku lze přiřadit pouze členovi společného prostoru.');
        $lookup = ['gallery_space_id' => $spaceId, 'item_key' => $item['key'], 'calendar_event_id' => $event?->id, 'trip_id' => $trip?->id];
        $existing = DB::table('meal_shopping_states')->where($lookup)->first();
        $checked = (bool) ($data['is_checked'] ?? $existing?->is_checked ?? false);
        $assigned = array_key_exists('assigned_to', $data) ? $data['assigned_to'] : $existing?->assigned_to;
        $title = $this->shoppingTitle($item);
        $taskId = $existing?->event_task_id; $packingId = $existing?->packing_item_id;
        if ($event) {
            if (! $taskId) $taskId = DB::table('event_tasks')->insertGetId(['event_id' => $event->id, 'assigned_to' => $assigned, 'title' => $title, 'notes' => $data['note'] ?? null, 'due_at' => $event->starts_at->copy()->subHour(), 'completed_at' => $checked ? now() : null, 'priority' => 'normal', 'sort_order' => ((int) DB::table('event_tasks')->where('event_id', $event->id)->max('sort_order')) + 1, 'created_at' => now(), 'updated_at' => now()]);
            else DB::table('event_tasks')->where('id', $taskId)->update(['assigned_to' => $assigned, 'title' => $title, 'notes' => $data['note'] ?? $existing?->note, 'completed_at' => $checked ? ($existing?->is_checked ? DB::table('event_tasks')->where('id', $taskId)->value('completed_at') ?? now() : now()) : null, 'updated_at' => now()]);
        }
        if ($trip) {
            if (! $packingId) $packingId = DB::table('trip_packing_items')->insertGetId(['uuid' => (string) Str::uuid(), 'trip_id' => $trip->id, 'created_by' => $request->user()->id, 'assigned_to' => $assigned, 'title' => $title, 'category' => 'food', 'quantity' => 1, 'is_essential' => false, 'is_packed' => $checked, 'packed_at' => $checked ? now() : null, 'packed_by' => $checked ? $request->user()->id : null, 'source_template' => 'meal_plan', 'sort_order' => ((int) DB::table('trip_packing_items')->where('trip_id', $trip->id)->max('sort_order')) + 1, 'created_at' => now(), 'updated_at' => now()]);
            else DB::table('trip_packing_items')->where('id', $packingId)->update(['assigned_to' => $assigned, 'title' => $title, 'is_packed' => $checked, 'packed_at' => $checked ? ($existing?->is_checked ? DB::table('trip_packing_items')->where('id', $packingId)->value('packed_at') ?? now() : now()) : null, 'packed_by' => $checked ? $request->user()->id : null, 'updated_at' => now()]);
        }
        DB::table('meal_shopping_states')->updateOrInsert($lookup, [
            'assigned_to' => $assigned, 'checked_by' => $checked ? $request->user()->id : null,
            'event_task_id' => $taskId, 'packing_item_id' => $packingId, 'is_checked' => $checked,
            'note' => $data['note'] ?? $existing?->note, 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
        ]);
    }

    private function shoppingTitle(array $item): string
    {
        return 'Nakoupit: ' . ($item['display_quantity'] ? $item['display_quantity'] . ' ' : '') . ($item['unit'] ? $item['unit'] . ' ' : '') . $item['name'];
    }

    private function validated(Request $request, bool $trip = false): array
    {
        return $request->validate([
            'recipe_uuid' => 'required|uuid', 'meal_type' => 'required|in:breakfast,lunch,dinner,snack,picnic,other',
            'servings' => 'required|numeric|between:0.25,1000', 'planned_for' => 'nullable|date',
            'trip_day_id' => ($trip ? 'required' : 'nullable') . '|integer', 'notes' => 'nullable|string|max:5000',
        ]);
    }

    private function mealRow(Recipe $recipe, int $userId, array $data, Carbon $plannedFor): array
    {
        $factor = (float) $data['servings'] / max(.01, (float) $recipe->base_servings);
        return ['recipe_id' => $recipe->id, 'created_by' => $userId, 'meal_type' => $data['meal_type'], 'planned_for' => $plannedFor, 'servings' => $data['servings'], 'status' => 'planned', 'notes' => $data['notes'] ?? null, 'estimated_cost' => $recipe->estimated_cost !== null ? round((float) $recipe->estimated_cost * $factor, 2) : null, 'currency' => $recipe->currency];
    }
    private function snapshot(Recipe $recipe, float $servings): array { return collect($this->recipes->payload($recipe, $servings))->only(['title', 'selected_servings', 'scale_factor', 'ingredients', 'steps', 'prep_minutes', 'cook_minutes', 'rest_minutes'])->all(); }
    private function recipe(User $user, int $spaceId, string $uuid): Recipe { return Recipe::where('uuid', $uuid)->where('gallery_space_id', $spaceId)->where(fn ($query) => $query->where('status', 'published')->orWhere('created_by', $user->id))->firstOrFail(); }
    private function event(Request $request, string $uuid): CalendarEvent { $this->available(); return CalendarEvent::where('uuid', $uuid)->whereIn('gallery_space_id', $this->spaceIds($request->user()))->where(fn ($query) => $query->where('is_private', false)->orWhere('created_by', $request->user()->id)->orWhereHas('participants', fn ($participants) => $participants->whereKey($request->user()->id)))->firstOrFail(); }
    private function trip(Request $request, int $id): object { $this->available(); return DB::table('trips')->where('id', $id)->whereIn('gallery_space_id', $this->spaceIds($request->user()))->firstOrFail(); }
    private function spaceIds(User $user): array { return $user->gallerySpaces()->pluck('gallery_spaces.id')->map(fn ($id) => (int) $id)->all(); }
    private function write(Request $request): void { abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze jídelní plán měnit.'); }
    private function available(): void { abort_unless(Schema::hasTable('planned_meals') && Schema::hasTable('meal_shopping_states'), 503, 'Pro propojené jídelní plánování dokončete databázové migrace.'); }
}
