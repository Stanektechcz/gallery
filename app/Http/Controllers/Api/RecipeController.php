<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MediaItem;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Recipes\RecipeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class RecipeController extends Controller
{
    public function __construct(private readonly RecipeService $recipes) {}

    public function index(Request $request): JsonResponse
    {
        $this->available();
        $filters = $request->validate([
            'q' => 'nullable|string|max:120', 'category' => 'nullable|string|max:40',
            'difficulty' => 'nullable|in:easy,medium,hard', 'favorite' => 'nullable|boolean',
            'dietary' => 'nullable|string|max:40', 'sort' => 'nullable|in:newest,title,rating,cooked',
        ]);
        $query = Recipe::query()
            ->whereIn('gallery_space_id', $this->spaceIds($request->user()))
            ->where(fn ($q) => $q->where('status', 'published')->orWhere('created_by', $request->user()->id))
            ->when(trim((string) ($filters['q'] ?? '')), function ($q, $term) {
                $like = '%' . trim($term) . '%';
                $q->where(fn ($search) => $search->where('title', 'like', $like)->orWhere('summary', 'like', $like)->orWhere('cuisine', 'like', $like)->orWhereHas('ingredients', fn ($ingredients) => $ingredients->where('name', 'like', $like)));
            })
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when($filters['difficulty'] ?? null, fn ($q, $difficulty) => $q->where('difficulty', $difficulty))
            ->when(array_key_exists('favorite', $filters), fn ($q) => $q->where('is_favorite', (bool) $filters['favorite']))
            ->when($filters['dietary'] ?? null, fn ($q, $tag) => $q->whereJsonContains('dietary_tags', $tag))
            ->with(['cover.variants', 'album'])
            ->withCount(['completedSessions as list_times_cooked'])
            ->withAvg(['completedSessions as list_average_rating'], 'overall_rating')
            ->withAvg(['completedSessions as list_average_taste_rating'], 'taste_rating')
            ->withMax(['completedSessions as list_last_cooked_at'], 'cooked_at')
            ->withMin(['cookingSessions as list_next_planned_for' => fn ($session) => $session->where('status', 'planned')->where('planned_for', '>=', now())], 'planned_for');
        match ($filters['sort'] ?? 'newest') {
            'title' => $query->orderBy('title'),
            'rating' => $query->withAvg(['completedSessions as list_rating'], 'overall_rating')->orderByDesc('list_rating'),
            'cooked' => $query->withMax(['completedSessions as list_last_cooked'], 'cooked_at')->orderByDesc('list_last_cooked'),
            default => $query->latest(),
        };
        $items = $query->limit(100)->get()->map(fn (Recipe $recipe) => $this->recipes->payload($recipe, null, false))->values();
        $spaceId = (int) ($items->first()['gallery_space_id'] ?? $request->user()->gallerySpaces()->value('gallery_spaces.id'));

        return response()->json([
            'items' => $items,
            'gallery_space_id' => $spaceId,
            'summary' => [
                'recipes' => Recipe::whereIn('gallery_space_id', $this->spaceIds($request->user()))->count(),
                'favorites' => Recipe::whereIn('gallery_space_id', $this->spaceIds($request->user()))->where('is_favorite', true)->count(),
                'cookings' => DB::table('recipe_cooking_sessions as session')->join('recipes as recipe', 'recipe.id', '=', 'session.recipe_id')->whereIn('recipe.gallery_space_id', $this->spaceIds($request->user()))->where('session.status', 'completed')->count(),
            ],
        ]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $recipe = $this->recipe($request, $uuid);
        $servings = $request->validate(['servings' => 'nullable|numeric|between:0.25,1000'])['servings'] ?? null;
        $recentMedia = MediaItem::where('gallery_space_id', $recipe->gallery_space_id)->whereNull('trashed_at')->where('is_hidden', false)
            ->whereIn('media_type', ['photo', 'video'])->with(['variants' => fn ($q) => $q->whereIn('type', ['thumbnail', 'small'])])
            ->latest('taken_at')->limit(36)->get()->map(fn (MediaItem $media) => $this->recipes->mediaPayload($media))->values();

        return response()->json($this->recipes->payload($recipe, $servings ? (float) $servings : null) + ['available_media' => $recentMedia]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->available(); $this->write($request);
        $data = $this->validated($request);
        $spaceId = (int) ($data['gallery_space_id'] ?? $request->user()->gallerySpaces()->value('gallery_spaces.id'));
        abort_unless(in_array($spaceId, $this->spaceIds($request->user()), true), 403);
        $recipe = DB::transaction(function () use ($request, $data, $spaceId) {
            $recipe = Recipe::create($this->recipeData($data) + ['gallery_space_id' => $spaceId, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $this->syncParts($recipe, $data);
            return $recipe;
        });
        $this->recipes->ensureAlbum($recipe, $request->user());
        $this->syncCover($recipe, $request->user(), $data['cover_media_uuid'] ?? null);
        AuditLog::record('recipe.create', $recipe, ['title' => $recipe->title]);
        return response()->json($this->recipes->payload($recipe->fresh()), 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $this->write($request); $recipe = $this->recipe($request, $uuid);
        $data = $this->validated($request);
        DB::transaction(function () use ($request, $recipe, $data) {
            $recipe->update($this->recipeData($data) + ['updated_by' => $request->user()->id]);
            $this->syncParts($recipe, $data);
        });
        $this->syncCover($recipe, $request->user(), $data['cover_media_uuid'] ?? null);
        AuditLog::record('recipe.update', $recipe, ['title' => $recipe->title]);
        return response()->json($this->recipes->payload($recipe->fresh()));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->write($request); $recipe = $this->recipe($request, $uuid);
        AuditLog::record('recipe.delete', $recipe, ['title' => $recipe->title]);
        $recipe->delete();
        return response()->json(['status' => 'deleted']);
    }

    public function toggleFavorite(Request $request, string $uuid): JsonResponse
    {
        $this->write($request); $recipe = $this->recipe($request, $uuid);
        $recipe->update(['is_favorite' => ! $recipe->is_favorite, 'updated_by' => $request->user()->id]);
        return response()->json(['is_favorite' => $recipe->is_favorite]);
    }

    public function ensureAlbum(Request $request, string $uuid): JsonResponse
    {
        $this->write($request); $recipe = $this->recipe($request, $uuid);
        $album = $this->recipes->ensureAlbum($recipe, $request->user());
        return response()->json(['album' => $album->only(['uuid', 'title'])]);
    }

    public function attachMedia(Request $request, string $uuid): JsonResponse
    {
        $this->write($request); $recipe = $this->recipe($request, $uuid);
        $data = $request->validate(['media_uuids' => 'required|array|min:1|max:50', 'media_uuids.*' => 'uuid|distinct', 'role' => 'nullable|in:gallery,cover,preparation,result']);
        $media = MediaItem::where('gallery_space_id', $recipe->gallery_space_id)->whereNull('trashed_at')->whereIn('uuid', $data['media_uuids'])->get();
        if ($media->count() !== count($data['media_uuids'])) throw ValidationException::withMessages(['media_uuids' => 'Některé médium není dostupné v této společné galerii.']);
        $this->recipes->attachMedia($recipe, $request->user(), $media, null, $data['role'] ?? 'gallery');
        return response()->json($this->recipes->payload($recipe->fresh()));
    }

    public function shoppingList(Request $request, string $uuid): JsonResponse
    {
        $recipe = $this->recipe($request, $uuid);
        $servings = (float) ($request->validate(['servings' => 'nullable|numeric|between:0.25,1000'])['servings'] ?? $recipe->base_servings);
        $payload = $this->recipes->payload($recipe, $servings);
        return response()->json([
            'recipe' => ['uuid' => $recipe->uuid, 'title' => $recipe->title], 'servings' => $servings,
            'sections' => collect($payload['ingredients'])->groupBy(fn ($item) => $item['section'] ?: 'Suroviny')->map(fn ($items, $section) => ['section' => $section, 'items' => $items->values()])->values(),
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'gallery_space_id' => 'nullable|integer', 'title' => 'required|string|max:180', 'summary' => 'nullable|string|max:2000',
            'description' => 'nullable|string|max:20000', 'category' => 'required|in:breakfast,soup,main_course,side,salad,dessert,baking,snack,drink,sauce,other',
            'cuisine' => 'nullable|string|max:80', 'difficulty' => 'required|in:easy,medium,hard', 'status' => 'required|in:draft,published',
            'base_servings' => 'required|numeric|between:0.25,1000', 'prep_minutes' => 'nullable|integer|between:0,10080',
            'cook_minutes' => 'nullable|integer|between:0,10080', 'rest_minutes' => 'nullable|integer|between:0,10080',
            'estimated_cost' => 'nullable|numeric|between:0,9999999999.99', 'currency' => 'required|string|size:3',
            'calories_per_serving' => 'nullable|numeric|between:0,100000', 'protein_per_serving' => 'nullable|numeric|between:0,10000',
            'carbs_per_serving' => 'nullable|numeric|between:0,10000', 'fat_per_serving' => 'nullable|numeric|between:0,10000',
            'dietary_tags' => 'nullable|array|max:30', 'dietary_tags.*' => 'string|max:40', 'occasion_tags' => 'nullable|array|max:30', 'occasion_tags.*' => 'string|max:40',
            'equipment' => 'nullable|array|max:30', 'equipment.*' => 'string|max:100', 'source_name' => 'nullable|string|max:180', 'source_url' => 'nullable|url|max:2048',
            'tips' => 'nullable|string|max:10000', 'storage_notes' => 'nullable|string|max:5000', 'reheating_notes' => 'nullable|string|max:5000',
            'is_favorite' => 'nullable|boolean', 'cover_media_uuid' => 'nullable|uuid',
            'ingredients' => 'required|array|min:1|max:200', 'ingredients.*.section' => 'nullable|string|max:100', 'ingredients.*.name' => 'required|string|max:180',
            'ingredients.*.quantity' => 'nullable|numeric|between:0,99999999', 'ingredients.*.unit' => 'nullable|string|max:32',
            'ingredients.*.quantity_note' => 'nullable|string|max:120', 'ingredients.*.is_scalable' => 'nullable|boolean', 'ingredients.*.is_optional' => 'nullable|boolean',
            'ingredients.*.is_pantry' => 'nullable|boolean', 'ingredients.*.preparation' => 'nullable|string|max:255', 'ingredients.*.substitutes' => 'nullable|string|max:3000',
            'steps' => 'required|array|min:1|max:100', 'steps.*.title' => 'nullable|string|max:180', 'steps.*.instruction' => 'required|string|max:20000',
            'steps.*.timer_seconds' => 'nullable|integer|between:0,604800', 'steps.*.temperature' => 'nullable|numeric|between:-100,1000',
            'steps.*.temperature_unit' => 'nullable|in:C,F', 'steps.*.equipment' => 'nullable|string|max:255', 'steps.*.tip' => 'nullable|string|max:5000',
        ]);
    }

    private function recipeData(array $data): array
    {
        return collect($data)->only(['title', 'summary', 'description', 'category', 'cuisine', 'difficulty', 'status', 'base_servings', 'prep_minutes', 'cook_minutes', 'rest_minutes', 'estimated_cost', 'currency', 'calories_per_serving', 'protein_per_serving', 'carbs_per_serving', 'fat_per_serving', 'dietary_tags', 'occasion_tags', 'equipment', 'source_name', 'source_url', 'tips', 'storage_notes', 'reheating_notes', 'is_favorite'])->all();
    }

    private function syncParts(Recipe $recipe, array $data): void
    {
        $recipe->ingredients()->delete();
        foreach ($data['ingredients'] as $index => $ingredient) $recipe->ingredients()->create(collect($ingredient)->only(['section', 'name', 'quantity', 'unit', 'quantity_note', 'is_scalable', 'is_optional', 'is_pantry', 'preparation', 'substitutes'])->all() + ['sort_order' => $index]);
        $recipe->steps()->delete();
        foreach ($data['steps'] as $index => $step) $recipe->steps()->create(collect($step)->only(['title', 'instruction', 'timer_seconds', 'temperature', 'temperature_unit', 'equipment', 'tip'])->all() + ['sort_order' => $index]);
    }

    private function syncCover(Recipe $recipe, User $user, ?string $uuid): void
    {
        if (! $uuid) return;
        $media = MediaItem::where('uuid', $uuid)->where('gallery_space_id', $recipe->gallery_space_id)->whereNull('trashed_at')->firstOrFail();
        $this->recipes->attachMedia($recipe, $user, collect([$media]), null, 'cover');
    }

    private function recipe(Request $request, string $uuid): Recipe
    {
        $this->available();
        return Recipe::where('uuid', $uuid)->whereIn('gallery_space_id', $this->spaceIds($request->user()))
            ->where(fn ($query) => $query->where('status', 'published')->orWhere('created_by', $request->user()->id))->firstOrFail();
    }
    private function available(): void { abort_unless(Schema::hasTable('recipes'), 503, 'Pro recepty dokončete databázové migrace aplikace.'); }
    private function write(Request $request): void { abort_if($request->user()->read_only_mode, 403, 'V režimu pouze pro čtení nelze recepty měnit.'); }
    private function spaceIds(User $user): array { return $user->gallerySpaces()->pluck('gallery_spaces.id')->map(fn ($id) => (int) $id)->all(); }
}
