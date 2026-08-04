<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A recipe is normally pasted as prose — a title line, a "Suroviny" section of grouped
 * bullets and a "Postup" section of numbered multi-line steps. The parser used to expect
 * everything on one line after a colon, so a real recipe produced nothing at all.
 */
class AssistantRecipeParsingTest extends TestCase
{
    use RefreshDatabase;

    private function recipe(): string
    {
        return <<<'RECIPE'
        Kynutá maková bábovka se 400 g máku
        Těsto zůstává stejné jako v původním receptu. Pouze zvětšíme množství makové náplně.
        Suroviny
        Kynuté těsto

        * 600 g polohrubé mouky
        * 60 g cukru krupice
        * 42 g čerstvého droždí
        * 2 žloutky, přibližně 35–40 g
        * 6 g soli

        Maková náplň

        * 400 g mletého máku
        * 400 g polotučného mléka
        * 100 g švestkových povidel
        * 20 g rumu, volitelné
        * 40 g rozinek, volitelné

        Na formu

        * 15 g másla

        Přesný postup
        1. Připrav máslo
        Do malého kastrůlku dej 60 g másla a na mírném ohni ho rozpusť.
        Rozpuštěné máslo odstav a nech ho zchladnout.
        2. Připrav kvásek
        Mléko ohřej přibližně na 30–35 °C.
        Promíchej a nech kvásek stát přibližně 10–15 minut.
        3. Zadělej těsto
        Hotové těsto má být hladké, pružné a jen lehce lepivé.
        4. Pečení
        Peč ji při 165 °C přibližně 55–70 minut.
        RECIPE;
    }

    public function test_a_pasted_recipe_is_parsed_into_grouped_ingredients_and_numbered_steps(): void
    {
        [$user, $space] = $this->couple();
        $this->actingAs($user);

        $plan = $this->postJson('/api/v1/assistant/preview', ['message' => $this->recipe()])->assertOk()->json();

        $this->assertSame('Kynutá maková bábovka se 400 g máku', $plan['recipe']);
        $this->assertCount(11, $plan['recipe_details']['ingredient_rows']);
        $this->assertCount(4, $plan['recipe_details']['step_rows']);

        $flour = $plan['recipe_details']['ingredient_rows'][0];
        $this->assertSame('Kynuté těsto', $flour['section']);
        $this->assertEquals(600, $flour['quantity']);
        $this->assertSame('g', $flour['unit']);
        $this->assertSame('polohrubé mouky', $flour['name']);

        // Group headings switch the section rather than becoming ingredients.
        $poppy = collect($plan['recipe_details']['ingredient_rows'])->firstWhere('name', 'mletého máku');
        $this->assertSame('Maková náplň', $poppy['section']);

        // "volitelné" marks an optional ingredient.
        $rum = collect($plan['recipe_details']['ingredient_rows'])->firstWhere('name', 'rumu, volitelné');
        $this->assertTrue($rum['optional']);

        // Each numbered item is one step; the lines under it become its instruction.
        $this->assertSame('Připrav máslo', $plan['recipe_details']['step_rows'][0]['title']);
        $this->assertStringContainsString('rozpusť', $plan['recipe_details']['step_rows'][0]['instruction']);
        $this->assertStringContainsString('zchladnout', $plan['recipe_details']['step_rows'][0]['instruction']);
        $this->assertSame('Pečení', $plan['recipe_details']['step_rows'][3]['title']);

        $this->postJson('/api/v1/assistant/apply', ['message' => $this->recipe(), 'selected_actions' => ['recipe']])->assertCreated();

        $recipe = Recipe::where('gallery_space_id', $space->id)->firstOrFail();
        $this->assertSame('Kynutá maková bábovka se 400 g máku', $recipe->title);
        $this->assertSame(11, DB::table('recipe_ingredients')->where('recipe_id', $recipe->id)->count());
        $this->assertSame(4, DB::table('recipe_steps')->where('recipe_id', $recipe->id)->count());
        $this->assertDatabaseHas('recipe_ingredients', [
            'recipe_id' => $recipe->id, 'name' => 'polohrubé mouky', 'section' => 'Kynuté těsto', 'quantity' => 600, 'unit' => 'g',
        ]);
        $this->assertDatabaseHas('recipe_steps', ['recipe_id' => $recipe->id, 'title' => 'Pečení', 'sort_order' => 3]);
    }

    public function test_a_recipe_longer_than_the_old_four_thousand_character_limit_is_accepted(): void
    {
        [$user] = $this->couple();
        $this->actingAs($user);

        $padding = str_repeat('Poznámka k postupu, kterou si chceme uchovat. ', 130);
        $long = $this->recipe() . "\n" . $padding;
        $this->assertGreaterThan(4000, mb_strlen($long));

        $this->postJson('/api/v1/assistant/preview', ['message' => $long])
            ->assertOk()
            ->assertJsonPath('recipe', 'Kynutá maková bábovka se 400 g máku');
    }

    public function test_the_single_line_form_still_works(): void
    {
        [$user, $space] = $this->couple();
        $this->actingAs($user);

        $message = "recept: Těstoviny s rajčaty\nsuroviny: těstoviny, rajčata, bazalka\npostup: uvařit; zamíchat";
        $this->postJson('/api/v1/assistant/preview', ['message' => $message])
            ->assertOk()
            ->assertJsonPath('recipe', 'Těstoviny s rajčaty')
            ->assertJsonPath('recipe_details.ingredients.1', 'rajčata');

        $this->postJson('/api/v1/assistant/apply', ['message' => $message, 'selected_actions' => ['recipe']])->assertCreated();
        $recipe = Recipe::where('gallery_space_id', $space->id)->firstOrFail();
        $this->assertSame(3, DB::table('recipe_ingredients')->where('recipe_id', $recipe->id)->count());
        $this->assertSame(2, DB::table('recipe_steps')->where('recipe_id', $recipe->id)->count());
    }

    /** @return array{0:User,1:GallerySpace} */
    private function couple(): array
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $space = GallerySpace::create(['name' => 'Kuchyně', 'slug' => 'kuchyne', 'owner_id' => $owner->id, 'is_default' => true]);
        $space->members()->attach($owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);

        return [$owner, $space];
    }
}
