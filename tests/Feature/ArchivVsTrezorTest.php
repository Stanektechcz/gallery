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
 * Archiv nesmí ukázat trezor, ani přes něj se dát pohnout.
 *
 * `is_archived` a `is_hidden` jsou na sobě nezávislé — přesun do trezoru
 * archivní příznak nesmaže. Bez filtru na `is_hidden` tak archiv obsahem
 * (jméno souboru, datum, velikost) prozradil zamčený trezor, a hromadné
 * odarchivování šlo použít i na jeho položky.
 */
class ArchivVsTrezorTest extends TestCase
{
    use RefreshDatabase;

    private User $uzivatel;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        // Statická mezipaměť prostorů (`SpaceContext`) se mezi testy stejného
        // procesu sama nezneplatní a SQLite po `RefreshDatabase` recykluje ID —
        // bez tohohle řádku by test zdědil seznam prostorů z předchozího testu.
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
            'original_filename' => 'tajne.jpg',
            'safe_filename' => 'tajne.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1000,
            'status' => 'ready',
            'is_archived' => true,
        ], $atributy));
    }

    public function test_archiv_nevraci_polozky_z_trezoru(): void
    {
        $verejna = $this->polozka(['original_filename' => 'vylet.jpg']);
        $trezorova = $this->polozka(['original_filename' => 'tajne.jpg', 'is_hidden' => true]);

        $odpoved = $this->actingAs($this->uzivatel)->get('/archive');

        $odpoved->assertOk();
        $data = $odpoved->viewData('page')['props']['media']['data'];
        $uuids = array_column($data, 'uuid');

        $this->assertContains($verejna->uuid, $uuids);
        $this->assertNotContains($trezorova->uuid, $uuids, 'Položka z trezoru unikla do archivu.');
    }

    /** Statistiky počítají archiv stejně jako archiv sám — bez trezoru. */
    public function test_statistiky_nepocitaji_archivovany_trezor(): void
    {
        $this->polozka(['original_filename' => 'vylet.jpg']);
        $this->polozka(['original_filename' => 'tajne.jpg', 'is_hidden' => true]);

        $stats = $this->actingAs($this->uzivatel)->get('/stats')->assertOk()
            ->viewData('page')['props']['stats'];

        $this->assertSame(1, $stats['archived']);
    }

    public function test_hromadne_odarchivovani_se_nedotkne_trezoru(): void
    {
        $trezorova = $this->polozka(['is_hidden' => true]);

        $odpoved = $this->postJson('/archive/bulk-unarchive', ['uuids' => [$trezorova->uuid]]);

        $odpoved->assertOk()->assertJson(['count' => 0]);
        $this->assertTrue(
            MediaItem::withoutGlobalScopes()->find($trezorova->id)->is_archived,
            'Zamčená položka trezoru se odarchivovala přes archivní hromadnou akci.'
        );
    }
}
