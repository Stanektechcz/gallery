<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegratedMealPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_meal_scales_shopping_assigns_task_and_does_not_complete_the_parent_event(): void
    {
        [$owner, $partner, $space] = $this->couple();
        $recipe = $this->actingAs($owner)->postJson('/api/v1/recipes', $this->recipePayload($space->id))
            ->assertCreated()->json();
        $event = $this->postJson('/api/v1/calendar/events', [
            'gallery_space_id' => $space->id,
            'title' => 'Nedělní odpoledne',
            'starts_at' => now()->addDays(3)->setTime(16, 0)->toIso8601String(),
            'participant_ids' => [$partner->id],
        ])->assertCreated()->json();

        $plan = $this->postJson("/api/v1/calendar/events/{$event['uuid']}/meal-plan", [
            'recipe_uuid' => $recipe['uuid'], 'meal_type' => 'dinner', 'servings' => 4,
            'notes' => 'Nakoupit cestou domů.',
        ])->assertCreated()
            ->assertJsonPath('summary.meals', 1)
            ->assertJsonPath('summary.estimated_cost', 360)
            ->assertJsonPath('shopping.0.quantity', 400)
            ->json();

        $updatedShopping = $this->patchJson("/api/v1/calendar/events/{$event['uuid']}/meal-shopping/{$plan['shopping'][0]['key']}", [
            'assigned_to' => $partner->id, 'is_checked' => true,
        ])->assertOk()->json('shopping');
        $updatedItem = collect($updatedShopping)->firstWhere('key', $plan['shopping'][0]['key']);
        $this->assertSame($partner->id, $updatedItem['assigned_to']['id']);
        $this->assertTrue($updatedItem['is_checked']);
        $this->assertDatabaseHas('event_tasks', ['event_id' => $event['id'], 'assigned_to' => $partner->id]);
        $this->assertNotNull(DB::table('event_tasks')->where('event_id', $event['id'])->value('completed_at'));

        $sessionUuid = $plan['meals'][0]['cooking_session_uuid'];
        $this->postJson("/api/v1/recipes/{$recipe['uuid']}/cooking-sessions/start", ['session_uuid' => $sessionUuid, 'servings' => 4])->assertOk();
        $this->putJson("/api/v1/recipes/{$recipe['uuid']}/cooking-sessions/{$sessionUuid}/complete", [
            'overall_rating' => 5, 'successes' => 'Povedlo se.',
        ])->assertOk();

        $this->assertDatabaseHas('planned_meals', ['uuid' => $plan['meals'][0]['uuid'], 'status' => 'prepared']);
        $this->assertDatabaseHas('calendar_events', ['uuid' => $event['uuid'], 'status' => 'planned']);

        $this->deleteJson('/api/v1/planned-meals/' . $plan['meals'][0]['uuid'])->assertOk();
        $this->assertDatabaseMissing('planned_meals', ['uuid' => $plan['meals'][0]['uuid']]);
        $this->assertDatabaseMissing('event_tasks', ['event_id' => $event['id']]);
        $this->assertDatabaseHas('calendar_events', ['uuid' => $event['uuid'], 'status' => 'planned']);
    }

    public function test_trip_meal_connects_itinerary_calendar_now_packing_and_food_budget(): void
    {
        [$owner, $partner, $space] = $this->couple();
        $recipe = $this->actingAs($owner)->postJson('/api/v1/recipes', $this->recipePayload($space->id))
            ->assertCreated()->json();
        $date = now()->addWeek()->toDateString();
        $tripId = DB::table('trips')->insertGetId([
            'gallery_space_id' => $space->id, 'created_by' => $owner->id, 'name' => 'Víkend na chatě',
            'start_date' => $date, 'end_date' => $date, 'currency' => 'CZK', 'budget' => 3000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $dayId = DB::table('trip_days')->insertGetId(['trip_id' => $tripId, 'date' => $date, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('trip_budget_limits')->insert(['trip_id' => $tripId, 'category' => 'food', 'amount' => 500, 'currency' => 'CZK', 'warn_percent' => 80, 'created_at' => now(), 'updated_at' => now()]);

        $plan = $this->postJson("/api/v1/trips/{$tripId}/meal-plan", [
            'recipe_uuid' => $recipe['uuid'], 'meal_type' => 'dinner', 'servings' => 4,
            'trip_day_id' => $dayId, 'planned_for' => $date . 'T18:30',
        ])->assertCreated()
            ->assertJsonPath('summary.budget.limit', 500)
            ->assertJsonPath('summary.budget.planned', 360)
            ->json();

        $meal = $plan['meals'][0];
        $this->assertDatabaseHas('trip_activities', ['trip_day_id' => $dayId, 'title' => '🍳 Houbové risotto']);
        $generatedEvent = DB::table('calendar_events')->where('trip_id', $tripId)->where('title', 'like', 'Jídlo na cestě%')->first();
        $this->assertNotNull($generatedEvent);
        $this->getJson("/api/v1/calendar/events/{$generatedEvent->uuid}")->assertOk()
            ->assertJsonPath('origin.kind', 'trip_recipe_meal')->assertJsonPath('origin.recipe.uuid', $recipe['uuid']);

        // Obrazovka na cestě ukazuje recept i tehdy, kdy je zvolen první den budoucí cesty.
        $this->getJson("/api/v1/trips/{$tripId}/now")->assertOk()
            ->assertJsonPath('meals.0.recipe_uuid', $recipe['uuid'])->assertJsonPath('meals.0.servings', 4);

        $updatedShopping = $this->patchJson("/api/v1/trips/{$tripId}/meal-shopping/{$plan['shopping'][0]['key']}", [
            'assigned_to' => $partner->id, 'is_checked' => true,
        ])->assertOk()->json('shopping');
        $this->assertTrue(collect($updatedShopping)->firstWhere('key', $plan['shopping'][0]['key'])['is_checked']);
        $this->assertDatabaseHas('trip_packing_items', ['trip_id' => $tripId, 'category' => 'food', 'assigned_to' => $partner->id, 'is_packed' => true, 'source_template' => 'meal_plan']);

        $this->getJson("/api/v1/trips/{$tripId}/budget-advisor")->assertOk()
            ->assertJsonPath('categories.2.category', 'food')->assertJsonPath('categories.2.planned', 360);

        $this->putJson("/api/v1/recipes/{$recipe['uuid']}/cooking-sessions/{$meal['cooking_session_uuid']}/complete", [
            'overall_rating' => 4.5,
        ])->assertOk();
        $this->assertDatabaseHas('planned_meals', ['uuid' => $meal['uuid'], 'status' => 'prepared']);
        $this->assertDatabaseHas('calendar_events', ['id' => $generatedEvent->id, 'status' => 'completed']);
    }

    private function couple(): array
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => 'owner']);
        $partner = User::factory()->create(['role' => 'partner']);
        $space = GallerySpace::create(['name' => 'Náš prostor', 'slug' => 'nas-prostor-' . $owner->id, 'owner_id' => $owner->id]);
        $space->members()->attach($owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $space->members()->attach($partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        return [$owner, $partner, $space];
    }

    private function recipePayload(int $spaceId): array
    {
        return [
            'gallery_space_id' => $spaceId, 'title' => 'Houbové risotto', 'category' => 'main_course', 'difficulty' => 'medium',
            'status' => 'published', 'base_servings' => 2, 'prep_minutes' => 10, 'cook_minutes' => 30,
            'estimated_cost' => 180, 'currency' => 'CZK',
            'ingredients' => [
                ['name' => 'Rýže arborio', 'quantity' => 200, 'unit' => 'g', 'is_scalable' => true],
                ['name' => 'Sůl', 'quantity_note' => 'dle chuti', 'is_scalable' => false, 'is_pantry' => true],
            ],
            'steps' => [['title' => 'Vaření', 'instruction' => 'Postupně přilévejte vývar.', 'timer_seconds' => 1200]],
        ];
    }
}
