<?php

namespace Tests\Feature\Galerie;

use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nahrávání z telefonu rovnou do alba.
 *
 * Dvojice chtěla z Androidu nahrát celé album fotek a mít ho v galerii zase
 * jako album. Každá dokončená fotka se do alba zařadí hned na serveru — kdyby
 * se zařazovalo až na konci dávky, zavřený prohlížeč uprostřed nahrávání by
 * nechal nahrané fotky mimo album a nikdo by nevěděl které.
 *
 * Zároveň hlídá cestu po částech, kterou telefon teď používá pro všechny
 * soubory: datum pořízení v hlavičce, chybějící části a limity serveru.
 */
class NahravaniDoAlbaTest extends TestCase
{
    use RefreshDatabase;

    /** Začátek skutečného souboru MP4 (`ftyp` box), podle kterého ho pozná `finfo`. */
    private const MP4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();

        $this->adri = User::factory()->create();
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->adri->gallerySpaces()->syncWithoutDetaching([$this->prostor->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    public function test_maly_soubor_se_zaradi_do_alba(): void
    {
        $album = $this->album('Dovolená Zadar');

        $id = $this->post('/api/media', ['file' => $this->fotka('a.jpg'), 'album' => $album->uuid])
            ->assertCreated()
            ->assertJsonPath('album', $album->uuid)
            ->json('id');

        $media = MediaItem::where('uuid', $id)->sole();
        $this->assertSame($album->id, (int) $media->primary_album_id);
        $this->assertTrue(DB::table('album_media')->where('album_id', $album->id)->where('media_item_id', $media->id)->exists());
        $this->assertSame(1, (int) $album->fresh()->media_count, 'Počet v albu se musí přepočítat, jinak by album ukazovalo „0 položek".');
    }

    public function test_soubor_po_castech_se_zaradi_do_alba_a_nese_datum(): void
    {
        $album = $this->album('Výlet');
        $casti = [self::MP4.'prvni--', 'druha'];

        foreach ($casti as $poradi => $cast) {
            $odpoved = $this->cast('up-album-1', $poradi, count($casti), $cast, [
                'X-Album' => $album->uuid,
                'X-Taken-At' => '1756000000000',
            ]);
        }

        $odpoved->assertCreated()->assertJsonPath('album', $album->uuid);

        $media = MediaItem::sole();
        $this->assertSame($album->id, (int) $media->primary_album_id);
        $this->assertSame(1_756_000_000, $media->taken_at->getTimestamp(), 'Datum z telefonu se po částech ztrácelo — fotka pak spadla na konec časové osy.');
    }

    /** Opakované nahrání složky doplní album i o fotky, které v knihovně už byly. */
    public function test_duplicitni_fotka_se_do_alba_zaradi_taky(): void
    {
        $obsah = $this->fotka('a.jpg')->get();
        $puvodni = $this->post('/api/media', ['file' => UploadedFile::fake()->createWithContent('a.jpg', $obsah)])->assertCreated()->json('id');
        $album = $this->album('Znovu');

        $this->post('/api/media', ['file' => UploadedFile::fake()->createWithContent('a.jpg', $obsah), 'album' => $album->uuid])
            ->assertOk()
            ->assertJsonPath('status', 'duplicate')
            ->assertJsonPath('id', $puvodni)
            ->assertJsonPath('album', $album->uuid);

        $this->assertSame($album->id, (int) MediaItem::sole()->primary_album_id);
    }

    /** Fotka z trezoru do sdíleného alba nepatří, ani když ji někdo nahraje znovu. */
    public function test_duplicitni_fotka_z_trezoru_do_alba_nejde(): void
    {
        $obsah = $this->fotka('t.jpg')->get();
        $this->post('/api/media', ['file' => UploadedFile::fake()->createWithContent('t.jpg', $obsah)])->assertCreated();
        MediaItem::query()->update(['is_hidden' => true]);
        $album = $this->album('Sdílené');

        $this->post('/api/media', ['file' => UploadedFile::fake()->createWithContent('t.jpg', $obsah), 'album' => $album->uuid])
            ->assertOk()
            ->assertJsonPath('status', 'duplicate')
            ->assertJsonPath('album', null);

        $this->assertFalse(DB::table('album_media')->where('album_id', $album->id)->exists());
        $this->assertNull(MediaItem::withoutGlobalScopes()->sole()->primary_album_id);
    }

    /** Cizí nebo smazané album se odmítne dřív, než se cokoli uloží. */
    public function test_cizi_ani_smazane_album_neprojde(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);
        $ciziAlbum = Album::withoutGlobalScopes()->create([
            'gallery_space_id' => $ciziProstor->id, 'title' => 'Cizí album', 'slug' => 'cizi-album',
            'created_by' => $cizi->id, 'updated_by' => $cizi->id,
        ]);

        $this->post('/api/media', ['file' => $this->fotka('a.jpg'), 'album' => $ciziAlbum->uuid], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Album, do kterého se nahrává, už v galerii není. Založte ho znovu.');

        $smazane = $this->album('Smazané');
        $smazane->delete();

        $this->cast('up-smazane', 0, 2, self::MP4.'x', ['X-Album' => $smazane->uuid])->assertStatus(422);

        $this->assertSame(0, MediaItem::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('upload_chunks'), 'Část pro neexistující album se nemá ani zapsat.');
    }

    /**
     * Poslední část bez předchozích neskončí tichým „nahráno".
     *
     * Když se po výpadku spojení poslala znovu jen poslední část (server už
     * soubor mezitím složil a části smazal), odpověď byla 202 a telefon to
     * počítal jako hotové. Server teď řekne, které části mu chybí.
     */
    public function test_posledni_cast_rekne_ktere_casti_chybi(): void
    {
        $this->cast('up-chybi', 3, 4, 'posledni')
            ->assertStatus(202)
            ->assertJsonPath('status', 'partial')
            ->assertJsonPath('chybi', [0, 1, 2]);

        // Doplnění chybějících částí soubor složí.
        $this->cast('up-chybi', 0, 4, self::MP4.'a');
        $this->cast('up-chybi', 1, 4, 'b');
        $this->cast('up-chybi', 2, 4, 'c')->assertCreated()->assertJsonPath('status', 'stored');

        $this->assertSame(self::MP4.'abcposledni', Storage::disk('public')->get(MediaItem::sole()->variants()->where('type', 'original')->sole()->path));
    }

    /** Telefon si před nahráváním zjistí, jak velké části server přijme a jestli může zapisovat. */
    public function test_limity_nahravani(): void
    {
        $odpoved = $this->getJson('/api/media/limity')->assertOk()->assertJsonPath('zapis', true);

        $cast = (int) $odpoved->json('cast');
        $this->assertGreaterThanOrEqual(256 * 1024, $cast);
        $this->assertLessThanOrEqual(8 * 1024 * 1024, $cast);

        $post = $this->bajty((string) ini_get('post_max_size'));
        if ($post > 0) {
            $this->assertLessThan($post, $cast, 'Část musí projít pod post_max_size, jinak ji PHP zahodí (413).');
        }
    }

    public function test_host_nahrat_do_alba_nesmi(): void
    {
        $album = $this->album('Naše');
        $host = User::factory()->create();
        $this->prostor->members()->syncWithoutDetaching([$host->id => ['role' => 'viewer']]);
        Sanctum::actingAs($host);

        $this->post('/api/media', ['file' => $this->fotka('a.jpg'), 'album' => $album->uuid], ['Accept' => 'application/json'])->assertForbidden();
        $this->cast('up-host', 0, 1, self::MP4, ['X-Album' => $album->uuid])->assertForbidden();
        $this->getJson('/api/media/limity')->assertForbidden();

        $this->assertSame(0, MediaItem::withoutGlobalScopes()->count());
    }

    // ——— pomocné ———

    private function album(string $nazev): Album
    {
        $uuid = $this->postJson('/api/alba', ['nazev' => $nazev])->assertOk()->json('album');

        return Album::withoutGlobalScopes()->where('uuid', $uuid)->sole();
    }

    private function cast(string $id, int $poradi, int $celkem, string $obsah, array $dalsi = [])
    {
        $server = ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'];

        foreach ([
            'X-Upload-Id' => $id,
            'X-Chunk-Index' => (string) $poradi,
            'X-Chunk-Count' => (string) $celkem,
            'X-File-Name' => 'video.mp4',
        ] + $dalsi as $jmeno => $hodnota) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($jmeno))] = $hodnota;
        }

        return $this->call('POST', '/api/media/chunk', [], [], [], $server, $obsah);
    }

    private function fotka(string $jmeno, string $sul = ''): UploadedFile
    {
        $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
            ."\xFF\xDB\x00\x43\x00".str_repeat("\x08", 64)
            .$sul."\xFF\xD9";

        return UploadedFile::fake()->createWithContent($jmeno, $jpeg);
    }

    private function bajty(string $hodnota): int
    {
        $cislo = (int) $hodnota;

        return match (strtolower(substr(trim($hodnota), -1))) {
            'g' => $cislo * 1024 ** 3,
            'm' => $cislo * 1024 ** 2,
            'k' => $cislo * 1024,
            default => $cislo,
        };
    }
}
