<?php

namespace Tests\Feature\Pomocnik;

use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\Place;
use App\Models\Recipe;
use App\Models\User;
use App\Support\SpaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fotka z trezoru nesmí odejít do sdíleného obsahu mimo trezor.
 *
 * Režim události alba, kurátorská nástěnka, hodnocení podniku, recepty,
 * vaření i příloha pomocníka hlídaly jen koš (`trashed_at`). Fotka
 * z trezoru (`is_hidden`) tak skončila v albu, na nástěnce s názvem
 * souboru a datem pořízení, nebo jako obal alba — i při zamčeném trezoru
 * a i pro partnera, který ho odemčený nemá.
 */
class TrezorMimoSdileneObsahyTest extends TestCase
{
    use RefreshDatabase;

    private User $vlastnik;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();
        SpaceContext::forget();
        Queue::fake();

        $this->vlastnik = User::factory()->create(['role' => 'owner']);
        $this->prostor = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Galerie', 'slug' => 'galerie', 'owner_id' => $this->vlastnik->id]);
        $this->prostor->members()->attach($this->vlastnik->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->actingAs($this->vlastnik);
    }

    public function test_rezim_udalosti_alba_nenabizi_ani_nesbira_fotky_z_trezoru(): void
    {
        $album = Album::create([
            'gallery_space_id' => $this->prostor->id, 'title' => 'Svatba', 'slug' => 'svatba-'.Str::random(6), 'visibility' => 'shared',
            'created_by' => $this->vlastnik->id, 'updated_by' => $this->vlastnik->id,
            'event_mode' => true, 'event_start_at' => now()->subDays(2), 'event_end_at' => now()->subDay(),
        ]);
        $this->fotka(['taken_at' => now()->subDays(2)->addHour()]);
        $tajna = $this->fotka(['taken_at' => now()->subDays(2)->addHours(2), 'is_hidden' => true]);

        $nabidka = $this->getJson("/api/v1/albums/{$album->uuid}/event-media")->assertOk();
        $nabidka->assertJsonPath('count', 1);
        $this->assertNotContains($tajna, collect($nabidka->json('samples'))->pluck('uuid')->all());

        $this->postJson("/api/v1/albums/{$album->uuid}/event-collect")->assertOk()->assertJsonPath('added', 1);
        $this->assertDatabaseMissing('album_media', ['album_id' => $album->id, 'media_item_id' => $this->id($tajna)]);
    }

    public function test_kuratorska_nastenka_fotku_z_trezoru_neprijme_ani_neukaze(): void
    {
        $nastenka = $this->postJson('/api/v1/curation-boards', ['title' => 'Fotokniha'])->assertCreated()->json();
        $tajna = $this->fotka(['is_hidden' => true, 'original_filename' => 'tajne.jpg']);

        $this->postJson("/api/v1/curation-boards/{$nastenka['uuid']}/items", ['media_uuids' => [$tajna]])->assertUnprocessable();
        $this->assertSame(0, DB::table('curation_board_items')->count());

        // Fotka přidaná dřív a do trezoru přesunutá až potom.
        $pozdeji = $this->fotka(['original_filename' => 'pozdeji-v-trezoru.jpg']);
        $this->postJson("/api/v1/curation-boards/{$nastenka['uuid']}/items", ['media_uuids' => [$pozdeji]])->assertCreated();
        DB::table('media_items')->where('uuid', $pozdeji)->update(['is_hidden' => true]);

        $obsah = $this->getJson("/api/v1/curation-boards/{$nastenka['uuid']}")->assertOk();
        $obsah->assertJsonPath('items_count', 0);
        $this->assertStringNotContainsString('pozdeji-v-trezoru.jpg', $obsah->getContent());
    }

    public function test_hodnoceni_podniku_neprijme_fotku_z_trezoru(): void
    {
        $podnik = Place::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Bistro', 'type' => 'restaurant', 'city' => 'Brno', 'created_by' => $this->vlastnik->id]);
        $tajna = $this->fotka(['is_hidden' => true]);

        $this->postJson("/api/v1/places/{$podnik->id}/reviews", [
            'status' => 'published', 'overall_rating' => 5, 'currency' => 'CZK', 'media_uuids' => [$tajna],
        ])->assertUnprocessable()->assertJsonValidationErrors('media_uuids');
        $this->assertSame(0, DB::table('place_review_media')->count());
    }

    public function test_recept_ani_vareni_neprijmou_fotku_z_trezoru(): void
    {
        $recept = Recipe::create([
            'gallery_space_id' => $this->prostor->id, 'created_by' => $this->vlastnik->id, 'updated_by' => $this->vlastnik->id,
            'title' => 'Guláš', 'category' => 'main_course', 'difficulty' => 'medium', 'status' => 'published', 'base_servings' => 2, 'currency' => 'CZK',
        ]);
        $tajna = $this->fotka(['is_hidden' => true]);

        $this->postJson("/api/v1/recipes/{$recept->uuid}/media", ['media_uuids' => [$tajna]])
            ->assertUnprocessable()->assertJsonValidationErrors('media_uuids');

        $sezeni = $this->postJson("/api/v1/recipes/{$recept->uuid}/cooking-sessions/start", ['servings' => 2])->assertCreated()->json('uuid');
        $this->putJson("/api/v1/recipes/{$recept->uuid}/cooking-sessions/{$sezeni}/complete", ['overall_rating' => 5, 'media_uuids' => [$tajna]])
            ->assertUnprocessable()->assertJsonValidationErrors('media_uuids');

        $this->assertSame(0, DB::table('recipe_media')->where('media_item_id', $this->id($tajna))->count());
    }

    public function test_pomocnik_neudela_z_fotky_z_trezoru_obal_alba(): void
    {
        $tajna = $this->fotka(['is_hidden' => true]);

        $this->withSession($this->odemcenyTrezor($this->vlastnik))
            ->postJson('/api/v1/assistant/apply', ['message' => 'Dnes jsme byli na kávě', 'media_uuids' => [$tajna]])
            ->assertUnprocessable();

        $this->assertSame(0, Album::query()->where('cover_media_id', $this->id($tajna))->count());
        $this->assertSame(0, DB::table('album_media')->where('media_item_id', $this->id($tajna))->count());
    }

    private function fotka(array $atributy = []): string
    {
        $uuid = (string) Str::uuid();
        DB::table('media_items')->insert(array_merge([
            'uuid' => $uuid, 'gallery_space_id' => $this->prostor->id, 'owner_user_id' => $this->vlastnik->id, 'uploaded_by' => $this->vlastnik->id,
            'original_filename' => 'vylet.jpg', 'safe_filename' => 'vylet.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg',
            'media_type' => 'photo', 'size_bytes' => 100, 'status' => 'ready', 'storage_status' => 'ready', 'is_hidden' => false,
            'created_at' => now(), 'updated_at' => now(),
        ], $atributy));

        return $uuid;
    }

    private function id(string $uuid): int
    {
        return (int) DB::table('media_items')->where('uuid', $uuid)->value('id');
    }
}
