<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reproduction of the recipe reported as failing with a server error. Unlike the
 * shortened fixture in AssistantRecipeParsingTest, this is the recipe exactly as a
 * user pastes it — including bullet lists nested inside numbered steps, degree
 * symbols and en-dash ranges.
 */
class AssistantRealRecipeTest extends TestCase
{
    use RefreshDatabase;

    private function recipe(): string
    {
        return file_get_contents(base_path('tests/Fixtures/recipe-makova-babovka.txt'));
    }

    public function test_the_reported_recipe_is_previewed_and_stored_without_an_error(): void
    {
        [$user, $space] = $this->couple();
        $this->actingAs($user);

        $this->assertGreaterThan(4000, mb_strlen($this->recipe()));

        $plan = $this->postJson('/api/v1/assistant/preview', ['message' => $this->recipe()])->assertOk()->json();
        $this->assertSame('Kynutá maková bábovka se 400 g máku', $plan['recipe']);
        $this->assertSame(14, count($plan['recipe_details']['step_rows']), 'The recipe has 14 numbered steps.');

        $this->postJson('/api/v1/assistant/apply', ['message' => $this->recipe(), 'selected_actions' => ['recipe']])
            ->assertCreated();

        $recipe = Recipe::where('gallery_space_id', $space->id)->firstOrFail();
        $this->assertSame(14, DB::table('recipe_steps')->where('recipe_id', $recipe->id)->count());
        $this->assertDatabaseHas('recipe_steps', ['recipe_id' => $recipe->id, 'title' => 'Pečení']);
        $this->assertDatabaseHas('recipe_ingredients', ['recipe_id' => $recipe->id, 'section' => 'Maková náplň', 'name' => 'mletého máku']);
    }

    /**
     * Tests run on SQLite, which ignores VARCHAR limits; production is MySQL, where an
     * over-long value is a "Data too long for column" error and a 500. Every parsed value
     * therefore has to be checked against the real column widths from the migration.
     */
    public function test_parsed_values_fit_the_real_column_widths(): void
    {
        [$user] = $this->couple();
        $this->actingAs($user);

        $plan = $this->postJson('/api/v1/assistant/preview', ['message' => $this->recipe()])->assertOk()->json();

        // Widths from 2026_07_15_090000_create_recipe_system.php
        $this->assertLessThanOrEqual(180, mb_strlen($plan['recipe']), 'recipes.title is varchar(180)');

        foreach ($plan['recipe_details']['ingredient_rows'] as $row) {
            $this->assertLessThanOrEqual(180, mb_strlen($row['name']), "recipe_ingredients.name is varchar(180): {$row['name']}");
            $this->assertLessThanOrEqual(100, mb_strlen((string) $row['section']), 'recipe_ingredients.section is varchar(100)');
            $this->assertLessThanOrEqual(32, mb_strlen((string) $row['unit']), 'recipe_ingredients.unit is varchar(32)');
        }

        foreach ($plan['recipe_details']['step_rows'] as $row) {
            $this->assertLessThanOrEqual(180, mb_strlen($row['title']), "recipe_steps.title is varchar(180): {$row['title']}");
        }
    }

    /** @return array{0:User,1:GallerySpace} */
    private function couple(): array
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $space = GallerySpace::create(['name' => 'Kuchyně', 'slug' => 'kuchyne-real', 'owner_id' => $owner->id, 'is_default' => true]);
        $space->members()->attach($owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);

        return [$owner, $space];
    }
}
