<?php

namespace App\Services\Sharing;

use App\Models\Album;
use App\Models\MediaItem;
use App\Models\PlaceReview;
use App\Models\Recipe;
use App\Models\SharedLink;
use App\Models\User;

class SharedContentService
{
    public const CONTENT_TYPES = ['recipe', 'place_review'];

    /**
     * Resolve a public-content target while checking both gallery membership and
     * ownership rules. The database continues to store the internal numeric ID;
     * clients only ever need to know the stable UUID.
     *
     * @return array{id:int, gallery_space_id:int, name:string}
     */
    public function resolveForSharing(User $user, string $type, string $uuid): array
    {
        $spaceIds = $user->gallerySpaces()->pluck('gallery_spaces.id');

        if ($type === 'recipe') {
            $recipe = Recipe::query()
                ->where('uuid', $uuid)
                ->whereIn('gallery_space_id', $spaceIds)
                ->where(fn ($query) => $query->where('status', 'published')->orWhere('created_by', $user->id))
                ->firstOrFail();

            $this->ensureCanShare($user, (int) $recipe->gallery_space_id);

            return ['id' => $recipe->id, 'gallery_space_id' => $recipe->gallery_space_id, 'name' => $recipe->title];
        }

        if ($type === 'place_review') {
            $review = PlaceReview::query()
                ->with('place:id,name')
                ->where('uuid', $uuid)
                ->whereIn('gallery_space_id', $spaceIds)
                ->where('status', 'published')
                ->where('author_user_id', $user->id)
                ->firstOrFail();

            $this->ensureCanShare($user, (int) $review->gallery_space_id);

            return [
                'id' => $review->id,
                'gallery_space_id' => $review->gallery_space_id,
                'name' => 'Hodnocení · ' . ($review->place?->name ?: 'podnik'),
            ];
        }

        abort(422, 'Tento typ obsahu nelze sdílet.');
    }

    /** @return array{type:string, label:string, title:string, uuid:?string} */
    public function summary(SharedLink $link): array
    {
        return match ($link->target_type) {
            'recipe' => $this->recipeSummary($link),
            'place_review' => $this->reviewSummary($link),
            'album' => [
                'type' => 'album', 'label' => 'Album',
                'title' => Album::whereKey($link->target_id)->value('title') ?: ($link->name ?: 'Sdílené album'),
                'uuid' => Album::whereKey($link->target_id)->value('uuid'),
            ],
            'media' => [
                'type' => 'media', 'label' => 'Fotografie nebo video',
                'title' => MediaItem::whereKey($link->target_id)->value('display_title') ?: ($link->name ?: 'Sdílené médium'),
                'uuid' => MediaItem::whereKey($link->target_id)->value('uuid'),
            ],
            default => ['type' => 'selection', 'label' => 'Výběr médií', 'title' => $link->name ?: 'Sdílený výběr', 'uuid' => null],
        };
    }

    /** @return array{type:string, title:string, data:array<string,mixed>} */
    public function publicPayload(SharedLink $link): array
    {
        return match ($link->target_type) {
            'recipe' => $this->recipePayload($link),
            'place_review' => $this->reviewPayload($link),
            default => abort(404),
        };
    }

    private function ensureCanShare(User $user, int $spaceId): void
    {
        $space = $user->gallerySpaces()->whereKey($spaceId)->firstOrFail();
        $canShare = in_array((string) $space->pivot?->role, ['owner'], true)
            || (bool) $space->pivot?->can_share
            || in_array((string) $user->role, ['admin', 'owner'], true);
        abort_unless($canShare, 403, 'Pro sdílení obsahu nemáte oprávnění.');
    }

    private function recipeSummary(SharedLink $link): array
    {
        $recipe = Recipe::withTrashed()->whereKey($link->target_id)->first(['uuid', 'title']);
        return ['type' => 'recipe', 'label' => 'Recept', 'title' => $recipe?->title ?: ($link->name ?: 'Nedostupný recept'), 'uuid' => $recipe?->uuid];
    }

    private function reviewSummary(SharedLink $link): array
    {
        $review = PlaceReview::with('place:id,name')->whereKey($link->target_id)->first();
        return ['type' => 'place_review', 'label' => 'Hodnocení podniku', 'title' => $review?->place?->name ?: ($link->name ?: 'Nedostupné hodnocení'), 'uuid' => $review?->uuid];
    }

