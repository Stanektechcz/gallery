<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RecipeSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipe_scales_ingredients_and_connects_calendar_album_photos_and_cooking_journal(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => 'owner']);
        $partner = User::factory()->create(['role' => 'partner']);
        $space = GallerySpace::create(['name' => 'Naše kuchyně', 'slug' => 'nase-kuchyne', 'owner_id' => $owner->id]);
        $space->members()->attach($owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $space->members()->attach($partner->id, ['role' => 'editor', 'can_share' => true, 'joined_at' => now()]);
        $media = MediaItem::create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $space->id, 'owner_user_id' => $owner->id, 'uploaded_by' => $owner->id,
            'original_filename' => 'risotto.jpg', 'safe_filename' => 'risotto.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg',
            'media_type' => 'photo', 'size_bytes' => 4096, 'status' => 'ready', 'storage_status' => 'local_only', 'is_hidden' => false,
            'taken_at' => now(), 'uploaded_at' => now(),
        ]);

        $recipe = $this->actingAs($owner)->postJson('/api/v1/recipes', [
            'gallery_space_id' => $space->id, 'title' => 'Houbové risotto', 'summary' => 'Krémové risotto pro dva.',
            'category' => 'main_course', 'cuisine' => 'Italská', 'difficulty' => 'medium', 'status' => 'published',
            'base_servings' => 2, 'prep_minutes' => 10, 'cook_minutes' => 30, 'rest_minutes' => 5,
            'estimated_cost' => 180, 'currency' => 'CZK', 'calories_per_serving' => 620,
            'dietary_tags' => ['vegetarian'], 'equipment' => ['široký hrnec'], 'tips' => 'Vývar přidávat postupně.',
            'ingredients' => [
                ['section' => 'Risotto', 'name' => 'Rýže arborio', 'quantity' => 200, 'unit' => 'g', 'is_scalable' => true],
                ['section' => 'Dokončení', 'name' => 'Sůl', 'quantity_note' => 'dle chuti', 'is_scalable' => false, 'is_pantry' => true],
            ],
            'steps' => [
                ['title' => 'Základ', 'instruction' => 'Rýži krátce orestujte.', 'timer_seconds' => 120],
                ['title' => 'Vaření', 'instruction' => 'Postupně přilévejte vývar.', 'timer_seconds' => 1200, 'temperature' => 90, 'temperature_unit' => 'C'],
            ],
        ])->assertCreated()->assertJsonPath('title', 'Houbové risotto')->assertJsonPath('ingredients.0.scaled_quantity', 200)->json();

        $this->assertNotEmpty($recipe['album']['uuid']);
        $this->assertDatabaseHas('recipes', ['uuid' => $recipe['uuid']]);
        $this->getJson('/api/v1/recipes?q=risotto')->assertOk()->assertJsonPath('items.0.uuid', $recipe['uuid']);
        $this->patchJson('/api/v1/recipes/' . $recipe['uuid'] . '/favorite')->assertOk()->assertJsonPath('is_favorite', true);
        $scaled = $this->getJson('/api/v1/recipes/' . $recipe['uuid'] . '?servings=5')->assertOk()
            ->assertJsonPath('selected_servings', 5)->assertJsonPath('scale_factor', 2.5)
            ->assertJsonPath('ingredients.0.scaled_quantity', 500)->assertJsonPath('scaled_cost', 450)->json();
        $this->assertSame('500', $scaled['ingredients'][0]['display_quantity']);
        $this->getJson('/api/v1/recipes/' . $recipe['uuid'] . '/shopping-list?servings=4')->assertOk()
            ->assertJsonPath('sections.0.items.0.scaled_quantity', 400);
        $this->getJson('/api/v1/search/suggestions?q=risotto')->assertOk()
            ->assertJsonFragment(['type' => 'recipe', 'label' => 'Houbové risotto', 'url' => '/recipes/' . $recipe['uuid']]);

        $this->postJson('/api/v1/recipes/' . $recipe['uuid'] . '/media', ['media_uuids' => [$media->uuid], 'role' => 'cover'])
            ->assertOk()->assertJsonPath('cover.uuid', $media->uuid);

        $plannedFor = now()->addDay()->setTime(18, 0);
        $session = $this->postJson('/api/v1/recipes/' . $recipe['uuid'] . '/cooking-sessions/schedule', [
            'planned_for' => $plannedFor->toIso8601String(), 'servings' => 3, 'notes' => 'Společná večeře', 'add_to_calendar' => true,
        ])->assertCreated()->assertJsonPath('status', 'planned')->assertJsonPath('servings', 3)->json();
        $this->assertDatabaseHas('calendar_events', ['uuid' => $session['calendar_event']['uuid'], 'type' => 'meal', 'color' => '#f59e0b']);
        $this->assertDatabaseCount('event_participants', 2);
        $this->assertDatabaseCount('event_reminders', 2);
        $this->getJson('/api/v1/calendar/events/' . $session['calendar_event']['uuid'])->assertOk()
            ->assertJsonPath('origin.kind', 'recipe_cooking')->assertJsonPath('origin.recipe.uuid', $recipe['uuid']);
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.partner_hub.recipe.kind', 'planned')->where('data.partner_hub.recipe.uuid', $recipe['uuid']));

        $this->postJson('/api/v1/recipes/' . $recipe['uuid'] . '/cooking-sessions/start', ['session_uuid' => $session['uuid'], 'servings' => 3])
            ->assertOk()->assertJsonPath('status', 'cooking');
        $completed = $this->putJson('/api/v1/recipes/' . $recipe['uuid'] . '/cooking-sessions/' . $session['uuid'] . '/complete', [
            'overall_rating' => 4.5, 'taste_rating' => 5, 'process_rating' => 4, 'appearance_rating' => 4,
            'successes' => 'Výborná konzistence.', 'failures' => 'Málo hub.', 'improvements' => 'Příště více hub.',
            'changes_made' => 'Přidali jsme tymián.', 'partner_feedback' => 'Zopakovat.', 'would_cook_again' => true,
            'media_uuids' => [$media->uuid],
        ])->assertOk()->assertJsonPath('session.status', 'completed')->assertJsonPath('recipe.stats.times_cooked', 1)->json();

        $this->assertDatabaseHas('recipe_cooking_sessions', ['uuid' => $session['uuid'], 'status' => 'completed', 'overall_rating' => 4.5]);
        $this->assertDatabaseHas('calendar_events', ['uuid' => $session['calendar_event']['uuid'], 'status' => 'completed']);
        $this->assertDatabaseHas('recipe_media', ['recipe_id' => $this->recipeId($recipe['uuid']), 'media_item_id' => $media->id]);
        $this->assertDatabaseHas('album_media', ['media_item_id' => $media->id]);

        $this->actingAs($partner)->getJson('/api/v1/recipes/' . $recipe['uuid'])->assertOk()
            ->assertJsonPath('stats.times_cooked', 1)->assertJsonPath('cooking_sessions.0.improvements', 'Příště více hub.');
    }

    private function recipeId(string $uuid): int
    {
        return (int) \Illuminate\Support\Facades\DB::table('recipes')->where('uuid', $uuid)->value('id');
    }
}
