<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CalendarEvent;
use App\Models\MediaItem;
use App\Models\Recipe;
use App\Models\RecipeCookingSession;
use App\Services\Recipes\RecipeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class RecipeCookingController extends Controller
{
    public function __construct(private readonly RecipeService $recipes) {}

    public function schedule(Request $request, string $uuid): JsonResponse
    {
        $this->write($request); $recipe = $this->recipe($request, $uuid);
        $data = $request->validate(['planned_for' => 'required|date', 'servings' => 'required|numeric|between:0.25,1000', 'notes' => 'nullable|string|max:5000', 'add_to_calendar' => 'nullable|boolean']);
        $planned = Carbon::parse($data['planned_for']);
        $session = DB::transaction(function () use ($request, $recipe, $data, $planned) {
            $session = RecipeCookingSession::create([
                'recipe_id' => $recipe->id, 'created_by' => $request->user()->id, 'status' => 'planned',
                'planned_for' => $planned, 'servings' => $data['servings'], 'notes' => $data['notes'] ?? null,
                'currency' => $recipe->currency, 'recipe_snapshot' => $this->snapshot($recipe, (float) $data['servings']),
            ]);
            if ($data['add_to_calendar'] ?? true) {
                $duration = max(30, (int) $recipe->prep_minutes + (int) $recipe->cook_minutes + (int) $recipe->rest_minutes);
                $event = CalendarEvent::create([
                    'gallery_space_id' => $recipe->gallery_space_id, 'created_by' => $request->user()->id,
                    'album_id' => $recipe->album_id, 'title' => 'Vaření · ' . $recipe->title,
                    'description' => 'Společné vaření receptu pro ' . $data['servings'] . ' porcí.' . (! empty($data['notes']) ? "\n\n" . $data['notes'] : ''),
                    'type' => 'meal', 'status' => 'planned', 'starts_at' => $planned, 'ends_at' => $planned->copy()->addMinutes($duration),
                    'timezone' => 'Europe/Prague', 'color' => '#f59e0b', 'is_private' => false,
                    'metadata' => ['kind' => 'recipe_cooking', 'source' => 'recipe', 'recipe_uuid' => $recipe->uuid, 'cooking_session_uuid' => $session->uuid, 'href' => '/recipes/' . $recipe->uuid],
                ]);
                $members = DB::table('gallery_space_user')->where('gallery_space_id', $recipe->gallery_space_id)->pluck('user_id');
                foreach ($members as $memberId) {
                    DB::table('event_participants')->insertOrIgnore(['event_id' => $event->id, 'user_id' => $memberId, 'role' => (int) $memberId === (int) $request->user()->id ? 'organizer' : 'guest', 'response' => (int) $memberId === (int) $request->user()->id ? 'accepted' : 'pending', 'created_at' => now(), 'updated_at' => now()]);
                    if ($planned->isAfter(now()->addHours(2))) DB::table('event_reminders')->insert(['event_id' => $event->id, 'user_id' => $memberId, 'channel' => 'database', 'remind_at' => $planned->copy()->subHours(2), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                }
                $session->update(['calendar_event_id' => $event->id]);
            }
            return $session;
        });
        AuditLog::record('recipe.cooking.schedule', $session, ['recipe_uuid' => $recipe->uuid]);
        return response()->json($this->recipes->sessionPayload($session->fresh()), 201);
    }

    public function start(Request $request, string $uuid): JsonResponse
    {
        $this->write($request); $recipe = $this->recipe($request, $uuid);
        $data = $request->validate(['session_uuid' => 'nullable|uuid', 'servings' => 'required|numeric|between:0.25,1000']);
        $session = ! empty($data['session_uuid']) ? RecipeCookingSession::where('uuid', $data['session_uuid'])->where('recipe_id', $recipe->id)->firstOrFail() : new RecipeCookingSession(['recipe_id' => $recipe->id, 'created_by' => $request->user()->id]);
        abort_if(in_array($session->status, ['completed', 'cancelled'], true), 422, 'Toto vaření již nelze znovu spustit.');
        $session->fill(['status' => 'cooking', 'servings' => $data['servings'], 'started_at' => $session->started_at ?? now(), 'currency' => $recipe->currency, 'recipe_snapshot' => $this->snapshot($recipe, (float) $data['servings'])])->save();
        AuditLog::record('recipe.cooking.start', $session, ['recipe_uuid' => $recipe->uuid]);
        return response()->json($this->recipes->sessionPayload($session->fresh()), $session->wasRecentlyCreated ? 201 : 200);
    }

    public function complete(Request $request, string $uuid, string $sessionUuid): JsonResponse
    {
        $this->write($request); $recipe = $this->recipe($request, $uuid);
        $session = RecipeCookingSession::where('uuid', $sessionUuid)->where('recipe_id', $recipe->id)->firstOrFail();
        $rating = 'nullable|numeric|between:1,5';
        $data = $request->validate([
            'cooked_at' => 'nullable|date', 'overall_rating' => 'required|numeric|between:1,5', 'taste_rating' => $rating,
            'process_rating' => $rating, 'appearance_rating' => $rating, 'actual_duration_minutes' => 'nullable|integer|between:0,10080',
            'actual_cost' => 'nullable|numeric|between:0,9999999999.99', 'currency' => 'nullable|string|size:3',
            'notes' => 'nullable|string|max:10000', 'successes' => 'nullable|string|max:10000', 'failures' => 'nullable|string|max:10000',
            'improvements' => 'nullable|string|max:10000', 'changes_made' => 'nullable|string|max:10000', 'partner_feedback' => 'nullable|string|max:10000',
            'would_cook_again' => 'nullable|boolean', 'leftovers_notes' => 'nullable|string|max:5000',
            'media_uuids' => 'nullable|array|max:50', 'media_uuids.*' => 'uuid|distinct',
        ]);
        $media = MediaItem::where('gallery_space_id', $recipe->gallery_space_id)->whereNull('trashed_at')->whereIn('uuid', $data['media_uuids'] ?? [])->get();
        if ($media->count() !== count($data['media_uuids'] ?? [])) throw ValidationException::withMessages(['media_uuids' => 'Některá fotografie není dostupná v této společné galerii.']);
        $finished = now(); $started = $session->started_at ?? $finished;
        $session->update(collect($data)->except('media_uuids')->all() + [
            'status' => 'completed', 'cooked_at' => $data['cooked_at'] ?? $finished,
            'finished_at' => $finished, 'actual_duration_minutes' => $data['actual_duration_minutes'] ?? max(0, $started->diffInMinutes($finished)),
            'album_id' => $this->recipes->ensureAlbum($recipe, $request->user())->id,
        ]);
        if ($media->isNotEmpty()) $this->recipes->attachMedia($recipe, $request->user(), $media, $session, 'result');
        if (Schema::hasTable('planned_meals')) {
            DB::table('planned_meals')->where('cooking_session_id', $session->id)->update(['status' => 'prepared', 'updated_at' => now()]);
        }
        $this->finishGeneratedEvent($session, 'completed', $recipe->fresh()->album_id);
        AuditLog::record('recipe.cooking.complete', $session, ['recipe_uuid' => $recipe->uuid, 'rating' => $session->overall_rating]);
        return response()->json(['session' => $this->recipes->sessionPayload($session->fresh()), 'recipe' => $this->recipes->payload($recipe->fresh())]);
    }

    public function cancel(Request $request, string $uuid, string $sessionUuid): JsonResponse
    {
        $this->write($request); $recipe = $this->recipe($request, $uuid);
        $session = RecipeCookingSession::where('uuid', $sessionUuid)->where('recipe_id', $recipe->id)->firstOrFail();
        abort_if($session->status === 'completed', 422, 'Dokončené vaření nelze zrušit.');
        $session->update(['status' => 'cancelled']);
        if (Schema::hasTable('planned_meals')) {
            DB::table('planned_meals')->where('cooking_session_id', $session->id)->update(['status' => 'skipped', 'updated_at' => now()]);
        }
        $this->finishGeneratedEvent($session, 'cancelled');
        return response()->json(['status' => 'cancelled']);
    }

    private function finishGeneratedEvent(RecipeCookingSession $session, string $status, ?int $albumId = null): void
    {
        if (! $session->calendar_event_id) return;
        $event = CalendarEvent::find($session->calendar_event_id);
        if (! $event || ! in_array($event->metadata['kind'] ?? null, ['recipe_cooking', 'trip_recipe_meal'], true)) return;
        $values = ['status' => $status];
        if ($albumId) $values['album_id'] = $albumId;
        $event->update($values);
    }

    private function snapshot(Recipe $recipe, float $servings): array
    {
        $payload = $this->recipes->payload($recipe, $servings);
        return collect($payload)->only(['title', 'selected_servings', 'scale_factor', 'ingredients', 'steps', 'prep_minutes', 'cook_minutes', 'rest_minutes'])->all();
    }
    private function recipe(Request $request, string $uuid): Recipe { return Recipe::where('uuid', $uuid)->whereIn('gallery_space_id', $request->user()->gallerySpaces()->pluck('gallery_spaces.id'))->where(fn ($query) => $query->where('status', 'published')->orWhere('created_by', $request->user()->id))->firstOrFail(); }
    private function write(Request $request): void { abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze vaření měnit.'); }
}
