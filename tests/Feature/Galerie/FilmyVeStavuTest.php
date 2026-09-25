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
 * Filmy, seriály a žebříček se opravdu uloží.
 *
 * `watch_titles` se do téhle chvíle jen četla. Přehled z ní kreslil pásma
 * S až F, hvězdičky se daly klikat a rozkoukaný seriál posouvat po dílech —
 * a po zavření záložky byl žebříček zase ukázkový.
 */
class FilmyVeStavuTest extends TestCase
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

    /**
     * Bez titulů chodí seznamy filmů prázdné — ne ukázkové „Dune 2".
     *
     * `orders` je vedle nich, a prázdné: ukázka na jeho místě tvrdí
     * „doručeno 8. 1. 2026 · 1 190 Kč", tedy že dvojice zaplatila.
     */
    public function test_bez_titulu_se_seznamy_neposilaji(): void
    {
        $al = (array) $this->getJson('/api/data/pribeh')->assertOk()->json('data.AL');

        foreach (['films', 'series', 'watchlist', 'orders'] as $seznam) {
            $this->assertSame([], $al[$seznam]);
        }
    }

    /** Nový titul z obrazovky vznikne v tabulce. */
    public function test_pridany_titul_vznikne_v_tabulce(): void
    {
        $this->stav(['xRows' => ['films' => [
            ['t' => 'Dune: Part Two', 'm' => 'sledujeme', 'g' => 'hotovo', 'id' => 'films-n1'],
        ]]])->assertOk();

        $radek = DB::table('watch_titles')->sole();

        $this->assertSame('Dune: Part Two', $radek->title);
        $this->assertSame('film', $radek->kind);
        $this->assertSame($this->adri->id, $radek->created_by);
    }

    /** Seriál z vlastní záložky je seriál, ne film. */
    public function test_seznam_urcuje_druh_i_stav(): void
    {
        $this->stav(['xRows' => [
            'series' => [['t' => 'Shogun', 'm' => '', 'g' => 'probíhá', 'id' => 'series-n1']],
            'watchlist' => [['t' => 'Poor Things', 'm' => '', 'g' => 'chceme', 'id' => 'watchlist-n2']],
        ]])->assertOk();

        $this->assertSame('seriál', DB::table('watch_titles')->where('title', 'Shogun')->value('kind'));
        $this->assertSame('chceme', DB::table('watch_titles')->where('title', 'Poor Things')->value('status'));
    }

    /** Odebraný titul z tabulky zmizí — obrazovka ho posílá v `__odebrane`. */
    public function test_odebrany_titul_zmizi(): void
    {
        $zustava = $this->titul('Zůstává');
        $mizi = $this->titul('Mizí');

        $this->stav([
            'xRows' => ['films' => [
                ['t' => 'Zůstává', 'm' => '', 'g' => 'hotovo', 'id' => $this->id('films', $zustava)],
            ]],
            '__odebrane' => ['xRows.films' => [$this->id('films', $mizi)]],
        ])->assertOk();

        $this->assertSame(['Zůstává'], DB::table('watch_titles')->pluck('title')->all());
    }

    /**
     * Titul se pozná podle uuid, ne podle pořadí.
     *
     * `films-3` bylo pořadí: přidal-li ten druhý titul nebo jeden smazal,
     * hodnocení ze starší obrazovky dopadlo na jiný film, starší seznam
     * titul zdvojil a mazání trefilo vedle.
     */
    public function test_titul_podle_uuid_a_starsi_seznam_nic_nezdvoji(): void
    {
        $prvni = $this->titul('Anatomie pádu');
        $druhy = $this->titul('Dune: Part Two');
        $uuidDruhy = DB::table('watch_titles')->where('id', $druhy)->value('uuid');
        $uuidPrvni = DB::table('watch_titles')->where('id', $prvni)->value('uuid');

        $ids = collect($this->getJson('/api/data/pribeh')->json('data.AL.films'))->pluck(7)->all();
        $this->assertSame(['films-'.$uuidPrvni, 'films-'.$uuidDruhy], $ids);

        // Ten druhý mezitím první titul smazal; starší obrazovka hodnotí Dune.
        DB::table('watch_titles')->where('id', $prvni)->delete();

        $this->stav([
            'xRows' => ['films' => [
                ['t' => 'Anatomie pádu', 'm' => '', 'g' => 'hotovo', 'id' => 'films-'.$uuidPrvni],
                ['t' => 'Dune: Part Two', 'm' => '', 'g' => 'hotovo', 'id' => 'films-'.$uuidDruhy],
            ]],
            'tierMap' => ['films-'.$uuidDruhy => 'S'],
            '__odebrane' => ['xRows.films' => []],
        ])->assertOk();

        $this->assertSame(['Dune: Part Two'], DB::table('watch_titles')->pluck('title')->all());
        $this->assertSame('S', DB::table('watch_titles')->where('id', $druhy)->value('tier'));
    }

    /** Titul, který mezitím přidal ten druhý, starší seznam nesmaže; odebraný ano. */
    public function test_odebrany_titul_jen_vyslovne(): void
    {
        $zustane = $this->titul('Zůstává');
        $odebrany = $this->titul('Odebraný');
        $this->titul('Mezitím od Makinky');
        $uuid = fn (int $id) => DB::table('watch_titles')->where('id', $id)->value('uuid');

        $this->stav([
            'xRows' => ['films' => [['t' => 'Zůstává', 'm' => '', 'g' => 'hotovo', 'id' => 'films-'.$uuid($zustane)]]],
            '__odebrane' => ['xRows.films' => ['films-'.$uuid($odebrany)]],
        ])->assertOk();

        $this->assertEqualsCanonicalizing(['Zůstává', 'Mezitím od Makinky'], DB::table('watch_titles')->pluck('title')->all());

        // Znovu odeslaný nový titul se nezdvojí.
        $novy = ['xRows' => ['films' => [['t' => 'Poor Things', 'm' => '', 'g' => 'hotovo', 'id' => 'films-n9']]], '__odebrane' => ['xRows.films' => []]];
        $this->stav($novy)->assertOk();
        $this->stav($novy)->assertOk();
        $this->assertSame(1, DB::table('watch_titles')->where('title', 'Poor Things')->count());

        // Zpět dřív, než obrazovka dostala uuid: odebraný `films-n9` se smaže.
        $this->stav(['xRows' => ['films' => []], '__odebrane' => ['xRows.films' => ['films-n9']]])->assertOk();
        $this->assertSame(0, DB::table('watch_titles')->where('title', 'Poor Things')->count());
        $this->assertSame(2, DB::table('watch_titles')->count());
    }

    /**
     * Hvězdičky se ukládají každému zvlášť.
     *
     * „Sedm" bez toho, kdo ho dal, je ke dvojici k ničemu — celá obrazovka
     * je o tom, že hodnotí každý sám za sebe.
     */
    public function test_hvezdicky_patri_tomu_kdo_je_dal(): void
    {
        $titul = $this->titul('Anatomie pádu');

        // Adrian pošle i hodnotu za Makinku — zapsat se smí jen jeho vlastní.
        $this->stav(['fmRate' => [$this->id('films', $titul) => ['a' => 5, 'm' => 1]]])->assertOk();

        $znamky = DB::table('watch_title_ratings')->where('watch_title_id', $titul)->pluck('rating', 'user_id');

        $this->assertSame(5, (int) $znamky[$this->adri->id]);
        $this->assertArrayNotHasKey($this->maki->id, $znamky->all(), 'Za druhého se nehodnotí.');

        // Makinka hodnotí sama, ze svého účtu — u ní je `a` ona.
        Sanctum::actingAs($this->maki);
        $this->stav(['fmRate' => [$this->id('films', $titul) => ['a' => 4, 'm' => 5]]])->assertOk();
        Sanctum::actingAs($this->adri);

        $znamky = DB::table('watch_title_ratings')->where('watch_title_id', $titul)->pluck('rating', 'user_id');
        $this->assertSame(5, (int) $znamky[$this->adri->id]);
        $this->assertSame(4, (int) $znamky[$this->maki->id]);

        // A z obou vzniká společná známka z desítky, kterou kreslí popisek.
        $radek = $this->getJson('/api/data/pribeh')->assertOk()->json('data.AL.films.0');
        $this->assertStringContainsString('9/10', $radek[1]);
        $this->assertSame(['a' => 5, 'm' => 4], $radek[4]);
    }

    /** Pásmo v žebříčku se uloží a vrátí. */
    public function test_pasmo_v_zebricku_se_ulozi(): void
    {
        $titul = $this->titul('Perfect Days');

        $this->stav(['tierMap' => [$this->id('films', $titul) => 'S']])->assertOk();

        $this->assertSame('S', DB::table('watch_titles')->value('tier'));
        $this->assertSame('S', $this->getJson('/api/data/pribeh')->assertOk()->json('data.AL.films.0.3'));

        // A přehled z něj kreslí pásmo, ne ukázku.
        $this->assertStringStartsWith('S · ', $this->getJson('/api/data/pribeh')->assertOk()->json('data.ABARS.tier.0.0'));
    }

    /** Zrušené zařazení pásmo smaže, ne přepíše na nesmysl. */
    public function test_zrusene_zarazeni_pasmo_smaze(): void
    {
        $titul = $this->titul('Perfect Days', ['tier' => 'S']);

        $this->stav(['tierMap' => [$this->id('films', $titul) => '']])->assertOk();

        $this->assertNull(DB::table('watch_titles')->value('tier'));
    }

    /** Rozkoukaný díl se uloží. */
    public function test_rozkoukany_dil_se_ulozi(): void
    {
        $titul = $this->titul('Shogun', ['kind' => 'seriál', 'status' => 'probíhá', 'episodes_total' => 10]);

        $this->stav(['fmEp' => [$this->id('series', $titul) => 7]])->assertOk();

        $this->assertSame(7, (int) DB::table('watch_titles')->value('episodes_done'));
        // Sérii aplikace nikde neeviduje, takže popisek počítá díly. Dřív
        // tu stálo „S1E7" — tvrzení o první řadě u každého seriálu.
        $this->assertStringContainsString('7 dílů z 10', $this->getJson('/api/data/pribeh')->assertOk()->json('data.AL.series.0.1'));
    }

    /** „Viděli jsme" změní stav titulu. */
    public function test_videli_jsme_zmeni_stav(): void
    {
        $titul = $this->titul('Poor Things', ['status' => 'chceme']);

        $this->stav(['rowDone' => [$this->id('watchlist', $titul) => true]])->assertOk();

        $this->assertSame('hotovo', DB::table('watch_titles')->value('status'));
    }

    /**
     * Cizí klíče v `rowDone` zůstávají.
     *
     * `rowDone` drží odškrtnuté řádky napříč celou aplikací. Vyhodit ho celý
     * kvůli jednomu filmu by smazalo věci, které vlastní tabulku nemají.
     *
     * Seznamy samotné jsou dnes jiný případ: `films` i `shopping` server
     * počítá, a spočítaný seznam se do stavu neukládá vůbec — prototyp čte
     * `xRows[klíč]` přednostně, takže by uložený snímek ten serverový navždy
     * zastínil.
     */
    public function test_cizi_klice_zustavaji_ve_stavu(): void
    {
        $titul = $this->titul('Dune: Part Two');

        $this->stav([
            'xRows' => [
                'films' => [['t' => 'Dune: Part Two', 'm' => '', 'g' => 'hotovo', 'id' => $this->id('films', $titul)]],
            ],
            'rowDone' => [$this->id('films', $titul) => true, 'poznamky-0' => true],
        ])->assertOk();

        $stav = (array) $this->getJson('/api/state')->assertOk()->json('data');

        $this->assertArrayNotHasKey('xRows', $stav);
        $this->assertSame(['poznamky-0' => true], $stav['rowDone']);
    }

    /** Do cizího prostoru se zápis nedostane. */
    public function test_cizi_prostor_zustava_nedotcen(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);

        DB::table('watch_titles')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $ciziProstor->id,
            'title' => 'Cizí film', 'kind' => 'film', 'status' => 'hotovo',
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->stav(['xRows' => ['films' => []]])->assertOk();

        $this->assertSame(1, DB::table('watch_titles')->count());
    }

    /**
     * Pořadí (`films-0`) už na titul nemíří.
     *
     * Server i obě obrazovky titul pojmenují podle uuid (`films-<uuid>`).
     * Pořadí zbylo jen jako cesta, jak starší opis trefí jiný film: ten
     * druhý smazal první titul, `films-0` se posunulo na druhý — a známka,
     * pásmo i „viděli jsme" dopadly na něj.
     */
    public function test_poradi_ze_starsiho_opisu_netrefi_jiny_titul(): void
    {
        $prvni = $this->titul('Anatomie pádu');
        $druhy = $this->titul('Dune: Part Two', ['status' => 'chceme']);
        DB::table('watch_titles')->where('id', $prvni)->delete();

        $this->stav([
            'tierMap' => ['films-0' => 'F', 'watchlist-0' => 'F'],
            'fmRate' => ['films-0' => ['a' => 1], 'watchlist-0' => ['a' => 1]],
            'rowDone' => ['watchlist-0' => true],
        ])->assertOk();

        $radek = DB::table('watch_titles')->where('id', $druhy)->first();
        $this->assertNull($radek->tier);
        $this->assertSame('chceme', $radek->status);
        $this->assertSame(0, DB::table('watch_title_ratings')->count());
    }

    /**
     * Bez `__odebrane` se nemaže nic.
     *
     * Titul, který v seznamu chybí, mohl mezitím přidat ten druhý. Obrazovka
     * odebrání posílá výslovně; seznam bez rozdílu je jen starší opis.
     */
    public function test_chybejici_titul_bez_odebranych_nezmizi(): void
    {
        $zustava = $this->titul('Zůstává');
        $this->titul('Mezitím od Makinky');

        $this->stav(['xRows' => ['films' => [
            ['t' => 'Zůstává', 'm' => '', 'g' => 'hotovo', 'id' => $this->id('films', $zustava)],
        ]]])->assertOk();

        $this->assertSame(2, DB::table('watch_titles')->count());
    }

    // ——— pomůcky ———

    /** Identifikátor řádku tak, jak ho posílá server (`films-<uuid>`). */
    private function id(string $seznam, int $titul): string
    {
        return $seznam.'-'.DB::table('watch_titles')->where('id', $titul)->value('uuid');
    }

    private function titul(string $nazev, array $navic = []): int
    {
        return DB::table('watch_titles')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'title' => $nazev,
            'kind' => 'film',
            'status' => 'hotovo',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
    }

    private function stav(array $patch)
    {
        return $this->patchJson('/api/state', ['data' => $patch]);
    }
}
