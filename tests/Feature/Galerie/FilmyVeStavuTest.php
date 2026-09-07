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
     * Bez titulů se seznamy filmů neposílají — obrazovka si nechá ukázku.
     *
     * `orders` je vedle nich, a prázdné: ukázka na jeho místě tvrdí
     * „doručeno 8. 1. 2026 · 1 190 Kč", tedy že dvojice zaplatila.
     */
    public function test_bez_titulu_se_seznamy_neposilaji(): void
    {
        $al = (array) $this->getJson('/api/data/pribeh')->assertOk()->json('data.AL');

        $this->assertSame(['orders' => []], $al);
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

    /** Odebraný titul z tabulky zmizí. */
    public function test_odebrany_titul_zmizi(): void
    {
        $this->titul('Zůstává');
        $this->titul('Mizí');

        $this->stav(['xRows' => ['films' => [
            ['t' => 'Zůstává', 'm' => '', 'g' => 'hotovo', 'id' => 'films-0'],
        ]]])->assertOk();

        $this->assertSame(['Zůstává'], DB::table('watch_titles')->pluck('title')->all());
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

        $this->stav(['fmRate' => ['films-0' => ['a' => 5, 'm' => 4]]])->assertOk();

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
        $this->titul('Perfect Days');

        $this->stav(['tierMap' => ['films-0' => 'S']])->assertOk();

        $this->assertSame('S', DB::table('watch_titles')->value('tier'));
        $this->assertSame('S', $this->getJson('/api/data/pribeh')->assertOk()->json('data.AL.films.0.3'));

        // A přehled z něj kreslí pásmo, ne ukázku.
        $this->assertStringStartsWith('S · ', $this->getJson('/api/data/pribeh')->assertOk()->json('data.ABARS.tier.0.0'));
    }

    /** Zrušené zařazení pásmo smaže, ne přepíše na nesmysl. */
    public function test_zrusene_zarazeni_pasmo_smaze(): void
    {
        $this->titul('Perfect Days', ['tier' => 'S']);

        $this->stav(['tierMap' => ['films-0' => '']])->assertOk();

        $this->assertNull(DB::table('watch_titles')->value('tier'));
    }

    /** Rozkoukaný díl se uloží. */
    public function test_rozkoukany_dil_se_ulozi(): void
    {
        $this->titul('Shogun', ['kind' => 'seriál', 'status' => 'probíhá', 'episodes_total' => 10]);

        $this->stav(['fmEp' => ['series-0' => 7]])->assertOk();

        $this->assertSame(7, (int) DB::table('watch_titles')->value('episodes_done'));
        $this->assertStringContainsString('S1E7', $this->getJson('/api/data/pribeh')->assertOk()->json('data.AL.series.0.1'));
    }

    /** „Viděli jsme" změní stav titulu. */
    public function test_videli_jsme_zmeni_stav(): void
    {
        $this->titul('Poor Things', ['status' => 'chceme']);

        $this->stav(['rowDone' => ['watchlist-0' => true]])->assertOk();

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
        $this->titul('Dune: Part Two');

        $this->stav([
            'xRows' => [
                'films' => [['t' => 'Dune: Part Two', 'm' => '', 'g' => 'hotovo', 'id' => 'films-0']],
            ],
            'rowDone' => ['films-0' => true, 'poznamky-0' => true],
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

    // ——— pomůcky ———

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
