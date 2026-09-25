<?php

namespace Tests\Feature\Dodelky;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Planovani\DvojiceSHostem;
use Tests\TestCase;

/**
 * Účet, který vlastní svůj prostor, ale je hostem v cizí galerii, nesmí přes
 * `gallery_space_id` cizí galerie číst ani zapisovat její záznamy.
 */
class HostSpaceIsolationTest extends TestCase
{
    use DvojiceSHostem, RefreshDatabase;

    public function test_ics_import_rejects_guest_space(): void
    {
        [$ucet, $vlastni, $cizi, $ciziProstor] = $this->hostCiziGalerie();
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:x1\r\nSUMMARY:Test\r\nDTSTART:20261001T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $this->actingAs($ucet)->postJson('/api/v1/calendar/ics-import', [
            'gallery_space_id' => $ciziProstor->id, 'ics' => $ics,
        ])->assertNotFound();
    }

    public function test_curation_board_store_rejects_guest_space(): void
    {
        [$ucet, $vlastni, $cizi, $ciziProstor] = $this->hostCiziGalerie();

        $this->actingAs($ucet)->postJson('/api/v1/curation-boards', [
            'gallery_space_id' => $ciziProstor->id, 'title' => 'Cizí nástěnka',
        ])->assertNotFound();
    }

    public function test_curation_board_index_excludes_guest_space_boards(): void
    {
        [$ucet, $vlastni, $cizi, $ciziProstor] = $this->hostCiziGalerie();
        DB::table('curation_boards')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $ciziProstor->id, 'created_by' => $cizi->id,
            'title' => 'Cizí nástěnka', 'visibility' => 'shared', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($ucet)->getJson('/api/v1/curation-boards')->assertOk();
        $this->assertEmpty($response->json());
    }

    public function test_recipe_store_rejects_guest_space(): void
    {
        [$ucet, $vlastni, $cizi, $ciziProstor] = $this->hostCiziGalerie();

        $this->actingAs($ucet)->postJson('/api/v1/recipes', $this->recipePayload($ciziProstor->id))->assertForbidden();
    }

    public function test_recipe_read_rejects_guest_space_recipe(): void
    {
        [$ucet, $vlastni, $cizi, $ciziProstor] = $this->hostCiziGalerie();
        $recipe = Recipe::create($this->recipeAttributes($ciziProstor->id, $cizi->id));

        $this->actingAs($ucet)->getJson('/api/v1/recipes/'.$recipe->uuid)->assertNotFound();
    }

    private function recipePayload(int $spaceId): array
    {
        return [
            'gallery_space_id' => $spaceId, 'title' => 'Recept', 'category' => 'main_course', 'difficulty' => 'easy',
            'status' => 'published', 'base_servings' => 2, 'currency' => 'CZK',
            'ingredients' => [['name' => 'Sůl']], 'steps' => [['instruction' => 'Osolit.']],
        ];
    }

    private function recipeAttributes(int $spaceId, int $createdBy): array
    {
        return [
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $spaceId, 'created_by' => $createdBy, 'updated_by' => $createdBy,
            'title' => 'Recept', 'category' => 'main_course', 'difficulty' => 'easy', 'status' => 'published',
            'base_servings' => 2, 'currency' => 'CZK',
        ];
    }
}
