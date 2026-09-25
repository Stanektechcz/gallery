<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\MediaVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * Fotokniha neobsahuje trezor ani koš.
 *
 * Přidat šlo cokoli z prostoru a export ZIP bral originály bez ohledu na
 * `is_hidden`: fotka, která do knihy přišla dřív, než odešla do trezoru
 * (nebo do ní šla přidat rovnou podle uuid), se stáhla v archivu se zamčeným
 * trezorem — a obrazovka knihy ukazovala její název i podepsaný náhled.
 * Fotokniha je na tisk a dárek, tedy ven; trezor je „schované před mřížkou
 * i sdílením", stejně jako ho vynechává export galerie a archiv alba.
 */
class FotoknihaBezTrezoruTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->adri = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'slug' => 'nase', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->attach($this->adri->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);

        $this->actingAs($this->adri);
    }

    public function test_do_knihy_nejde_pridat_trezor_ani_kos(): void
    {
        $kniha = $this->kniha();
        $bezna = $this->fotka('more.jpg');
        $trezor = $this->fotka('pas.jpg', ['is_hidden' => true]);
        $kos = $this->fotka('smazana.jpg', ['trashed_at' => now()]);

        $this->postJson("/api/v1/books/{$kniha}/items", ['media_uuids' => [$bezna->uuid, $trezor->uuid, $kos->uuid]])
            ->assertOk()
            ->assertJsonPath('added', 1);

        $this->assertSame([$bezna->id], DB::table('photo_book_items')->pluck('media_item_id')->all());
    }

    /** Fotka, která do trezoru odešla až po přidání, se z knihy neukáže — ani název, ani náhled. */
    public function test_kniha_neukazuje_fotku_z_trezoru(): void
    {
        $trezor = $this->fotka('pas.jpg');
        $bezna = $this->fotka('more.jpg');
        $kniha = $this->kniha([$trezor, $bezna]);
        $trezor->forceFill(['is_hidden' => true])->save();

        foreach (["/api/v1/books/{$kniha}", "/api/v1/books/{$kniha}/export/contact", '/api/v1/books'] as $adresa) {
            $telo = $this->getJson($adresa)->assertOk()->getContent();

            $this->assertStringNotContainsString('pas.jpg', $telo, "{$adresa} prozrazuje název fotky z trezoru.");
            $this->assertStringNotContainsString($trezor->uuid, $telo, "{$adresa} prozrazuje fotku z trezoru.");
        }

        $this->getJson("/api/v1/books/{$kniha}")
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.filename', 'more.jpg')
            ->assertJsonPath('item_count', 1);

        $seznam = $this->get("/api/v1/books/{$kniha}/export/filelist")->assertOk()->getContent();
        $this->assertStringNotContainsString('pas.jpg', $seznam);
        $this->assertStringContainsString('more.jpg', $seznam);
    }

    /**
     * ZIP je bez trezoru i s odemčeným trezorem.
     *
     * Archiv odchází ven (tiskárna, dárek) a přežije zamčení — stejně jako
     * export galerie a archiv alba ho proto trezor nedostane nikdy.
     */
    public function test_zip_je_bez_trezoru_a_kose(): void
    {
        $trezor = $this->fotka('pas.jpg');
        $kos = $this->fotka('smazana.jpg');
        $bezna = $this->fotka('more.jpg');
        $kniha = $this->kniha([$trezor, $kos, $bezna]);
        $trezor->forceFill(['is_hidden' => true])->save();
        $kos->forceFill(['trashed_at' => now()])->save();

        $this->assertSame(['001_more.jpg'], $this->polozkyZipu($this->get("/api/v1/books/{$kniha}/export/zip")));

        $odemceno = $this->withSession($this->odemcenyTrezor($this->adri))->get("/api/v1/books/{$kniha}/export/zip");
        $this->assertSame(['001_more.jpg'], $this->polozkyZipu($odemceno));
    }

    /** @return list<string> */
    private function polozkyZipu($odpoved): array
    {
        $odpoved->assertOk();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($odpoved->baseResponse->getFile()->getPathname()) === true);

        $jmena = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $jmena[] = $zip->getNameIndex($i);
        }
        $zip->close();

        return $jmena;
    }

    /** @param  list<MediaItem>  $fotky */
    private function kniha(array $fotky = []): string
    {
        $uuid = (string) Str::uuid();
        $id = DB::table('photo_books')->insertGetId([
            'uuid' => $uuid, 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id,
            'name' => 'Léto', 'purpose' => 'print', 'item_count' => count($fotky), 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($fotky as $poradi => $fotka) {
            DB::table('photo_book_items')->insert([
                'photo_book_id' => $id, 'media_item_id' => $fotka->id, 'sort_order' => $poradi, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $uuid;
    }

    private function fotka(string $jmeno, array $navic = []): MediaItem
    {
        $fotka = MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => $jmeno,
            'safe_filename' => $jmeno,
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 100,
            'width' => 4000,
            'height' => 3000,
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));

        foreach (['original', 'thumbnail'] as $typ) {
            $cesta = 'media/'.$fotka->uuid.'/'.$typ.'.jpg';
            Storage::disk('public')->put($cesta, 'jpeg');
            MediaVariant::create(['media_item_id' => $fotka->id, 'type' => $typ, 'disk' => 'public', 'path' => $cesta, 'mime_type' => 'image/jpeg']);
        }

        return $fotka;
    }
}
