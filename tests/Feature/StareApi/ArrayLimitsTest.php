<?php

namespace Tests\Feature\StareApi;

use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nález 6: pole bez omezení velikosti — jedno zástupné 422 na kontrolér.
 */
class ArrayLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create();
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->adri->gallerySpaces()->syncWithoutDetaching([$this->prostor->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    public function test_smart_rules_odmitne_prilis_mnoho_podminek(): void
    {
        $album = Album::create(['gallery_space_id' => $this->prostor->id, 'uuid' => (string) Str::uuid(), 'title' => 'Album', 'slug' => 'album-'.Str::random(6), 'materialized_path' => '', 'created_by' => $this->adri->id]);

        $conditions = array_fill(0, 201, ['field' => 'media_type', 'op' => 'eq', 'value' => 'photo']);

        $this->putJson('/api/v1/albums/'.$album->uuid.'/smart-rules', [
            'album_type' => 'smart',
            'smart_rules' => ['match' => 'all', 'conditions' => $conditions],
        ])->assertStatus(422);
    }

    public function test_hledani_odmitne_prilis_mnoho_tag_ids(): void
    {
        $tagIds = range(1, 501);

        $this->getJson('/api/v1/search?'.http_build_query(['tag_ids' => $tagIds]))
            ->assertStatus(422);
    }

    public function test_ulozene_hledani_odmitne_prilis_mnoho_filtru(): void
    {
        $filtry = [];
        for ($i = 0; $i < 201; $i++) {
            $filtry['klic_'.$i] = $i;
        }

        $this->postJson('/api/v1/saved-searches', [
            'name' => 'Test',
            'filters_json' => $filtry,
        ])->assertStatus(422);
    }

    public function test_predvolby_vzpominek_odmitnou_prilis_mnoho_skrytych_osob(): void
    {
        $this->patchJson('/api/v1/memories/preferences', [
            'hidden_person_ids' => range(1, 501),
        ])->assertStatus(422);
    }
}
