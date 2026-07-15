<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Recipes\RecipeService;
use App\Services\Recipes\TestRecipeInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestRecipeInstallerTest extends TestCase
{
    use RefreshDatabase;

    public function test_requested_recipes_are_installed_idempotently_with_scalable_ingredients_and_cooking_steps(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        $space = GallerySpace::create([
            'name' => 'Naše kuchařka',
            'slug' => 'nase-kucharka-test',
            'owner_id' => $owner->id,
            'is_default' => true,
        ]);
        $space->members()->attach($owner->id, [
            'role' => 'owner',
            'can_delete' => true,
            'can_share' => true,
            'joined_at' => now(),
        ]);

        $installer = app(TestRecipeInstaller::class);
        $firstRun = $installer->install($space);
        $secondRun = $installer->install($space);

        $this->assertCount(2, $firstRun);
        $this->assertCount(2, $secondRun);
        $this->assertDatabaseCount('recipes', 2);
        $this->assertDatabaseCount('recipe_ingredients', 55);
        $this->assertDatabaseCount('recipe_steps', 26);
        $this->assertDatabaseHas('recipes', [
            'uuid' => TestRecipeInstaller::RECIPE_UUIDS[0],
            'title' => 'Zapečená kuřecí prsa v medovo-hořčičné omáčce se třemi sýry a pečenými bramborami',
            'base_servings' => 6,
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('recipes', [
            'uuid' => TestRecipeInstaller::RECIPE_UUIDS[1],
            'title' => 'Marry Me Chicken',
            'base_servings' => 4,
            'status' => 'published',
        ]);

        $marryMe = Recipe::where('uuid', TestRecipeInstaller::RECIPE_UUIDS[1])->firstOrFail();
        $payload = app(RecipeService::class)->payload($marryMe, 8);
        $ingredients = collect($payload['ingredients'])->keyBy('name');
        $this->assertSame(2.0, $payload['scale_factor']);
        $this->assertSame(1000.0, $ingredients['Kuřecí prsa']['scaled_quantity']);
        $this->assertSame(400.0, $ingredients['Rýže']['scaled_quantity']);
        $this->assertNull($ingredients['Sůl']['scaled_quantity']);
        $this->assertTrue((bool) $ingredients['Hladká mouka nebo škrob']['is_optional']);
        $this->assertSame(1200, $payload['steps'][0]['timer_seconds']);
        $this->assertSame(10, count($payload['steps']));

        $bakedChicken = Recipe::where('uuid', TestRecipeInstaller::RECIPE_UUIDS[0])->firstOrFail();
        $scaled = app(RecipeService::class)->payload($bakedChicken, 3);
        $scaledIngredients = collect($scaled['ingredients'])->keyBy('name');
        $this->assertSame(500.0, $scaledIngredients['Kuřecí prsa']['scaled_quantity']);
        $this->assertSame(55.0, $scaledIngredients['Cheddar']['scaled_quantity']);
        $this->assertSame(190.0, (float) $scaled['steps'][2]['temperature']);
        $this->assertSame('C', $scaled['steps'][2]['temperature_unit']);
    }
}
