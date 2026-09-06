<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Karanténa, výběry fotek a rozpracovaný tisk.
 *
 * Tři seznamy, které prototyp kreslil z napsaných řádků, a přitom všechny tři
 * mají oporu v knihovně.
 */
class ObsahUklidTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    /** Prázdná knihovna nemá co uklízet. */
    public function test_bez_knihovny_se_skupina_neposila(): void
    {
        $this->assertSame([], $this->getJson('/api/data/uklid')->assertOk()->json('data'));
    }

    /**
     * Karanténa je to, co dvojice odložila — ne smazala.
     *
     * A proč to leží stranou, ví jen člověk; server si nic nedomýšlí.
     */
    public function test_karantena_bere_odlozene_fotky(): void
    {
        $this->fotka(['original_filename' => 'V mřížce.HEIC']);
        $this->fotka([
            'original_filename' => 'IMG_2481.HEIC',
            'is_archived' => true,
            'location_name' => 'Zadar',
            'taken_at' => '2026-07-24 10:00:00',
            'size_bytes' => 4_404_019,
            'notes' => 'Rozmazaná, ale je na ní první skok do vody.',
            'purge_after' => now()->addMonths(4),
        ], 2);

        $k = $this->getJson('/api/data/uklid')->assertOk()->json('data.QUAR');

        $this->assertCount(1, $k);
        $this->assertSame('IMG_2481.HEIC', $k[0]['name']);
        $this->assertSame('Zadar · 24. 7. 2026 · 4,2 MB', $k[0]['meta']);
        $this->assertSame('Rozmazaná, ale je na ní první skok do vody.', $k[0]['why']);
        $this->assertSame('4 měsíce', $k[0]['left']);
        $this->assertFalse($k[0]['expired']);
        // Kolik se pustením opravdu uvolní — ne odhad 3,4 MB na položku.
        $this->assertEqualsWithDelta(4.2, $k[0]['mb'], 0.05);
    }

    /** Odložená fotka bez lhůty čeká, dokud si na ni někdo nevzpomene. */
    public function test_karantena_bez_lhuty(): void
    {
        $this->fotka(['is_archived' => true, 'purge_after' => null]);

        $this->assertSame('bez lhůty', $this->getJson('/api/data/uklid')->assertOk()->json('data.QUAR.0.left'));
    }

    /** Neznámá velikost se mlčí — „0 kB“ by byla lež. */
    public function test_neznama_velikost_se_do_popisku_nepise(): void
    {
        $this->fotka([
            'is_archived' => true,
            'size_bytes' => 0,
            'location_name' => null,
            'taken_at' => '2024-05-05 09:20:33',
        ]);

        $this->assertSame('5. 5. 2024', $this->getJson('/api/data/uklid')->assertOk()->json('data.QUAR.0.meta'));
    }

    /** Prošlá lhůta se pozná. */
    public function test_prosla_lhuta_v_karantene(): void
    {
        $this->fotka(['is_archived' => true, 'purge_after' => now()->subWeek()]);

        $k = $this->getJson('/api/data/uklid')->assertOk()->json('data.QUAR.0');

        $this->assertTrue($k['expired']);
        $this->assertSame('lhůta vypršela', $k['left']);
    }

    /**
     * Výběry jsou pořadová čísla do mřížky, ne identifikátory.
     *
     * Prototyp z nich skládá dlaždice (`photos()[i]`), takže musí ukazovat
     * do téže mřížky, kterou posílá knihovna.
     */
    public function test_vybery_jsou_poradi_v_mrizce(): void
    {
        // Nejnovější první — mřížka řadí sestupně.
        $this->fotka(['taken_at' => '2026-08-01 10:00:00', 'is_favorite' => true], 1);
        $this->fotka(['taken_at' => '2026-03-01 10:00:00'], 2);
        $this->fotka(['taken_at' => '2025-06-01 10:00:00', 'is_favorite' => true], 3);

        $v = $this->getJson('/api/data/uklid')->assertOk()->json('data.AGRID');

        // Jedna fotka za rok: 2026 je první v mřížce, 2025 třetí.
        $this->assertSame([0, 2], $v['anniv']['idx']);
        // Do promítání jdou oblíbené.
        $this->assertSame([0, 2], $v['show']['idx']);
        // Kontaktní arch bere prvních 24 — tady jsou tři.
        $this->assertSame([0, 1, 2], $v['contact']['idx']);
    }

    /** Bez oblíbených se výběr pro promítání neposílá. */
    public function test_bez_oblibenych_neni_vyber_pro_promitani(): void
    {
        $this->fotka(['taken_at' => '2026-08-01 10:00:00']);

        $v = $this->getJson('/api/data/uklid')->assertOk()->json('data.AGRID');

        $this->assertArrayNotHasKey('show', $v);
        $this->assertArrayHasKey('contact', $v);
    }

    /** Zakázka do tisku ví, kolik je vybráno a jestli jde ke korektuře. */
    public function test_zakazka_do_tisku(): void
    {
        $this->fotka();

        DB::table('photo_books')->insert([
            [
                'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id,
                'created_by' => $this->adri->id, 'name' => 'Fotokniha Chorvatsko 2026',
                'purpose' => 'photobook', 'item_count' => 128, 'target_count' => 200,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id,
                'created_by' => $this->adri->id, 'name' => 'Rámeček do ložnice',
                'purpose' => 'print', 'item_count' => 1, 'target_count' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $z = collect($this->getJson('/api/data/uklid')->assertOk()->json('data.PJOBS'))->keyBy(2);

        $this->assertSame('kniha', $z['Fotokniha Chorvatsko 2026'][1]);
        $this->assertSame('rozpracováno', $z['Fotokniha Chorvatsko 2026'][3]);
        $this->assertSame('vybráno 128 z 200', $z['Fotokniha Chorvatsko 2026'][6]);
        $this->assertSame('ramecek', $z['Rámeček do ložnice'][1]);
        $this->assertSame('ke korektuře', $z['Rámeček do ložnice'][3]);
    }

    /**
     * Odhad roku stojí jen na tom, co aplikace opravdu vidí.
     *
     * Ukázka odůvodňovala rok tím, že „auto na snímku je Škoda 100, vyráběná
     * 1969–1977". Tohle aplikace nepozná — zná sousední soubor, tentýž import,
     * totéž album a tentýž přístroj.
     */
    public function test_odhad_roku_stoji_na_sousednim_souboru(): void
    {
        $this->fotka(['original_filename' => 'sken_0142.jpg', 'taken_at' => '1988-07-07 12:00:00'], 1);
        $this->fotka(['original_filename' => 'sken_0143.jpg', 'taken_at' => null], 2);

        $d = $this->getJson('/api/data/uklid')->assertOk()->json('data.DATING.0');

        $this->assertSame('sken_0143.jpg', $d['name']);
        $this->assertSame('1988', $d['guess']);
        $this->assertSame(0, $d['span']);
        $this->assertSame('Sousední soubor sken_0142.jpg má datum 7. 7. 1988.', $d['reasons'][0]);
    }

    /** Bez jediné stopy se snímek nabídne — jen bez odhadu. */
    public function test_snimek_bez_stop_nema_odhad(): void
    {
        $this->fotka(['original_filename' => 'sken_0900.jpg', 'taken_at' => null, 'uploaded_at' => null]);

        $d = $this->getJson('/api/data/uklid')->assertOk()->json('data.DATING.0');

        $this->assertSame('', $d['guess']);
        $this->assertSame(0, $d['conf']);
        $this->assertSame(
            'Aplikace nemá z čeho vyjít — kolem téhle fotky není nic s datem.',
            $d['reasons'][0],
        );
    }

    /** Víc stop napříč lety znamená menší jistotu, ne větší. */
    public function test_siroke_rozpeti_snizuje_jistotu(): void
    {
        $album = DB::table('albums')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Rodinné',
            'slug' => 'rodinne',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Názvy schválně nesousední, ať zbude jediná stopa: album.
        $this->fotka(['original_filename' => 'chata.jpg', 'taken_at' => '1974-01-01 12:00:00', 'primary_album_id' => $album], 1);
        $this->fotka(['original_filename' => 'svatba.jpg', 'taken_at' => '1982-01-01 12:00:00', 'primary_album_id' => $album], 2);
        $this->fotka(['original_filename' => 'zahrada.jpg', 'taken_at' => null, 'primary_album_id' => $album, 'uploaded_at' => null], 3);

        $d = $this->getJson('/api/data/uklid')->assertOk()->json('data.DATING.0');

        $this->assertSame('1974', $d['guess']);
        $this->assertSame(4, $d['span']);
        // 60 + jedna stopa − osm let rozpětí: z toho zbude „jen tušení".
        $this->assertSame(24, $d['conf']);
        $this->assertSame(['Ve stejném albu jsou fotky z 1974–1982.'], $d['reasons']);
    }

    /** Datování je úplná kolekce — ukázkové skeny vedle skutečných nezůstanou. */
    public function test_datovani_je_uplna_kolekce(): void
    {
        $this->fotka(['taken_at' => null]);

        $this->assertContains('DATING', $this->getJson('/api/data/uklid')->assertOk()->json('uplne'));
    }

    /** Karanténa jiného páru se do odpovědi nedostane. */
    public function test_karantena_jineho_paru_se_neposila(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $this->fotka(['original_filename' => 'Naše.HEIC', 'is_archived' => true]);
        $this->fotka([
            'original_filename' => 'Cizí.HEIC', 'is_archived' => true,
            'gallery_space_id' => $ciziProstor->id, 'owner_user_id' => $cizi->id, 'uploaded_by' => $cizi->id,
        ], 2);

        $jmena = collect($this->getJson('/api/data/uklid')->assertOk()->json('data.QUAR'))->pluck('name');

        $this->assertSame(['Naše.HEIC'], $jmena->all());
    }

    // ——— pomůcky ———

    private function fotka(array $navic = [], int $poradi = 1): MediaItem
    {
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
            'size_bytes' => 2_097_152,
            'taken_at' => now(),
            'uploaded_at' => now(),
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));
    }
}