    private function recipePayload(SharedLink $link): array
    {
        $recipe = Recipe::query()
            ->whereKey($link->target_id)
            ->where('gallery_space_id', $link->gallery_space_id)
            ->with(['cover.variants', 'ingredients', 'steps.media.variants'])
            ->firstOrFail();
        $media = $recipe->media()->wherePivotNull('cooking_session_id')->with('variants')->get()->unique('id');

        return [
            'type' => 'recipe',
            'title' => $recipe->title,
            'data' => [
                'summary' => $recipe->summary,
                'description' => $recipe->description,
                'category' => $recipe->category,
                'cuisine' => $recipe->cuisine,
                'difficulty' => $recipe->difficulty,
                'servings' => (float) $recipe->base_servings,
                'prep_minutes' => $recipe->prep_minutes,
                'cook_minutes' => $recipe->cook_minutes,
                'rest_minutes' => $recipe->rest_minutes,
                'total_minutes' => (int) $recipe->prep_minutes + (int) $recipe->cook_minutes + (int) $recipe->rest_minutes,
                'estimated_cost' => $recipe->estimated_cost !== null ? (float) $recipe->estimated_cost : null,
                'currency' => $recipe->currency,
                'nutrition' => [
                    'calories' => $recipe->calories_per_serving !== null ? (float) $recipe->calories_per_serving : null,
                    'protein' => $recipe->protein_per_serving !== null ? (float) $recipe->protein_per_serving : null,
                    'carbs' => $recipe->carbs_per_serving !== null ? (float) $recipe->carbs_per_serving : null,
                    'fat' => $recipe->fat_per_serving !== null ? (float) $recipe->fat_per_serving : null,
                ],
                'dietary_tags' => $recipe->dietary_tags ?? [],
                'occasion_tags' => $recipe->occasion_tags ?? [],
                'equipment' => $recipe->equipment ?? [],
                'source_name' => $recipe->source_name,
                'source_url' => $recipe->source_url,
                'tips' => $recipe->tips,
                'storage_notes' => $recipe->storage_notes,
                'reheating_notes' => $recipe->reheating_notes,
                'cover' => $recipe->cover ? $this->media($recipe->cover) : null,
                'ingredients' => $recipe->ingredients->map(fn ($item) => [
                    'section' => $item->section, 'name' => $item->name,
                    'quantity' => $item->quantity !== null ? (float) $item->quantity : null,
                    'unit' => $item->unit, 'quantity_note' => $item->quantity_note,
                    'is_optional' => (bool) $item->is_optional, 'preparation' => $item->preparation,
                    'substitutes' => $item->substitutes,
                ])->values(),
                'steps' => $recipe->steps->map(fn ($step, $index) => [
                    'number' => $index + 1, 'title' => $step->title, 'instruction' => $step->instruction,
                    'timer_seconds' => $step->timer_seconds, 'temperature' => $step->temperature,
                    'temperature_unit' => $step->temperature_unit, 'equipment' => $step->equipment,
                    'tip' => $step->tip, 'media' => $step->media ? $this->media($step->media) : null,
                ])->values(),
                'media' => $media->map(fn (MediaItem $item) => $this->media($item) + [
                    'role' => $item->pivot->role, 'caption' => $item->pivot->caption,
                ])->values(),
            ],
        ];
    }

    private function reviewPayload(SharedLink $link): array
    {
        $review = PlaceReview::query()
            ->whereKey($link->target_id)
            ->where('gallery_space_id', $link->gallery_space_id)
            ->where('status', 'published')
            ->with(['place', 'author:id,name', 'items', 'media.variants'])
            ->firstOrFail();

        $ratingKeys = ['overall', 'service', 'staff_friendliness', 'food', 'food_quality', 'drink', 'speed', 'menu', 'atmosphere', 'cleanliness', 'value'];

        return [
            'type' => 'place_review',
            'title' => $review->place?->name ?: 'Hodnocení podniku',
            'data' => [
                'place' => [
                    'name' => $review->place?->name,
                    'type' => $review->place?->type,
                    'address' => $review->place?->address,
                    'city' => $review->place?->city,
                    'country' => $review->place?->country,
                    'website_url' => $review->place?->website_url,
                ],
                'author' => $review->author?->name,
                'visited_at' => $review->visited_at?->toIso8601String(),
                'visit_context' => $review->visit_context,
                'party_size' => $review->party_size,
                'ratings' => collect($ratingKeys)->mapWithKeys(fn ($key) => [$key => $review->{$key . '_rating'} !== null ? (float) $review->{$key . '_rating'} : null])->all(),
                'wait_minutes' => $review->wait_minutes,
                'total_amount' => $review->total_amount !== null ? (float) $review->total_amount : null,
                'currency' => $review->currency,
                'would_return' => $review->would_return,
                'recommends' => $review->recommends,
                'positives' => $review->positives,
                'improvements' => $review->improvements,
                'notes' => $review->notes,
                'items' => $review->items->map(fn ($item) => $item->only([
                    'category', 'name', 'quantity', 'overall_rating', 'quality_rating',
                    'presentation_rating', 'portion_rating', 'value_rating', 'price',
                    'currency', 'would_order_again', 'note',
                ]))->values(),
                'media' => $review->media->map(fn (MediaItem $item) => $this->media($item) + [
                    'subject' => $item->pivot->subject, 'caption' => $item->pivot->caption,
                ])->values(),
            ],
        ];
    }

    /** @return array{uuid:string, title:string, thumbnail_url:?string, media_type:string} */
    private function media(MediaItem $media): array
    {
        return [
            'uuid' => $media->uuid,
            'title' => $media->display_title ?: $media->original_filename,
            'thumbnail_url' => $media->thumbnail_url,
            'media_type' => $media->media_type,
        ];
    }
}
