<?php

namespace App\Services\Recipes;

use App\Jobs\Drive\CreateDriveFolderJob;
use App\Models\Album;
use App\Models\MediaItem;
use App\Models\Recipe;
use App\Models\RecipeCookingSession;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecipeService
{
    public function payload(Recipe $recipe, ?float $requestedServings = null, bool $full = true): array
    {
        $servings = max(0.25, min(1000, $requestedServings ?: (float) $recipe->base_servings));
        $factor = $servings / max(0.01, (float) $recipe->base_servings);
        $recipe->loadMissing(['cover.variants', 'album']);

        $completedQuery = RecipeCookingSession::where('recipe_id', $recipe->id)->where('status', 'completed');
        $prefetched = array_key_exists('list_times_cooked', $recipe->getAttributes());
        $stats = [
            'times_cooked' => $prefetched ? (int) $recipe->getAttribute('list_times_cooked') : (clone $completedQuery)->count(),
            'average_rating' => $this->number($prefetched ? $recipe->getAttribute('list_average_rating') : (clone $completedQuery)->avg('overall_rating')),
            'average_taste_rating' => $this->number($prefetched ? $recipe->getAttribute('list_average_taste_rating') : (clone $completedQuery)->avg('taste_rating')),
            'last_cooked_at' => $prefetched ? $recipe->getAttribute('list_last_cooked_at') : (clone $completedQuery)->max('cooked_at'),
            'next_planned_for' => $prefetched ? $recipe->getAttribute('list_next_planned_for') : RecipeCookingSession::where('recipe_id', $recipe->id)->where('status', 'planned')->where('planned_for', '>=', now())->min('planned_for'),
        ];

        $result = [
            'uuid' => $recipe->uuid,
            'gallery_space_id' => $recipe->gallery_space_id,
            'title' => $recipe->title,
            'summary' => $recipe->summary,
            'description' => $recipe->description,
            'category' => $recipe->category,
            'cuisine' => $recipe->cuisine,
            'difficulty' => $recipe->difficulty,
            'status' => $recipe->status,
            'base_servings' => (float) $recipe->base_servings,
            'selected_servings' => $servings,
            'scale_factor' => round($factor, 4),
            'prep_minutes' => $recipe->prep_minutes,
            'cook_minutes' => $recipe->cook_minutes,
            'rest_minutes' => $recipe->rest_minutes,
            'total_minutes' => (int) $recipe->prep_minutes + (int) $recipe->cook_minutes + (int) $recipe->rest_minutes,
            'estimated_cost' => $this->number($recipe->estimated_cost, 2),
            'scaled_cost' => $recipe->estimated_cost !== null ? $this->number((float) $recipe->estimated_cost * $factor, 2) : null,
            'cost_per_serving' => $recipe->estimated_cost !== null ? $this->number((float) $recipe->estimated_cost / max(0.01, (float) $recipe->base_servings), 2) : null,
            'currency' => $recipe->currency,
            'nutrition' => [
                'calories' => $this->number($recipe->calories_per_serving, 0),
                'protein' => $this->number($recipe->protein_per_serving),
                'carbs' => $this->number($recipe->carbs_per_serving),
                'fat' => $this->number($recipe->fat_per_serving),
            ],
            'dietary_tags' => $recipe->dietary_tags ?? [],
            'occasion_tags' => $recipe->occasion_tags ?? [],
            'equipment' => $recipe->equipment ?? [],
            'source_name' => $recipe->source_name,
            'source_url' => $recipe->source_url,
            'tips' => $recipe->tips,
            'storage_notes' => $recipe->storage_notes,
            'reheating_notes' => $recipe->reheating_notes,
            'is_favorite' => $recipe->is_favorite,
            'cover' => $recipe->cover ? $this->mediaPayload($recipe->cover) : null,
            'album' => $recipe->album?->only(['uuid', 'title']),
            'stats' => $stats,
            'created_at' => $recipe->created_at?->toIso8601String(),
            'updated_at' => $recipe->updated_at?->toIso8601String(),
        ];

        if (! $full) return $result;

        $recipe->loadMissing([
            'ingredients', 'steps.media.variants', 'cookingSessions.author:id,name',
            'cookingSessions.event:id,uuid,title,starts_at,status', 'media.variants',
        ]);
        $result['ingredients'] = $recipe->ingredients->map(function ($ingredient) use ($factor) {
            $scaled = $ingredient->quantity === null ? null : (float) $ingredient->quantity * ($ingredient->is_scalable ? $factor : 1);
            return [
                'id' => $ingredient->id, 'section' => $ingredient->section, 'name' => $ingredient->name,
                'quantity' => $ingredient->quantity, 'scaled_quantity' => $scaled,
                'display_quantity' => $this->formatQuantity($scaled), 'unit' => $ingredient->unit,
                'quantity_note' => $ingredient->quantity_note, 'is_scalable' => $ingredient->is_scalable,
                'is_optional' => $ingredient->is_optional, 'is_pantry' => $ingredient->is_pantry,
                'preparation' => $ingredient->preparation, 'substitutes' => $ingredient->substitutes,
            ];
        })->values();
        $result['steps'] = $recipe->steps->map(fn ($step, $index) => [
            'uuid' => $step->uuid, 'number' => $index + 1, 'title' => $step->title,
            'instruction' => $step->instruction, 'timer_seconds' => $step->timer_seconds,
            'temperature' => $step->temperature, 'temperature_unit' => $step->temperature_unit,
            'equipment' => $step->equipment, 'tip' => $step->tip,
            'media' => $step->media ? $this->mediaPayload($step->media) : null,
        ])->values();
        $result['cooking_sessions'] = $recipe->cookingSessions->take(50)->map(fn (RecipeCookingSession $session) => $this->sessionPayload($session))->values();
        $result['media'] = $recipe->media->unique('id')->values()->map(fn (MediaItem $media) => $this->mediaPayload($media) + [
            'role' => $media->pivot->role, 'caption' => $media->pivot->caption,
        ]);

        return $result;
    }

    public function sessionPayload(RecipeCookingSession $session): array
    {
        $session->loadMissing(['author:id,name', 'event:id,uuid,title,starts_at,status', 'media.variants']);
        return [
            'uuid' => $session->uuid, 'status' => $session->status,
            'author' => $session->author?->only(['id', 'name']),
            'calendar_event' => $session->event ? ['uuid' => $session->event->uuid, 'title' => $session->event->title, 'starts_at' => $session->event->starts_at?->toIso8601String(), 'status' => $session->event->status] : null,
            'planned_for' => $session->planned_for?->toIso8601String(), 'started_at' => $session->started_at?->toIso8601String(),
            'cooked_at' => $session->cooked_at?->toIso8601String(), 'finished_at' => $session->finished_at?->toIso8601String(),
            'servings' => (float) $session->servings, 'actual_duration_minutes' => $session->actual_duration_minutes,
            'ratings' => ['overall' => $session->overall_rating, 'taste' => $session->taste_rating, 'process' => $session->process_rating, 'appearance' => $session->appearance_rating],
            'actual_cost' => $session->actual_cost, 'currency' => $session->currency, 'notes' => $session->notes,
            'successes' => $session->successes, 'failures' => $session->failures, 'improvements' => $session->improvements,
            'changes_made' => $session->changes_made, 'partner_feedback' => $session->partner_feedback,
            'would_cook_again' => $session->would_cook_again, 'leftovers_notes' => $session->leftovers_notes,
            'media' => $session->media->map(fn (MediaItem $media) => $this->mediaPayload($media))->values(),
        ];
    }

    public function ensureAlbum(Recipe $recipe, User $user): Album
    {
        if ($recipe->album_id && ($album = Album::whereKey($recipe->album_id)->where('gallery_space_id', $recipe->gallery_space_id)->first())) return $album;
        $created = false;
        $album = DB::transaction(function () use ($recipe, $user, &$created) {
            $locked = Recipe::whereKey($recipe->id)->lockForUpdate()->firstOrFail();
            if ($locked->album_id && ($existing = Album::find($locked->album_id))) return $existing;
            $created = true;
            $album = Album::create([
                'gallery_space_id' => $recipe->gallery_space_id,
                'title' => 'Vaříme · ' . $recipe->title,
                'slug' => Str::slug('varime-' . $recipe->title . '-' . $recipe->id),
                'description' => 'Fotografie přípravy, výsledků a společných vaření receptu ' . $recipe->title . '.',
                'visibility' => 'shared', 'icon' => '🍳', 'color' => '#f59e0b',
                'created_by' => $user->id, 'updated_by' => $user->id, 'sync_status' => 'pending',
            ]);
            $album->rebuildPaths();
            $locked->update(['album_id' => $album->id]);
            $permissions = DB::table('gallery_space_user')->where('gallery_space_id', $recipe->gallery_space_id)->pluck('user_id')->map(fn ($userId) => [
                'album_id' => $album->id, 'user_id' => $userId, 'role' => 'editor', 'inherited' => false, 'created_at' => now(), 'updated_at' => now(),
            ])->all();
            if ($permissions) DB::table('album_user_permissions')->upsert($permissions, ['album_id', 'user_id'], ['role', 'updated_at']);
            return $album;
        });
        if ($created) CreateDriveFolderJob::dispatch($album);
        return $album;
    }

    public function attachMedia(Recipe $recipe, User $user, Collection $media, ?RecipeCookingSession $session = null, string $role = 'gallery'): void
    {
        $album = $this->ensureAlbum($recipe, $user);
        foreach ($media->values() as $index => $item) {
            DB::table('recipe_media')->updateOrInsert([
                'recipe_id' => $recipe->id, 'media_item_id' => $item->id, 'cooking_session_id' => $session?->id,
            ], ['role' => $role, 'sort_order' => $index, 'created_at' => now()]);
            DB::table('album_media')->insertOrIgnore([
                'album_id' => $album->id, 'media_item_id' => $item->id, 'sort_order' => $index,
                'is_cover' => $role === 'cover', 'added_at' => now(), 'added_by' => $user->id,
            ]);
        }
        if ($role === 'cover' && $media->first()) {
            $recipe->update(['cover_media_id' => $media->first()->id]);
            $album->update(['cover_media_id' => $media->first()->id]);
        }
    }

    public function mediaPayload(MediaItem $media): array
    {
        return [
            'uuid' => $media->uuid, 'title' => $media->display_title ?: $media->original_filename,
            'thumbnail_url' => $media->thumbnail_url, 'media_type' => $media->media_type,
            'taken_at' => $media->taken_at?->toIso8601String(), 'detail_url' => '/media/' . $media->uuid,
        ];
    }

    private function formatQuantity(?float $value): ?string
    {
        if ($value === null) return null;
        $rounded = round($value, abs($value) >= 10 ? 1 : 2);
        return rtrim(rtrim(number_format($rounded, 2, ',', ' '), '0'), ',');
    }

    private function number(mixed $value, int $precision = 1): ?float
    {
        return $value === null ? null : round((float) $value, $precision);
    }
}
