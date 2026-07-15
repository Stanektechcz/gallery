<?php

namespace App\Services\Recipes;

use App\Models\CalendarEvent;
use App\Models\PlannedMeal;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MealPlanService
{
    public function forEvent(CalendarEvent $event, User $viewer): array
    {
        $meals = PlannedMeal::where('calendar_event_id', $event->id)->with(['recipe.ingredients', 'recipe.cover.variants', 'cookingSession'])->orderBy('planned_for')->get();
        $currency = $event->trip_id ? (string) (DB::table('trips')->where('id', $event->trip_id)->value('currency') ?: 'CZK') : (string) ($meals->first()?->currency ?: 'CZK');
        return $this->payload($event->gallery_space_id, $viewer, $meals, $currency, $event->id, null);
    }

    public function forTrip(object $trip, User $viewer): array
    {
        $meals = PlannedMeal::where('trip_id', $trip->id)->with(['recipe.ingredients', 'recipe.cover.variants', 'cookingSession'])->orderBy('planned_for')->get();
        return $this->payload($trip->gallery_space_id, $viewer, $meals, strtoupper($trip->currency ?: 'CZK'), null, $trip->id);
    }

    public function itemForEvent(CalendarEvent $event, User $viewer, string $key): ?array
    {
        return collect($this->forEvent($event, $viewer)['shopping'])->firstWhere('key', $key);
    }

    public function itemForTrip(object $trip, User $viewer, string $key): ?array
    {
        return collect($this->forTrip($trip, $viewer)['shopping'])->firstWhere('key', $key);
    }

    private function payload(int $spaceId, User $viewer, Collection $meals, string $currency, ?int $eventId, ?int $tripId): array
    {
        $stateQuery = DB::table('meal_shopping_states')->where('gallery_space_id', $spaceId);
        $eventId ? $stateQuery->where('calendar_event_id', $eventId) : $stateQuery->where('trip_id', $tripId);
        $states = $stateQuery->get()->keyBy('item_key');
        $members = DB::table('gallery_space_user as membership')->join('users', 'users.id', '=', 'membership.user_id')
            ->where('membership.gallery_space_id', $spaceId)->orderBy('users.name')->get(['users.id', 'users.name'])->keyBy('id');

        $shopping = [];
        $mealPayload = $meals->map(function (PlannedMeal $meal) use (&$shopping, $currency) {
            $recipe = $meal->recipe;
            if (! $recipe) return null;
            $factor = (float) $meal->servings / max(.01, (float) $recipe->base_servings);
            foreach ($recipe->ingredients as $ingredient) {
                $key = sha1(Str::lower(trim($ingredient->name)) . '|' . Str::lower(trim((string) $ingredient->unit)));
                $quantity = $ingredient->quantity === null ? null : (float) $ingredient->quantity * ($ingredient->is_scalable ? $factor : 1);
                if (! isset($shopping[$key])) {
                    $shopping[$key] = [
                        'key' => $key, 'name' => $ingredient->name, 'unit' => $ingredient->unit,
                        'quantity' => 0.0, 'has_numeric_quantity' => false, 'quantity_notes' => [],
                        'is_pantry' => true, 'is_optional' => true, 'preparations' => [], 'sources' => [],
                    ];
                }
                if ($quantity !== null) { $shopping[$key]['quantity'] += $quantity; $shopping[$key]['has_numeric_quantity'] = true; }
                if ($ingredient->quantity_note) $shopping[$key]['quantity_notes'][] = $ingredient->quantity_note;
                if ($ingredient->preparation) $shopping[$key]['preparations'][] = $ingredient->preparation;
                $shopping[$key]['is_pantry'] = $shopping[$key]['is_pantry'] && (bool) $ingredient->is_pantry;
                $shopping[$key]['is_optional'] = $shopping[$key]['is_optional'] && (bool) $ingredient->is_optional;
                $shopping[$key]['sources'][] = $recipe->title;
            }
            $convertedCost = $meal->estimated_cost !== null ? $this->convert((float) $meal->estimated_cost, $meal->currency, $currency, $meal->planned_for?->toDateString()) : null;
            return [
                'uuid' => $meal->uuid, 'recipe' => ['uuid' => $recipe->uuid, 'title' => $recipe->title, 'category' => $recipe->category, 'cover_url' => $recipe->cover?->thumbnail_url],
                'meal_type' => $meal->meal_type, 'planned_for' => $meal->planned_for?->toIso8601String(),
                'servings' => (float) $meal->servings, 'status' => $meal->status, 'notes' => $meal->notes,
                'estimated_cost' => $convertedCost, 'currency' => $currency,
                'cooking_session_uuid' => $meal->cookingSession?->uuid,
            ];
        })->filter()->values();

        $shopping = collect($shopping)->map(function (array $item) use ($states, $members) {
            $state = $states->get($item['key']);
            $quantity = $item['has_numeric_quantity'] ? round($item['quantity'], abs($item['quantity']) >= 10 ? 1 : 2) : null;
            return [
                'key' => $item['key'], 'name' => $item['name'], 'unit' => $item['unit'], 'quantity' => $quantity,
                'display_quantity' => $this->formatQuantity($quantity),
                'quantity_notes' => array_values(array_unique($item['quantity_notes'])),
                'is_pantry' => $item['is_pantry'], 'is_optional' => $item['is_optional'],
                'preparations' => array_values(array_unique($item['preparations'])), 'sources' => array_values(array_unique($item['sources'])),
                'is_checked' => (bool) ($state?->is_checked ?? false),
                'assigned_to' => $state?->assigned_to ? ['id' => (int) $state->assigned_to, 'name' => $members->get($state->assigned_to)?->name] : null,
                'note' => $state?->note,
            ];
        })->sortBy(fn ($item) => ($item['is_checked'] ? '2' : ($item['is_pantry'] ? '1' : '0')) . Str::lower($item['name']))->values();

        $cost = round((float) $mealPayload->sum(fn ($meal) => $meal['estimated_cost'] ?? 0), 2);
        $unconverted = $mealPayload->whereNull('estimated_cost')->filter(fn ($meal) => $meals->firstWhere('uuid', $meal['uuid'])?->estimated_cost !== null)->count();
        $budget = null;
        if ($tripId) {
            $limit = DB::table('trip_budget_limits')->where('trip_id', $tripId)->where('category', 'food')->first();
            if ($limit) {
                $amount = $this->convert((float) $limit->amount, $limit->currency, $currency, null);
                $budget = ['limit' => $amount, 'planned' => $cost, 'remaining' => $amount !== null ? round($amount - $cost, 2) : null, 'usage_percent' => $amount !== null && $amount > 0 ? round($cost / $amount * 100, 1) : null];
            }
        }

        $available = Recipe::where('gallery_space_id', $spaceId)->where(fn ($query) => $query->where('status', 'published')->orWhere('created_by', $viewer->id))
            ->with('cover.variants')->orderByDesc('is_favorite')->orderBy('title')->limit(100)->get()->map(fn (Recipe $recipe) => [
                'uuid' => $recipe->uuid, 'title' => $recipe->title, 'category' => $recipe->category,
                'base_servings' => (float) $recipe->base_servings, 'total_minutes' => (int) $recipe->prep_minutes + (int) $recipe->cook_minutes + (int) $recipe->rest_minutes,
                'estimated_cost' => $recipe->estimated_cost, 'currency' => $recipe->currency, 'cover_url' => $recipe->cover?->thumbnail_url,
            ])->values();

        return [
            'meals' => $mealPayload, 'shopping' => $shopping, 'members' => $members->values(), 'available_recipes' => $available,
            'summary' => ['meals' => $mealPayload->count(), 'servings' => round((float) $mealPayload->sum('servings'), 2), 'shopping_items' => $shopping->count(), 'checked_items' => $shopping->where('is_checked', true)->count(), 'estimated_cost' => $cost, 'currency' => $currency, 'unconverted_costs' => $unconverted, 'budget' => $budget],
        ];
    }

    private function convert(float $amount, ?string $from, string $to, ?string $date): ?float
    {
        $from = strtoupper($from ?: $to); $to = strtoupper($to);
        if ($from === $to) return round($amount, 2);
        $query = DB::table('currency_rates')->where('effective_on', '<=', $date ?: now()->toDateString())->orderByDesc('effective_on');
        $direct = (clone $query)->where('base_currency', $from)->where('quote_currency', $to)->value('rate');
        if ($direct) return round($amount * (float) $direct, 2);
        $inverse = (clone $query)->where('base_currency', $to)->where('quote_currency', $from)->value('rate');
        return $inverse ? round($amount / (float) $inverse, 2) : null;
    }

    private function formatQuantity(?float $value): ?string
    {
        if ($value === null) return null;
        return rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ',');
    }
}
