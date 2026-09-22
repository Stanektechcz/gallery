<?php

namespace Tests\Feature\Galerie;

use App\Models\Album;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\Person;
use App\Models\User;
use Carbon\CarbonImmutable;
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

    /** Prázdná knihovna chodí prázdná — ukázkové fotky by byly cizí. */
    public function test_bez_fotek_se_skupina_neposila(): void
    {
        $this->assertPrazdne($this->getJson('/api/data/knihovna')->assertOk()->json('data'));
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
     * Čas pořízení jde s fotkou, čas nahrání ne.
     *
     * Série snímků se skládá z rozestupu mezi nimi; kdyby se dosadil čas
     * nahrání, byla by celá dávka z telefonu jedna „série".
     */
    public function test_cas_porizeni_jde_jen_se_skutecnym_datem(): void
    {
        $this->fotka(['taken_at' => null, 'uploaded_at' => '2026-01-10 08:00:00']);
        $this->fotka(['taken_at' => '2026-01-11 08:00:01'], 2);

        $fotky = collect($this->getJson('/api/data/knihovna')->assertOk()->json('data.PHOTOS'))
            ->keyBy('name');

        $this->assertArrayNotHasKey('ts', $fotky['IMG_1.jpg']);
        $this->assertSame(CarbonImmutable::parse('2026-01-11 08:00:01')->getTimestamp(), $fotky['IMG_2.jpg']['ts']);
    }

    /**
     * Prohlížeč fotky ukazoval u každé fotky čas „06:42" a „nahráno" s dnem
     * pořízení. Čas je z fotoaparátu, nahrání je okamžik v pásmu dvojice.
     */
    public function test_cas_porizeni_a_den_nahrani_jsou_z_fotky(): void
    {
        $this->fotka(['taken_at' => null, 'uploaded_at' => '2026-01-10 23:30:00']);
        $this->fotka(['taken_at' => '2025-12-24 17:45:00', 'uploaded_at' => '2026-01-11 08:00:00'], 2);

        $data = $this->getJson('/api/data/knihovna')->assertOk()->json('data');
        $fotky = collect($data['PHOTOS'])->keyBy('name');

        $this->assertArrayNotHasKey('timeVal', $fotky['IMG_1.jpg']);
        // 23:30 UTC je v Praze už 11. ledna.
        $this->assertSame('11. 1. 2026', $fotky['IMG_1.jpg']['nahrano']);
        $this->assertSame('17:45', $fotky['IMG_2.jpg']['timeVal']);
        $this->assertSame('11. 1. 2026', $fotky['IMG_2.jpg']['nahrano']);

        $telefon = collect($data['MOBIL']['PHOTOS'])->keyBy('id');
        $this->assertSame('11. 1. 2026', $telefon[$fotky['IMG_2.jpg']['id']]['nahrano']);
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
    /**
     * Šipka v názvu alba z něj nedělá podalbum.
     *
     * „Beskydy → Pustevny" je hlavní album: strom ho kreslil jako „Pustevny"
     * o úroveň níž a přehled ho nepočítal mezi hlavní.
     */
    public function test_sipka_v_nazvu_neni_hierarchie(): void
    {
        $this->album('Beskydy → Pustevny');
        $rodic = $this->album('Chorvatsko 2026');
        $this->album('Zadar', $rodic);
        $this->fotka();

        $data = $this->getJson('/api/data/knihovna')->assertOk()->json('data');
        $alba = collect($data['ALBUMS'])->keyBy('name');
        $strom = collect($data['ATREE'])->keyBy(0);

        $this->assertNull($alba['Beskydy → Pustevny']['parent']);
        $this->assertSame(0, $alba['Beskydy → Pustevny']['depth']);
        $this->assertSame($rodic->uuid, $alba['Zadar']['parent']);
        $this->assertSame(1, $alba['Zadar']['depth']);
        $this->assertSame(0, $strom['Beskydy → Pustevny'][1]);
        $this->assertSame(1, $strom['Zadar'][1]);
    }

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
        // `ATAGS` chodí i bez štítků (prázdné záložky), proto je v seznamu také.
        $this->assertContains('PERSONS', $odpoved->json('uplne'));
        $this->assertContains('APEOPLE', $odpoved->json('uplne'));
        // Skládané kolekce (`AL`, `MOBIL`) úplné být nesmí — smazaly by jiné skupiny.
        $this->assertNotContains('AL', $odpoved->json('uplne'));
        $this->assertNotContains('MOBIL', $odpoved->json('uplne'));
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

    /**
     * Koš, Sdílené, Plánování a Cesta právě nyní mají svoje počty.
     *
     * V katalogu nabídky byla čísla z ukázky (Zprávy 2, Plánování 3,
     * Sdílené 5, Koš 4, „den 5") a server je nepřepisoval — i dvojice bez
     * jediného odkazu nebo úkolu je tak v nabídce viděla. Knihovna je tu
     * záměrně bez fotek: odznaky musí přijít i tehdy.
     */
    public function test_ostatni_odznaky_nabidky_jsou_ze_skutecnych_dat(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-11 10:00:00', 'UTC'));

        $this->fotka(['trashed_at' => now()]);
        $this->fotka(['trashed_at' => now()], 2);
        DB::table('shared_links')->insert([
            ['uuid' => (string) Str::uuid(), 'token' => Str::random(40), 'created_by' => $this->adri->id, 'gallery_space_id' => $this->prostor->id, 'target_type' => 'album', 'is_active' => true, 'expires_at' => null],
            // Zneplatněný a prošlý odkaz se nepočítá.
            ['uuid' => (string) Str::uuid(), 'token' => Str::random(40), 'created_by' => $this->adri->id, 'gallery_space_id' => $this->prostor->id, 'target_type' => 'album', 'is_active' => false, 'expires_at' => null],
            ['uuid' => (string) Str::uuid(), 'token' => Str::random(40), 'created_by' => $this->adri->id, 'gallery_space_id' => $this->prostor->id, 'target_type' => 'album', 'is_active' => true, 'expires_at' => '2026-10-01 00:00:00'],
        ]);
        foreach ([['2026-10-10 18:00:00', 'open'], ['2026-10-11 20:00:00', 'open'], ['2026-10-09 18:00:00', 'completed'], ['2026-10-20 18:00:00', 'open']] as [$termin, $stav]) {
            DB::table('shared_todos')->insert([
                'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id,
                'title' => 'Úkol', 'status' => $stav, 'due_at' => $termin,
            ]);
        }
        DB::table('trips')->insert([
            'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id,
            'name' => 'Lisabon', 'start_date' => '2026-10-09', 'end_date' => '2026-10-14',
        ]);

        $pocty = $this->getJson('/api/data/knihovna')->assertOk()->json('data.NAVCNT');

        $this->assertSame('2', $pocty['trash']);
        $this->assertSame('1', $pocty['shared']);
        // Dnešní a zmeškaný úkol; hotový ani budoucí ne.
        $this->assertSame('2', $pocty['x-plan']);
        $this->assertSame('den 3', $pocty['x-teď']);
        // Nepřečtené zprávy aplikace nesleduje — odznak zůstane schovaný.
        $this->assertSame('', $pocty['x-zpravy']);
        $this->assertSame('', $pocty['all']);
    }

    /** Bez cesty a bez koše odznaky zmizí, ukázková čísla nezůstanou. */
    public function test_prazdna_nabidka_schova_odznaky(): void
    {
        $pocty = $this->getJson('/api/data/knihovna')->assertOk()->json('data.NAVCNT');

        foreach (['all', 'favorites', 'trash', 'shared', 'x-plan', 'x-zpravy', 'x-teď'] as $klic) {
            $this->assertSame('', $pocty[$klic], $klic);
        }
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

        // Čtvrté pole je den — zápis k dni z telefonu patří k němu, ne k dnešku.
        $this->assertSame([['Sobota 10. ledna 2026', 'Praha', 1, '2026-01-10']], $mobil['DAYS']);
        $this->assertSame(0, $mobil['PHOTOS'][0]['di']);
        $this->assertSame('Praha', $mobil['PHOTOS'][0]['place']);
    }

    /**
     * Fotokalendář dostane počet fotek za každý den celé knihovny.
     *
     * Dřív si ho počítal vzorečkem z data — u každé dvojice stejná vymyšlená
     * čísla. Koš a trezor se nepočítají, stejně jako v mřížce.
     */
    public function test_kalendar_dostane_skutecne_pocty_po_dnech(): void
    {
        $this->fotka(['taken_at' => '2026-01-10 08:00:00'], 1);
        $this->fotka(['taken_at' => '2026-01-10 18:30:00'], 2);
        $this->fotka(['taken_at' => '2025-12-24 20:00:00'], 3);
        $this->fotka(['taken_at' => '2025-12-24 21:00:00', 'trashed_at' => now()], 4);
        $this->fotka(['taken_at' => '2025-12-24 22:00:00', 'is_hidden' => true], 5);

        $dny = $this->getJson('/api/data/knihovna')->assertOk()->json('data.FOTODNY');

        $this->assertSame(['2025-12-24' => 1, '2026-01-10' => 2], collect($dny)->sortKeys()->all());
    }

    /**
     * Statistiky jsou přes celou knihovnu, ne přes výřez mřížky.
     *
     * Obrazovka počítala z posledních 240 fotek a snímku bez času vymyslela
     * hodinu. Bez času se do hodin nepočítá nic, video nese skutečnou délku.
     */
    public function test_statistiky_knihovny_jsou_ze_vsech_fotek(): void
    {
        // Místo je první část adresy — „Praha, Česko" i „Praha" jsou jedno místo.
        $this->fotka(['taken_at' => '2026-01-10 08:15:00', 'location_name' => 'Praha, Česko', 'camera_make' => 'Apple', 'camera_model' => 'iPhone 15'], 1);
        $this->fotka(['taken_at' => '2025-07-01 20:00:00', 'location_name' => 'Praha', 'media_type' => 'video', 'duration_ms' => 30_000], 2);
        $this->fotka(['taken_at' => null, 'uploaded_at' => '2026-02-01 10:00:00', 'is_favorite' => true], 3);

        $s = $this->getJson('/api/data/knihovna')->assertOk()->json('data.LIBSTATS');

        $this->assertSame(3, $s['total']);
        $this->assertSame(1, $s['videos']);
        $this->assertSame(1, $s['favs']);
        $this->assertSame(30, $s['videoAvg']);
        $this->assertSame(['2025' => 1, '2026' => 2], collect($s['years'])->sortKeys()->all());
        $this->assertSame(['Praha' => 2], $s['places']);
        $this->assertSame(1, $s['placeN']);
        $this->assertSame(1, $s['hours'][8]);
        $this->assertSame(1, $s['hours'][20]);
        $this->assertSame(2, array_sum($s['hours']), 'Snímek bez času pořízení se do hodin nepočítá.');
        // Značka se přidá, když ji model sám nenese — stejně jako u dlaždice.
        $this->assertSame(1, $s['dev']['Apple iPhone 15']);
        $this->assertSame(3, $s['autori']['Adrian']['count']);
        $this->assertSame('Praha', $s['autori']['Adrian']['place']);
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
