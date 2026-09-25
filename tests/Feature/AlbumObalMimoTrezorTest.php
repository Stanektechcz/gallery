<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use App\Support\SpaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Obal alba nesmí prozradit trezor.
 *
 * Automatický výběr obálky (`fillMissingCovers`) řadil podle „is_favorite",
 * ale nevyřazoval `is_hidden` — fotka z trezoru tak vyhrála konkurz na obal a
 * celý její řádek (jméno souboru, GPS, ID na disku…) odjel na `Albums/Index`.
 * Ručně vybraný obal měl stejnou díru: přesun do trezoru cover_media_id
 * nesmaže, takže `show()`/`index()` ho dál načetly a poslaly na frontend.
 */
class AlbumObalMimoTrezorTest extends TestCase
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

    private function fotka(array $atributy = []): MediaItem
    {
        return MediaItem::withoutGlobalScopes()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->uzivatel->id,
            'uploaded_by' => $this->uzivatel->id,
            'original_filename' => 'tajne-gps.jpg',
            'safe_filename' => 'tajne-gps.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1000,
            'status' => 'ready',
        ], $atributy));
    }

    private function album(): Album
    {
        return Album::create([
            'gallery_space_id' => $this->prostor->id,
            'title' => 'Léto',
            'slug' => 'leto-'.Str::random(6),
            'visibility' => 'shared',
            'created_by' => $this->uzivatel->id,
            'updated_by' => $this->uzivatel->id,
        ]);
    }

    public function test_automaticky_obal_nevybere_fotku_z_trezoru(): void
    {
        $album = $this->album();

        $trezorova = $this->fotka(['is_favorite' => true, 'is_hidden' => true]);
        $verejna = $this->fotka(['is_favorite' => false]);

        DB::table('album_media')->insert([
            ['album_id' => $album->id, 'media_item_id' => $trezorova->id, 'added_at' => now()],
            ['album_id' => $album->id, 'media_item_id' => $verejna->id, 'added_at' => now()],
        ]);
        $album->update(['media_count' => 2]);

        $odpoved = $this->get('/albums');
        $odpoved->assertOk();

        $albums = $odpoved->viewData('page')['props']['albums'];
        $nase = collect($albums)->firstWhere('uuid', $album->uuid);

        $this->assertNotNull($nase['cover'] ?? null, 'Album nedostalo žádný automatický obal.');
        $this->assertSame($verejna->id, $nase['cover']['id'], 'Obal alba je fotka z trezoru.');
    }

    public function test_rucne_vybrany_obal_v_trezoru_se_neukaze(): void
    {
        $trezorova = $this->fotka(['is_hidden' => true]);
        $album = $this->album();
        $album->update(['cover_media_id' => $trezorova->id]);

        $odpoved = $this->get('/albums/'.$album->uuid);
        $odpoved->assertOk();

        $albumData = $odpoved->viewData('page')['props']['album'];
        $this->assertNull($albumData['cover'] ?? null, 'Skrytý obal z trezoru unikl na Albums/Show.');
    }
}
