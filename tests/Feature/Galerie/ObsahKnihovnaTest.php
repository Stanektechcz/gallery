<?php

namespace Tests\Feature\Galerie;

use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Knihovna ve tvaru, ve kterém ji kreslí prototyp.
 *
 * Mřížka fotek se v prototypu nebrala z dat vůbec: dokument si ji vyráběl sám
 * ze šesti napsaných dnů, takže dvojice viděla 55 barevných obdélníků místo
 * svých fotek, v „Lidech" Kláru, kterou nikdy neoznačila, a v úklidu čtyři
 * nálezy o snímcích, které nemá.
 */
class ObsahKnihovnaTest extends TestCase
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

    /** Prázdná knihovna nechává ukázku — prázdná mřížka vypadá jako rozbitá aplikace. */
    public function test_bez_fotek_se_skupina_neposila(): void
    {
        $this->assertSame([], $this->getJson('/api/data/knihovna')->assertOk()->json('data'));
    }

    /**
     * Dlaždice nese den, místo, autora i jméno souboru.
     *
     * Prototyp z těch polí skládá popisky pod fotkou a celý řádek „Sobota
     * 10. ledna 2026 · Praha · 2 položky" nad skupinou.
     */
    public function test_dlazdice_ma_tvar_ktery_prototyp_kresli(): void
    {
        $this->fotka(['taken_at' => '2026-01-10 08:00:00', 'location_name' => 'Karlův most, Praha']);

        $data = $this->getJson('/api/data/knihovna')->assertOk()->json('data');

        $this->assertSame([[
            'key' => '2026-01-10',
            'label' => 'Sobota 10. ledna 2026',
            'short' => '10. 1. 2026',
            'place' => 'Karlův most, Praha',
            'album' => 'Bez alba',
            'n' => 1,
            'y' => 2026,
        ]], $data['DAYS']);

        $foto = $data['PHOTOS'][0];

        $this->assertSame('Sobota 10. ledna 2026', $foto['dayLabel']);
        $this->assertSame('Karlův most, Praha', $foto['place']);
        $this->assertSame('Adrian', $foto['author']);
        $this->assertSame('IMG_1.jpg', $foto['name']);
        $this->assertFalse($foto['isVideo']);
    }

    /**
     * Náhled je podepsaná adresa, ne token v odkazu.
     *
     * Dlaždici stahuje prohlížeč jako obrázek v CSS, kam hlavičku `Authorization`
     * nepřidá. Token v adrese by skončil v historii i v přístupovém logu, takže
     * se místo něj podepisuje jednotlivá cesta — a jen na jeden soubor.
     */
    public function test_nahled_ma_podepsanou_adresu_a_bez_podpisu_neprojde(): void
    {
        $foto = $this->fotka();
        $this->varianta($foto);

        $bg = $this->getJson('/api/data/knihovna')->assertOk()->json('data.PHOTOS.0.bg');

        $this->assertStringStartsWith("url('", $bg);
        $this->assertStringContainsString('signature=', $bg);

        preg_match("/url\('([^']+)'\)/", $bg, $shoda);

        $this->getJson('/api/media/'.$foto->uuid.'/thumb')->assertForbidden();
        $this->get($shoda[1])->assertOk();
    }

    /** Bez zmenšeniny se posílá týž barevný přechod, jaký si prototyp kreslí sám. */
    public function test_fotka_bez_zmenseniny_dostane_prechod_misto_adresy(): void
    {
        $this->fotka();

        $bg = $this->getJson('/api/data/knihovna')->assertOk()->json('data.PHOTOS.0.bg');

        $this->assertStringStartsWith('linear-gradient(', $bg);
        $this->assertStringNotContainsString('url(', $bg);
    }

    /**
     * Chybějící datum a místo se poznají z fotky.
     *
     * Prototyp měl seznam napsaný v `AMISS` jako ukázkové identifikátory, takže
     * úklid hlásil práci u fotek, které neexistují, a mlčel o těch skutečných.
     */
    public function test_chybejici_udaje_se_poznaji_z_fotky(): void
    {
        $this->fotka(['taken_at' => null, 'uploaded_at' => '2026-01-10 08:00:00', 'location_name' => null]);
        $this->fotka(['taken_at' => '2026-01-11 08:00:00', 'location_name' => 'Praha'], 2);

        $fotky = collect($this->getJson('/api/data/knihovna')->assertOk()->json('data.PHOTOS'))
            ->keyBy('name');

        $this->assertSame(['date', 'place'], $fotky['IMG_1.jpg']['miss']);
        $this->assertSame([], $fotky['IMG_2.jpg']['miss']);
    }

    /**
     * Fotka vložená do alba ručně hlásila „Bez alba".
     *
     * Album drží snímky dvěma cestami — `primary_album_id` a spojovací tabulkou.
     * Prototyp jméno alba píše pod každou dlaždici a mřížka podle něj filtruje,
     * takže album otevřené z karty bylo prázdné.
     */
    public function test_album_se_pozna_i_ze_spojovaci_tabulky(): void
    {
        $album = $this->album('Zkouška pořadí');
        $foto = $this->fotka();

        DB::table('album_media')->insert([
            'album_id' => $album->id,
            'media_item_id' => $foto->id,
            'sort_order' => 0,
            'is_cover' => false,
        ]);

        $data = $this->getJson('/api/data/knihovna')->assertOk()->json('data');

        $this->assertSame('Zkouška pořadí', $data['PHOTOS'][0]['album']);
        $this->assertSame([$foto->uuid], $data['MOBIL']['ALBUMS'][0]['ids']);
    }

    /** Podalbum je skutečné, ne pět vymyšlených jmen podle počtu potomků. */
    public function test_podalba_prichazeji_ze_skutecne_hierarchie(): void
    {
        $rodic = $this->album('Chorvatsko 2026');
        $this->album('Zadar', $rodic);
        $this->fotka();

        $alba = collect($this->getJson('/api/data/knihovna')->assertOk()->json('data.ALBUMS'))
            ->keyBy('name');

        $this->assertSame('Zadar', $alba['Chorvatsko 2026']['children'][0]['name']);
        $this->assertSame([], $alba['Zadar']['children']);
    }

    /**
     * Strom alb řadí podle cesty, ne podle poslední změny.
     *
     * Levý sloupec kreslí odsazení podle úrovně; se seznamem řazeným podle
     * `updated_at` by podalbum skočilo nad svého rodiče a odsazení by lhalo.
     */
    public function test_strom_alb_drzi_podalbum_pod_rodicem(): void
    {
        $rodic = $this->album('Chorvatsko 2026');
        $this->album('Zadar', $rodic);
        $this->fotka();

        $strom = $this->getJson('/api/data/knihovna')->assertOk()->json('data.ATREE');

        $this->assertSame(['Chorvatsko 2026', 0], [$strom[0][0], $strom[0][1]]);
        $this->assertSame(['Zadar', 1], [$strom[1][0], $strom[1][1]]);
        // Sedmé pole otevře skutečné album — prototyp měl v obsluze kliknutí
        // napevno „praha".
        $this->assertNotEmpty($strom[1][6]);
    }

    /**
     * Lidé jsou klíčovaní jménem a nesou všechna pole profilu.
     *
     * Prototyp klíč rovnou vypisuje jako jméno a čte `stats`, `years`, `co`,
     * `albums` i `idx` bez jediné pojistky — chybějící pole obrazovku shodí.
     */
    public function test_osoba_ma_uplny_profil(): void
    {
        $album = $this->album('Zkouška pořadí');
        $foto = $this->fotka(['taken_at' => '2026-01-10 08:00:00', 'primary_album_id' => $album->id]);

        $makinka = Person::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Makinka']);
        $adrian = Person::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Adrian']);

        foreach ([$makinka, $adrian] as $osoba) {
            DB::table('media_person')->insert([
                'media_item_id' => $foto->id,
                'person_id' => $osoba->id,
                'created_at' => now(),
            ]);
        }

        $lide = $this->getJson('/api/data/knihovna')->assertOk()->json('data.PERSONS');

        $this->assertArrayHasKey('Makinka', $lide);

        $profil = $lide['Makinka'];

        $this->assertSame('potvrzeno', $profil['tag']);
        $this->assertSame('1 fotka · 2026 · v 1 albu', $profil['meta']);
        $this->assertSame(['Fotek', '1'], $profil['stats'][0]);
        $this->assertSame([['2026', '1', 100, 0]], $profil['years']);
        // S kým se potkává a v jakém albu — obojí ze skutečných značek.
        $this->assertSame('Adrian', $profil['co'][0][0]);
        $this->assertSame('Zkouška pořadí', $profil['albums'][0][0]);
        // Pořadí v mřížce, ne uuid: profil z něj skládá dlaždice.
        $this->assertSame([0], $profil['idx']);
    }

    /**
     * Seznam lidí přichází celý, takže smí přepsat i ukázkové.
     *
     * U ostatních kolekcí platí opak — co server nepošle, zůstává. Kdyby to
     * platilo i tady, dvojice by vedle svých tváří viděla vymyšlenou Kláru.
     */
    public function test_lide_jsou_oznaceni_jako_uplna_kolekce(): void
    {
        $foto = $this->fotka();
        $osoba = Person::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Makinka']);

        DB::table('media_person')->insert([
            'media_item_id' => $foto->id,
            'person_id' => $osoba->id,
            'created_at' => now(),
        ]);

        $odpoved = $this->getJson('/api/data/knihovna')->assertOk();

        // Úzké rozvržení kreslí tytéž lidi z `APEOPLE`, takže platí totéž.
        $this->assertSame(['PERSONS', 'APEOPLE'], $odpoved->json('uplne'));
    }

    /** Skrytá osoba se pozná — prototyp podle toho plní záložku „Skryté". */
    public function test_skryta_osoba_ma_svuj_priznak(): void
    {
        $foto = $this->fotka();
        $osoba = Person::create([
            'gallery_space_id' => $this->prostor->id,
            'name' => 'Kolemjdoucí',
            'is_hidden' => true,
        ]);

        DB::table('media_person')->insert([
            'media_item_id' => $foto->id,
            'person_id' => $osoba->id,
            'created_at' => now(),
        ]);

        $lide = $this->getJson('/api/data/knihovna')->assertOk()->json('data.PERSONS');

        $this->assertSame('skryto', $lide['Kolemjdoucí']['tag']);
    }

    /**
     * Nález úklidu nese položky, vítěze i uvolněné místo.
     *
     * Obrazovka čte `g.items.some(i => i.best)` bez pojistky; nález bez položek
     * ji shodí dřív, než se vykreslí.
     */
    public function test_duplicita_ma_polozky_i_viteze(): void
    {
        $velka = $this->fotka(['size_bytes' => 4_194_304, 'width' => 4032, 'height' => 3024]);
        $mala = $this->fotka(['size_bytes' => 1_048_576, 'width' => 2016, 'height' => 1512], 2);

        $skupina = DB::table('duplicate_groups')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'match_type' => 'exact',
            'resolution' => 'unresolved',
            'detected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$velka, $mala] as $m) {
            DB::table('duplicate_group_items')->insert([
                'duplicate_group_id' => $skupina,
                'media_item_id' => $m->id,
                'is_kept' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $nalez = $this->getJson('/api/data/knihovna')->assertOk()->json('data.DUP_GROUPS.0');

        $this->assertSame('shoda 100 %', $nalez['match']);
        $this->assertCount(2, $nalez['items']);
        $this->assertTrue($nalez['items'][0]['best']);
        $this->assertSame('4032 × 3024 · 4 MB · bez místa', $nalez['items'][0]['meta']);
        // Uvolní se to, co jde do koše — tedy všechno kromě vítěze.
        $this->assertEqualsWithDelta(1.0, $nalez['freed'], 0.01);
    }

    /**
     * Uklizená knihovna se posílá jako prázdno.
     *
     * Jinde prázdná kolekce znamená „nech ukázku"; tady je prázdno odpověď a
     * prototyp na ni má napsané „Knihovna je uklizená". Vymyšlené nálezy by
     * posílaly dvojici uklízet fotky, které nemá.
     */
    public function test_bez_duplicit_se_posila_prazdny_seznam(): void
    {
        $this->fotka();

        $data = $this->getJson('/api/data/knihovna')->assertOk()->json('data');

        $this->assertArrayHasKey('DUP_GROUPS', $data);
        $this->assertSame([], $data['DUP_GROUPS']);
    }

    /** Čísla u položek nabídky počítá server, ne katalog nabídky. */
    public function test_cisla_postranniho_panelu_jsou_ze_skutecne_knihovny(): void
    {
        $this->fotka(['is_favorite' => true, 'taken_at' => '2026-01-10 08:00:00', 'location_name' => 'Praha']);
        $this->fotka(['taken_at' => '2026-01-11 08:00:00', 'location_name' => 'Praha'], 2);
        Person::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Makinka']);

        $data = $this->getJson('/api/data/knihovna')->assertOk()->json('data');

        $this->assertSame('2', $data['NAVCNT']['all']);
        $this->assertSame('1', $data['NAVCNT']['favorites']);
        $this->assertSame('1', $data['NAVCNT']['x-lide']);
        // Nula odznak schová — prázdný řetězec, ne „0".
        $this->assertSame('', $data['NAVCNT']['x-uklid']);
        $this->assertStringStartsWith('2 vzpomínky · ', $data['TOTAL']);
    }

    /** Fotka v koši ani skrytá do knihovny nepatří. */
    public function test_kos_a_skryte_se_nepocitaji(): void
    {
        $this->fotka();
        $this->fotka(['trashed_at' => now()], 2);
        $this->fotka(['is_hidden' => true], 3);

        $data = $this->getJson('/api/data/knihovna')->assertOk()->json('data');

        $this->assertCount(1, $data['PHOTOS']);
        $this->assertSame('1', $data['NAVCNT']['all']);
    }

    /** Cizí prostor se do odpovědi nedostane ani omylem. */
    public function test_fotky_jineho_paru_se_neposilaji(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $this->fotka();
        $this->fotka(['gallery_space_id' => $ciziProstor->id, 'owner_user_id' => $cizi->id, 'uploaded_by' => $cizi->id], 2);

        $fotky = $this->getJson('/api/data/knihovna')->assertOk()->json('data.PHOTOS');

        $this->assertCount(1, $fotky);
        $this->assertSame('IMG_1.jpg', $fotky[0]['name']);
    }

    /** Telefon kreslí tytéž fotky z vlastního, mnohem menšího tvaru. */
    public function test_telefon_dostane_svuj_tvar_teze_knihovny(): void
    {
        $this->fotka(['taken_at' => '2026-01-10 08:00:00', 'location_name' => 'Praha']);

        $mobil = $this->getJson('/api/data/knihovna')->assertOk()->json('data.MOBIL');

        $this->assertSame([['Sobota 10. ledna 2026', 'Praha', 1]], $mobil['DAYS']);
        $this->assertSame(0, $mobil['PHOTOS'][0]['di']);
        $this->assertSame('Praha', $mobil['PHOTOS'][0]['place']);
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
            'width' => 4032,
            'height' => 3024,
            'taken_at' => '2026-01-10 08:00:00',
            'uploaded_at' => '2026-01-10 09:00:00',
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));
    }

    private function varianta(MediaItem $m): void
    {
        DB::table('media_variants')->insert([
            'media_item_id' => $m->id,
            'type' => 'thumbnail',
            'disk' => 'local',
            'path' => 'nahledy/'.$m->uuid.'.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Storage::disk('local')->put('nahledy/'.$m->uuid.'.jpg', 'x');
    }

    private function album(string $jmeno, ?Album $rodic = null): Album
    {
        return Album::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => $jmeno,
            'slug' => Str::slug($jmeno),
            'parent_id' => $rodic?->id,
            'full_display_path' => $rodic ? $rodic->title.' → '.$jmeno : $jmeno,
            'visibility' => 'private',
        ]);
    }
}
