<?php

namespace Tests\Feature\Galerie;

use App\Jobs\Drive\CreateDriveFolderJob;
use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Alba z prototypu jdou do databáze.
 *
 * „Album vytvořeno" i „Zařadit do albumu" zapsaly jen do stavu v prohlížeči:
 * album nebylo v databázi ani na Disku a fotky v něm ležely jen na jedné
 * obrazovce.
 */
class AlbaTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    public function test_album_vznikne_i_s_vybranymi_fotkami_a_slozkou_na_disku(): void
    {
        $a = $this->fotka();
        $b = $this->fotka();

        $odpoved = $this->postJson('/api/alba', ['nazev' => 'Pálava', 'media' => [$a->uuid, $b->uuid]])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $album = Album::where('uuid', $odpoved->json('album'))->sole();

        $this->assertSame('Pálava', $album->title);
        $this->assertSame($this->prostor->id, $album->gallery_space_id);
        $this->assertSame(2, DB::table('album_media')->where('album_id', $album->id)->count());
        $this->assertSame($album->id, $a->fresh()->primary_album_id);
        Queue::assertPushed(CreateDriveFolderJob::class);
        // Knihovna se vrací rovnou, ať se album ukáže bez obnovení stránky.
        $this->assertContains('Pálava', collect($odpoved->json('data.ALBUMS'))->pluck('name')->all());
    }

    public function test_podalbum_pod_nadrazenym(): void
    {
        $rodic = $this->postJson('/api/alba', ['nazev' => 'Morava'])->assertOk()->json('album');

        $dite = $this->postJson('/api/alba', ['nazev' => 'Pálava', 'rodic' => $rodic])->assertOk()->json('album');

        $this->assertSame(Album::where('uuid', $rodic)->value('id'), Album::where('uuid', $dite)->value('parent_id'));
    }

    public function test_zaradit_a_vyjmout(): void
    {
        $foto = $this->fotka();
        $album = $this->postJson('/api/alba', ['nazev' => 'Pálava'])->assertOk()->json('album');

        $odpoved = $this->postJson('/api/alba/zaradit', ['album' => $album, 'media' => [$foto->uuid]])->assertOk();
        $this->assertNotNull($foto->fresh()->primary_album_id);
        // Počet v albu se přepočítá — jinak stálo „0 položek".
        $this->assertSame('1 položka', collect($odpoved->json('data.ALBUMS'))->firstWhere('id', $album)['count']);

        $this->postJson('/api/alba/zaradit', ['album' => null, 'media' => [$foto->uuid]])->assertOk();
        $this->assertNull($foto->fresh()->primary_album_id);
        $this->assertSame(0, DB::table('album_media')->where('media_item_id', $foto->id)->count());
    }

    /** Cizí fotku ani do cizího alba zařadit nejde. */
    public function test_cizi_prostor_nejde(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);
        $ciziFoto = $this->fotka(['gallery_space_id' => $ciziProstor->id]);
        $ciziAlbum = Album::withoutGlobalScopes()->create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $ciziProstor->id, 'title' => 'Cizí', 'slug' => 'cizi',
            'created_by' => $cizi->id,
        ]);

        $this->postJson('/api/alba/zaradit', ['album' => $ciziAlbum->uuid, 'media' => [$ciziFoto->uuid]])->assertNotFound();

        $album = $this->postJson('/api/alba', ['nazev' => 'Moje', 'media' => [$ciziFoto->uuid]])->assertOk()->json('album');
        $this->assertSame(0, DB::table('album_media')->where('album_id', Album::where('uuid', $album)->value('id'))->count());
        $this->assertNull($ciziFoto->fresh()->primary_album_id);
    }

    private function fotka(array $navic = []): MediaItem
    {
        static $poradi = 0;
        $poradi++;

        return MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'IMG_'.$poradi.'.jpg',
            'safe_filename' => 'img-'.$poradi.'.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1024,
            'taken_at' => '2026-09-01 10:00:00',
            'uploaded_at' => '2026-09-01 11:00:00',
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));
    }
}
