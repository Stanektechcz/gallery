<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Napsané přání se opravdu napíše — a chystaný dárek zůstane utajený.
 *
 * Obrazovky čtou `state.wishes || GIFT_WISHES`, takže první napsané přání
 * zastínilo celou sekci: druhý o něm nevěděl a po zavření záložky zmizelo.
 */
class DarkyVeStavuTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $maki;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $this->maki = User::factory()->create(['name' => 'Makinka']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);

        Sanctum::actingAs($this->adri);
    }

    /** Přání je veřejné — o to jde, aby ho druhý viděl. */
    public function test_prani_je_verejne(): void
    {
        $odpoved = $this->stav(['wishes' => [[
            'id' => 'w1757000000000',
            'who' => 'Adrian',
            'title' => 'Kurz keramiky',
            'price' => 2400,
            'note' => 'Ten v Zábrdovicích.',
        ]]])->assertOk();

        $radek = DB::table('gift_ideas')->first();

        $this->assertSame('Kurz keramiky', $radek->title);
        $this->assertSame('wish', $radek->status);
        $this->assertNull($radek->private_to_user_id);
        $this->assertSame($this->adri->id, $radek->created_by);
        $this->assertSame('big', $odpoved->json('data.wishes.0.size'));
        $this->assertContains('wishes', $odpoved->json('docasne'));
    }

    /** Vynulovaná místní kopie (`wishes: null`) přání na serveru nesmaže. */
    public function test_vynulovana_kopie_prani_nic_nesmaze(): void
    {
        $this->stav(['wishes' => [['id' => 'w1757000000000', 'who' => 'Adrian', 'title' => 'Kurz keramiky', 'price' => 2400]]])->assertOk();

        $this->stav(['wishes' => null])->assertOk();

        $this->assertSame(1, DB::table('gift_ideas')->count());
    }

    /**
     * Chystaný dárek je soukromý toho, kdo ho pořizuje.
     *
     * Prozrazený dárek se nedá vzít zpět — proto je tohle nejdůležitější
     * tvrzení v celé sekci.
     */
    public function test_chystany_darek_druhy_neuvidi(): void
    {
        $this->stav(['buys' => [[
            'id' => 'b1757000000000',
            'owner' => 'Adrian',
            'what' => 'Kurz keramiky',
            'price' => 2400,
            'occasion' => 'Vánoce 2026',
            'status' => 'reserved',
            'where' => 've skříni v ložnici',
        ]]])->assertOk();

        $this->assertSame($this->adri->id, (int) DB::table('gift_ideas')->value('private_to_user_id'));

        // Makinka o něm z API nic nezjistí.
        Sanctum::actingAs($this->maki);
        $data = $this->getJson('/api/data/darky')->assertOk()->json('data');

        $this->assertPrazdne($data['GIFT_BUYS'] ?? null);
    }

    /** Nápad se dá povýšit na přání — v tabulce, ne jen na obrazovce. */
    public function test_napad_se_promeni_v_prani(): void
    {
        $uuid = (string) Str::uuid();
        $this->darek(['uuid' => $uuid, 'title' => 'Kurz keramiky', 'status' => 'idea']);

        $this->stav([
            'ideas' => [],
            'wishes' => [['id' => $uuid, 'who' => 'Makinka', 'title' => 'Kurz keramiky', 'price' => 2400, 'note' => 'Z nápadu']],
        ])->assertOk();

        $radek = DB::table('gift_ideas')->where('uuid', $uuid)->first();

        $this->assertSame('wish', $radek->status);
        $this->assertSame($this->maki->id, $radek->created_by);
        $this->assertSame(1, DB::table('gift_ideas')->count());
    }

    /** Zahozený nápad zmizí z tabulky. */
    public function test_zahozeny_napad_zmizi(): void
    {
        $uuid = (string) Str::uuid();
        $this->darek(['uuid' => $uuid, 'title' => 'Zahozený', 'status' => 'idea']);

        $this->stav(['ideas' => [], '__odebrane' => ['ideas' => [$uuid]]])->assertOk();

        $this->assertSame(0, DB::table('gift_ideas')->count());
    }

    /**
     * Prázdný seznam bez `__odebrane` nezahodí nic.
     *
     * Dřív to smazalo všechny nápady v prostoru. Prázdný seznam se přitom nedá
     * odlišit od „ještě jsem se nenačetl" — a starší klient rozdíl neposílá
     * vůbec. Skutečné zahození pozná server jedině podle `__odebrane`.
     */
    public function test_prazdny_seznam_bez_odebranych_napad_nezahodi(): void
    {
        $this->darek(['title' => 'Zůstává', 'status' => 'idea']);

        $this->stav(['ideas' => []])->assertOk();

        $this->assertSame(1, DB::table('gift_ideas')->count());
    }

    /**
     * Cizí schovaný dárek se odsud smazat nedá.
     *
     * V seznamu, který přijde od Adriana, Makinčin schovaný dárek není —
     * a smazat ho proto, že o něm neví, by bylo to nejhorší, co tahle vrstva
     * může udělat.
     */
    public function test_cizi_schovany_darek_prezije(): void
    {
        $this->darek([
            'title' => 'Náhrdelník pro Adriana',
            'status' => 'reserved',
            'created_by' => $this->maki->id,
            'private_to_user_id' => $this->maki->id,
        ]);

        $this->stav(['buys' => []])->assertOk();

        $this->assertSame(1, DB::table('gift_ideas')->count());
    }

    /** Přání druhého páru zůstane, kde bylo. */
    public function test_darky_jineho_paru_se_nemeni(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        $this->darek([
            'title' => 'Cizí přání',
            'status' => 'wish',
            'gallery_space_id' => $ciziProstor->id,
            'created_by' => $cizi->id,
        ]);

        $this->stav(['wishes' => []])->assertOk();

        $this->assertSame(1, DB::table('gift_ideas')->count());
    }

    /**
     * Přání, které mezitím napsal ten druhý, se starším seznamem nesmaže.
     *
     * Seznam v prohlížeči je kopie z doby načtení; karta otevřená přes
     * víkend by jinak při první úpravě smazala všechno, co v ní chybí.
     * Maže se jen to, co prohlížeč sám odebral (`__odebrane`).
     */
    public function test_prani_druheho_se_starsim_seznamem_nesmaze(): void
    {
        $moje = (string) Str::uuid();
        $odebrane = (string) Str::uuid();
        $druheho = (string) Str::uuid();
        $this->darek(['uuid' => $moje, 'title' => 'Moje', 'status' => 'wish']);
        $this->darek(['uuid' => $odebrane, 'title' => 'Odebrané', 'status' => 'wish']);
        $this->darek(['uuid' => $druheho, 'title' => 'Mezitím od Makinky', 'status' => 'wish', 'created_by' => $this->maki->id]);

        $this->stav([
            'wishes' => [['id' => $moje, 'who' => 'Adrian', 'title' => 'Moje upravené', 'price' => 100]],
            '__odebrane' => ['wishes' => [$odebrane]],
        ])->assertOk();

        $this->assertSame(['Mezitím od Makinky', 'Moje upravené'], DB::table('gift_ideas')->orderBy('title')->pluck('title')->all());

        // Rozdíl se do sdíleného stavu neuloží.
        $this->assertArrayNotHasKey('__odebrane', (array) $this->getJson('/api/state')->json('data'));
    }

    /**
     * Starší opis v prohlížeči nepřepíše úpravu druhého u jiné položky.
     *
     * Převodník přepisoval každé přání, které přišlo. Makinka upravila cenu
     * svého přání, Adrian v kartě otevřené od rána změnil jiné — a Makinčina
     * cena se vrátila. Přepisuje se jen to, co prohlížeč označil za změněné.
     */
    public function test_nezmenene_prani_se_starsim_opisem_neprepise(): void
    {
        $jeji = (string) Str::uuid();
        $moje = (string) Str::uuid();
        $this->darek(['uuid' => $jeji, 'title' => 'Kurz keramiky', 'status' => 'wish', 'budget' => 3100, 'created_by' => $this->maki->id]);
        $this->darek(['uuid' => $moje, 'title' => 'Kolo', 'status' => 'wish', 'budget' => 9000]);

        $this->stav([
            'wishes' => [
                // Starší opis: cena 2400, Makinka ji mezitím zvedla na 3100.
                ['id' => $jeji, 'who' => 'Makinka', 'title' => 'Kurz keramiky', 'price' => 2400],
                ['id' => $moje, 'who' => 'Adrian', 'title' => 'Kolo', 'price' => 12000],
            ],
            '__odebrane' => ['wishes' => []],
            '__zmenene' => ['wishes' => [$moje]],
        ])->assertOk();

        $this->assertSame(3100, (int) DB::table('gift_ideas')->where('uuid', $jeji)->value('budget'));
        $this->assertSame(12000, (int) DB::table('gift_ideas')->where('uuid', $moje)->value('budget'));
    }

    /**
     * Chystaný dárek je soukromý i pro kalendář, rozpočty a koordinaci.
     *
     * Převodník psal jen `private_to_user_id`; sloupec `visibility` zůstal
     * na výchozím „shared" a čtení v `/api/v1/calendar/gifts` (a rozpočty
     * dárků, koordinace dvojice) filtruje právě podle něj — Makinka tam
     * „Hodinky pro Makinku" viděla.
     */
    public function test_chystany_darek_neni_videt_ani_v_kalendari(): void
    {
        $this->stav(['buys' => [[
            'id' => 'b1757000000001',
            'owner' => 'Adrian',
            'what' => 'Hodinky pro Makinku',
            'price' => 5400,
            'status' => 'reserved',
        ]]])->assertOk();

        $this->assertSame('private', DB::table('gift_ideas')->value('visibility'));

        Sanctum::actingAs($this->maki);
        $tituly = collect($this->getJson('/api/v1/calendar/gifts')->assertOk()->json())->pluck('title')->all();

        $this->assertNotContains('Hodinky pro Makinku', $tituly);
    }

    /** Přání zůstává veřejné i v kalendáři dárků. */
    public function test_prani_je_v_kalendari_videt(): void
    {
        $this->stav(['wishes' => [['id' => 'w1757000000002', 'who' => 'Adrian', 'title' => 'Kurz keramiky', 'price' => 2400]]])->assertOk();

        $this->assertSame('shared', DB::table('gift_ideas')->value('visibility'));

        Sanctum::actingAs($this->maki);
        $this->assertContains('Kurz keramiky', collect($this->getJson('/api/v1/calendar/gifts')->assertOk()->json())->pluck('title')->all());
    }

    /**
     * Soukromý nápad z kalendáře se úpravou v prototypu neprozradí.
     *
     * Převodník u nápadu vynuloval `private_to_user_id` — nápad, který si
     * Adrian schoval, tak po přejmenování uviděla Makinka.
     */
    public function test_soukromy_napad_uprava_neprozradi(): void
    {
        $uuid = (string) Str::uuid();
        $this->darek(['uuid' => $uuid, 'title' => 'Náušnice', 'status' => 'idea', 'visibility' => 'private', 'private_to_user_id' => $this->adri->id]);

        $this->stav(['ideas' => [['id' => $uuid, 'title' => 'Náušnice se safírem', 'price' => 900]]])->assertOk();

        $radek = DB::table('gift_ideas')->where('uuid', $uuid)->first();
        $this->assertSame('Náušnice se safírem', $radek->title);
        $this->assertSame('private', $radek->visibility);
        $this->assertSame($this->adri->id, (int) $radek->private_to_user_id);
    }

    /**
     * Přání, které ten druhý mezitím smazal, starší opis nevzkřísí.
     *
     * Uuid vydal server — když řádek v tabulce není, někdo ho smazal. Dřív
     * nezměněné přání propadlo do vložení a změněné taky.
     */
    public function test_smazane_prani_starsi_opis_nevzkrisi(): void
    {
        $smazane = (string) Str::uuid();

        // Nezměněné (není v `__zmenene`) i změněné.
        $this->stav([
            'wishes' => [
                ['id' => $smazane, 'who' => 'Adrian', 'title' => 'Smazané přání', 'price' => 100],
                ['id' => (string) Str::uuid(), 'who' => 'Adrian', 'title' => 'Taky smazané', 'price' => 100],
            ],
            '__odebrane' => ['wishes' => []],
            '__zmenene' => ['wishes' => [$smazane]],
        ])->assertOk();

        $this->assertSame(0, DB::table('gift_ideas')->count());
    }

    /**
     * Host prostoru není „ten druhý" — jméno se hledá jen ve dvojici.
     *
     * Mapa jméno → člověk se brala ze všech členů: přání podepsané jménem
     * hosta se připsalo hostovi a nákup „pro" něj dostal jeho soukromí.
     */
    public function test_jmeno_hosta_se_nepripise(): void
    {
        $host = User::factory()->create(['name' => 'Host Honza']);
        $this->prostor->members()->syncWithoutDetaching([$host->id => ['role' => 'viewer']]);

        $this->stav(['buys' => [[
            'id' => 'b1757000000003', 'owner' => 'Host Honza', 'what' => 'Tajný dárek', 'price' => 100, 'status' => 'reserved',
        ]]])->assertOk();

        $radek = DB::table('gift_ideas')->first();
        $this->assertSame($this->adri->id, (int) $radek->created_by);
        $this->assertSame($this->adri->id, (int) $radek->private_to_user_id);
    }

    /**
     * Znovu poslané nové přání se nezaloží podruhé.
     *
     * Identifikátor z obrazovky (`w1757…`) se nikam neukládal, takže seznam
     * poslaný podruhé — druhá úprava během rozjetého zápisu, opakování po
     * chybě — přidal totéž přání znovu.
     */
    public function test_znovu_poslane_nove_prani_se_nezalozi_podruhe(): void
    {
        $prani = ['id' => 'w1757000000000', 'title' => 'Kolo', 'who' => 'Makinka'];

        // Jako skutečná obrazovka: s rozdílem, takže se nic „chybějícího" nemaže.
        $this->stav(['wishes' => [$prani], '__odebrane' => ['wishes' => []]])->assertOk();
        $this->stav(['wishes' => [$prani], '__odebrane' => ['wishes' => []]])->assertOk();

        $this->assertSame(1, DB::table('gift_ideas')->count());
        $this->assertSame('w1757000000000', DB::table('gift_ideas')->value('client_id'));
        $this->assertTrue(Str::isUuid((string) DB::table('gift_ideas')->value('uuid')), 'Uuid vydává dál server.');
    }

    /** Úprava přání, které ještě nemá uuid, mění tentýž řádek. */
    public function test_uprava_noveho_prani_pred_uuid_meni_stejny_radek(): void
    {
        $this->stav(['wishes' => [['id' => 'w1757000000000', 'title' => 'Kolo', 'who' => 'Makinka']]])->assertOk();

        $this->stav([
            'wishes' => [['id' => 'w1757000000000', 'title' => 'Horské kolo', 'who' => 'Makinka']],
            '__odebrane' => ['wishes' => []],
            '__zmenene' => ['wishes' => ['w1757000000000']],
        ])->assertOk();

        $this->assertSame(1, DB::table('gift_ideas')->count());
        $this->assertSame('Horské kolo', DB::table('gift_ideas')->value('title'));
    }

    /** Nový chystaný dárek poslaný znovu zůstane jeden — a soukromý. */
    public function test_znovu_poslany_nakup_se_nezalozi_podruhe(): void
    {
        $nakup = ['id' => 'b1757000000009', 'owner' => 'Adrian', 'what' => 'Hodinky', 'price' => 900, 'status' => 'reserved'];

        $this->stav(['buys' => [$nakup], '__odebrane' => ['buys' => []]])->assertOk();
        $this->stav(['buys' => [$nakup], '__odebrane' => ['buys' => []]])->assertOk();

        $this->assertSame(1, DB::table('gift_ideas')->count());
        $this->assertSame($this->adri->id, (int) DB::table('gift_ideas')->value('private_to_user_id'));
    }

    /** Přání smazané dřív, než obrazovka dostala jeho uuid, zmizí i z tabulky. */
    public function test_prani_odebrane_pred_uuid_se_smaze(): void
    {
        $this->stav(['wishes' => [['id' => 'w1757000000000', 'title' => 'Kolo', 'who' => 'Makinka']]])->assertOk();

        $this->stav(['wishes' => [], '__odebrane' => ['wishes' => ['w1757000000000']]])->assertOk();

        $this->assertSame(0, DB::table('gift_ideas')->count());
    }

    /**
     * Starý klíč v odebraných nesmaže přání, které v seznamu je pod uuid.
     *
     * Prohlížeč si pamatuje všechno, co v seznamu kdy bylo — i `w1757…`,
     * které odpověď serveru nahradila uuid. V dalších odebraných pak visí.
     */
    public function test_stary_klic_v_odebranych_nesmaze_prani_s_uuid(): void
    {
        $this->stav(['wishes' => [['id' => 'w1757000000000', 'title' => 'Kolo', 'who' => 'Makinka']]])->assertOk();
        $uuid = (string) DB::table('gift_ideas')->value('uuid');

        $this->stav([
            'wishes' => [['id' => $uuid, 'title' => 'Kolo', 'who' => 'Makinka']],
            '__odebrane' => ['wishes' => ['w1757000000000']],
            '__zmenene' => ['wishes' => []],
        ])->assertOk();

        $this->assertSame(1, DB::table('gift_ideas')->count());
    }

    // ——— pomůcky ———

    private function stav(array $patch)
    {
        return $this->patchJson('/api/state', ['data' => $patch]);
    }

    private function darek(array $navic = []): void
    {
        DB::table('gift_ideas')->insert(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => 'Dárek',
            'budget' => 1000,
            'currency' => 'CZK',
            'status' => 'idea',
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
    }
}
