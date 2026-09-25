<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use App\Support\SpaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nástroje na obnovu (duplicity, úklidové návrhy) nesmí vidět ani sahat na trezor.
 *
 * `findDuplicates` a `cleanupSuggestions` počítaly a vypisovaly bez ohledu na
 * `is_hidden`, takže odpověď nesla jméno souboru, datum i náhled zamčené
 * položky. `trashDuplicates` šel navíc použít k přesunu trezorové položky
 * rovnou do koše — bez odemčení trezoru.
 *
 * Účet bez galerijního prostoru navíc na `$space->id` padal na 500 — tady se
 * čeká prázdný, ne chybový výsledek.
 */
class RecoveryVsTrezorTest extends TestCase
{
    use RefreshDatabase;

    private User $uzivatel;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        // Viz `ArchivVsTrezorTest` — statická mezipaměť prostorů se sama
        // nezneplatní mezi testy stejného procesu.
        SpaceContext::forget();

        $this->uzivatel = User::factory()->create(['role' => 'owner']);
        $this->prostor = GallerySpace::create(['name' => 'Naše galerie', 'owner_id' => $this->uzivatel->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->uzivatel->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->uzivatel);
    }

    private function polozka(array $atributy = []): MediaItem
    {
        return MediaItem::withoutGlobalScopes()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->uzivatel->id,
            'uploaded_by' => $this->uzivatel->id,
            'original_filename' => 'duplicit.jpg',
            'safe_filename' => 'duplicit.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1000,
            'status' => 'ready',
            'sha256' => str_repeat('a', 64),
        ], $atributy));
    }

    public function test_duplicity_nezahrnuji_trezor(): void
    {
        $this->polozka(['original_filename' => 'verejna1.jpg']);
        $this->polozka(['original_filename' => 'verejna2.jpg']);
        $this->polozka(['original_filename' => 'tajna.jpg', 'is_hidden' => true]);

        $odpoved = $this->getJson('/api/v1/recovery/duplicates');
        $odpoved->assertOk();

        $filenames = collect($odpoved->json('groups.0.items'))->pluck('filename');
        $this->assertSame(2, $odpoved->json('groups.0.count'), 'Trezorová položka se počítá mezi duplicity.');
        $this->assertNotContains('tajna.jpg', $filenames, 'Jméno souboru z trezoru uniklo do seznamu duplicit.');
    }

    public function test_uklidove_navrhy_nezahrnuji_trezor(): void
    {
        $this->polozka(['original_filename' => 'verejna1.jpg', 'sha256' => null]);
        $this->polozka(['original_filename' => 'tajna.jpg', 'sha256' => null, 'is_hidden' => true]);

        $odpoved = $this->getJson('/api/v1/recovery/cleanup');
        $odpoved->assertOk();

        $bezOtisku = collect($odpoved->json('categories'))->firstWhere('key', 'no_fingerprint');
        $this->assertSame(1, $bezOtisku['count'] ?? null, 'Trezorová položka se počítá do úklidových návrhů.');
    }

    public function test_trash_duplicates_nepremisti_trezorovou_polozku(): void
    {
        $trezorova = $this->polozka(['is_hidden' => true]);

        $odpoved = $this->deleteJson('/api/v1/recovery/duplicates/trash', ['media_ids' => [$trezorova->id]]);

        $odpoved->assertOk()->assertJson(['trashed' => 0]);
        $this->assertNull(
            MediaItem::withoutGlobalScopes()->find($trezorova->id)->trashed_at,
            'Zamčená položka trezoru se přesunula do koše bez odemčení.'
        );
    }

    public function test_bez_prostoru_vraci_prazdny_vysledek_misto_padu(): void
    {
        $bezProstoru = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($bezProstoru);

        $this->getJson('/api/v1/recovery/duplicates')->assertOk()->assertJson(['group_count' => 0, 'groups' => []]);
        $this->getJson('/api/v1/recovery/cleanup')->assertOk()->assertJson(['categories' => []]);
    }
}
