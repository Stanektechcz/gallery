<?php

namespace Tests\Feature\Meny;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Obsah obrazovek sčítá přes měny v hlavní měně (CZK) — a jen s kurzem.
 *
 * Zdraví dat, týdenní přehled, běžící cesta i „dnes v archivu" dřív vybíraly
 * „nejčastější měnu" a ostatní potichu vynechávaly, nebo koruny s eury sečetly
 * pod znakem jedné z nich. Teď: s kurzem ECB součet v korunách i s datem kurzu,
 * bez kurzu žádné smíšené číslo — koruny zvlášť a zbytek výslovně stranou.
 */
class ObsahVHlavniMeneTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create(['name' => 'Adrian']);
        $maki = User::factory()->create(['name' => 'Makinka']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $maki->id => ['role' => 'editor'],
        ]);

        Sanctum::actingAs($this->adri);
    }

    // ——— zdraví dat: zůstatek ———

    /** Dva eurové účty proti jednomu korunovému nesmí vyhrát — součet je v korunách. */
    public function test_zustatek_je_v_korunach_i_s_eurovymi_ucty(): void
    {
        $this->kurzEura();
        $this->penezenka('CZK', 30000);
        $this->penezenka('EUR', 600);
        $this->penezenka('EUR', 400);

        $radek = $this->radekZdravi('Zůstatek na účtech');

        // 30 000 + 1 000 € × 25
        $this->assertSame('55 000 Kč', $this->mezery($radek['value']));
        $this->assertStringContainsString('přepočteno kurzem ECB k 24. 9. 2026', $radek['where']);
        $this->assertSame('přepočteno kurzem ECB k 24. 9. 2026', $radek['rate']);
        // Rozpis po měnách, hlavní měna první (JSON celé částky nese jako celá čísla).
        $this->assertSame(['CZK' => 30000, 'EUR' => 1000], $radek['split']);
    }

    public function test_zustatek_bez_kurzu_nemicha_a_eura_da_stranou(): void
    {
        $this->penezenka('CZK', 30000);
        $this->penezenka('EUR', 600);
        $this->penezenka('EUR', 400);

        $radek = $this->radekZdravi('Zůstatek na účtech');

        $this->assertSame('30 000 Kč', $this->mezery($radek['value']));
        $this->assertStringContainsString('1 000 € stranou', $this->mezery($radek['where']));
        $this->assertNull($radek['rate']);
    }

    // ——— zdraví dat: zbývá v rozpočtu ———

    public function test_zbyva_v_rozpoctu_pocita_i_eura_prepoctena(): void
    {
        $this->kurzEura();
        $this->rozpocet(10000);
        $this->transakce(['amount_from' => 1000]);
        $this->transakce(['amount_from' => 20, 'currency_from' => 'EUR']);

        $radek = $this->radekZdravi('Zbývá v rozpočtu tento měsíc');

        // 10 000 − 1 000 − 20 € × 25
        $this->assertSame('8 500 Kč', $this->mezery($radek['value']));
        $this->assertStringContainsString('přepočteno kurzem ECB k 24. 9. 2026', $radek['where']);
    }

    public function test_zbyva_v_rozpoctu_bez_kurzu_rekne_co_nezapocitalo(): void
    {
        $this->rozpocet(10000);
        $this->transakce(['amount_from' => 1000]);
        $this->transakce(['amount_from' => 20, 'currency_from' => 'EUR']);

        $radek = $this->radekZdravi('Zbývá v rozpočtu tento měsíc');

        $this->assertSame('9 000 Kč', $this->mezery($radek['value']));
        $this->assertStringContainsString('20 € nezapočteno', $this->mezery($radek['where']));
    }

    // ——— zdraví dat: útrata na cestě ———

    public function test_utrata_na_ceste_je_v_korunach_i_s_eury(): void
    {
        $this->kurzEura();
        $cesta = $this->cesta(['name' => 'Beskydy']);
        $this->utrata($cesta, 1200, 'CZK');
        $this->utrata($cesta, 300, 'CZK');
        $this->utrata($cesta, 50, 'EUR');

        $radek = $this->radekZdravi('Útrata na cestě Beskydy');

        $this->assertSame('2 750 Kč', $this->mezery($radek['value']));
        $this->assertStringContainsString('3 položky', $radek['where']);
        $this->assertStringContainsString('přepočteno kurzem ECB k 24. 9. 2026', $radek['where']);
    }

    // ——— týden ———

    public function test_utraceno_za_tyden_v_korunach_s_rozpisem(): void
    {
        $this->kurzEura();
        $this->transakce(['amount_from' => 1000, 'occurred_at' => $this->dnes()->toDateString()]);
        $this->transakce(['amount_from' => 2000, 'occurred_at' => $this->dnes()->toDateString()]);
        $this->transakce(['amount_from' => 50, 'currency_from' => 'EUR', 'occurred_at' => $this->dnes()->toDateString()]);

        $radek = $this->getJson('/api/data/tyden')->assertOk()->json('data.WEEK.now.stats.3');

        $this->assertSame('Utraceno', $radek[0]);
        $this->assertSame('4 250 Kč', $this->mezery($radek[1]));
        $this->assertStringContainsString('3 000 Kč + 50 €', $this->mezery($radek[2]));
        $this->assertStringContainsString('přepočteno kurzem ECB k 24. 9. 2026', $radek[2]);
    }

    public function test_utraceno_za_tyden_bez_kurzu_nemicha(): void
    {
        $this->transakce(['amount_from' => 50, 'currency_from' => 'EUR', 'occurred_at' => $this->dnes()->toDateString()]);
        $this->transakce(['amount_from' => 60, 'currency_from' => 'EUR', 'occurred_at' => $this->dnes()->toDateString()]);
        $this->transakce(['amount_from' => 3000, 'occurred_at' => $this->dnes()->toDateString()]);

        $radek = $this->getJson('/api/data/tyden')->assertOk()->json('data.WEEK.now.stats.3');

        // Koruny mají přednost, i když eurových výdajů je víc.
        $this->assertSame('3 000 Kč', $this->mezery($radek[1]));
        $this->assertStringContainsString('110 € stranou', $this->mezery($radek[2]));
    }

    /** Týden jen v eurech bez kurzu: eura se ukážou, jak jsou — nic stranou není. */
    public function test_utraceno_za_tyden_jen_v_eurech_bez_kurzu(): void
    {
        $this->transakce(['amount_from' => 50, 'currency_from' => 'EUR', 'occurred_at' => $this->dnes()->toDateString()]);

        $radek = $this->getJson('/api/data/tyden')->assertOk()->json('data.WEEK.now.stats.3');

        $this->assertSame('50 €', $this->mezery($radek[1]));
        $this->assertSame('1 výdaj', $radek[2]);
    }

    // ——— běžící cesta ———

    /** Eurová cesta: její čísla jsou v eurech, koruny se k nim nepřičítají. */
    public function test_bezici_eurova_cesta_nese_menu_a_nemicha_koruny(): void
    {
        $this->kurzEura();
        $cesta = $this->cesta([
            'name' => 'Brač', 'currency' => 'EUR', 'budget' => 800,
            'start_date' => $this->dnes()->subDays(2)->toDateString(),
            'end_date' => $this->dnes()->addDays(3)->toDateString(),
        ]);
        $this->utrata($cesta, 40, 'EUR', $this->dnes()->setTime(10, 0));
        $this->utrata($cesta, 30, 'EUR', $this->dnes()->subDay()->setTime(19, 0));
        $this->utrata($cesta, 500, 'CZK', $this->dnes()->setTime(11, 0));

        $ted = $this->getJson('/api/data/cesty')->assertOk()->json('data.NOWTRIP');

        $this->assertSame('EUR', $ted['mena']);
        $this->assertSame('€', $ted['znak']);
        $this->assertSame(800, $ted['fund']);
        $this->assertSame(70, $ted['spent']);
        $this->assertSame(40, $ted['todaySpent']);
        $this->assertEquals(['CZK' => 500], $ted['spentOther']);
        $this->assertEquals(['CZK' => 500], $ted['todayOther']);
        $this->assertEqualsCanonicalizing(['EUR', 'CZK'], array_column($ted['spendRows'], 4));
        // Celkem v korunách: 70 € × 25 + 500
        $this->assertSame('CZK', $ted['spentMain']['mena']);
        $this->assertEquals(2250, $ted['spentMain']['celkem']);
        $this->assertSame('přepočteno kurzem ECB k 24. 9. 2026', $ted['spentMain']['popisek']);
    }

    public function test_bezici_cesta_bez_kurzu_nema_korunovy_soucet(): void
    {
        $cesta = $this->cesta([
            'name' => 'Brač', 'currency' => 'EUR',
            'start_date' => $this->dnes()->subDays(2)->toDateString(),
            'end_date' => $this->dnes()->addDays(3)->toDateString(),
        ]);
        $this->utrata($cesta, 40, 'EUR', $this->dnes()->setTime(10, 0));
        $this->utrata($cesta, 500, 'CZK', $this->dnes()->setTime(11, 0));

        $ted = $this->getJson('/api/data/cesty')->assertOk()->json('data.NOWTRIP');

        $this->assertSame(40, $ted['spent']);
        $this->assertNull($ted['spentMain']['celkem']);
        $this->assertSame(['EUR'], $ted['spentMain']['chybi']);
    }

    /** Limit kategorie se srovnává jen s útratou v jeho měně. */
    public function test_rozpocet_cesty_srovnava_jen_stejnou_menu(): void
    {
        $cesta = $this->cesta(['name' => 'Portugalsko', 'currency' => 'EUR']);
        DB::table('trip_budget_limits')->insert([
            'trip_id' => $cesta, 'category' => 'food', 'amount' => 100, 'currency' => 'EUR', 'warn_percent' => 80,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->utrata($cesta, 40, 'EUR', null, 'food');
        $this->utrata($cesta, 500, 'CZK', null, 'food');

        $radek = collect($this->getJson('/api/data/cesty')->assertOk()->json('data.TRIPS.portugalsko.budget'))->keyBy(0)['Jídlo'];

        $this->assertSame('40 z 100 € · 500 Kč v jiné měně', $radek[1]);
        $this->assertSame(40, $radek[2]);
    }

    /** „Utraceno" ve víc měnách se s kurzem sečte v korunách a řekne, jakým kurzem. */
    public function test_utraceno_na_ceste_ve_vic_menach_se_prepocte(): void
    {
        $this->kurzEura();
        $cesta = $this->cesta(['name' => 'Portugalsko', 'currency' => 'EUR']);
        $this->utrata($cesta, 70, 'EUR');
        $this->utrata($cesta, 500, 'CZK');

        $stats = collect($this->getJson('/api/data/cesty')->assertOk()->json('data.TRIPS.portugalsko.stats'))->keyBy(0);

        $this->assertSame('2 250 Kč', $stats['Utraceno'][1]);
        $this->assertSame('přepočteno kurzem ECB k 24. 9. 2026 · 70 € + 500 Kč', $stats['Utraceno'][2]);
    }

    public function test_utraceno_na_ceste_ve_vic_menach_bez_kurzu_se_nesecte(): void
    {
        $cesta = $this->cesta(['name' => 'Portugalsko', 'currency' => 'EUR']);
        $this->utrata($cesta, 70, 'EUR');
        $this->utrata($cesta, 500, 'CZK');

        $stats = collect($this->getJson('/api/data/cesty')->assertOk()->json('data.TRIPS.portugalsko.stats'))->keyBy(0);

        $this->assertSame('70 € + 500 Kč', $stats['Utraceno'][1]);
    }

    /** Rozpočet další cesty se odvozuje jen ze stejné měny — obrazovka ji musí znát. */
    public function test_cesta_nese_svou_menu(): void
    {
        $this->cesta(['name' => 'Portugalsko', 'currency' => 'EUR']);
        $this->cesta(['name' => 'Šumava', 'currency' => 'czk']);

        $cesty = $this->getJson('/api/data/cesty')->assertOk()->json('data.TRIPS');

        $this->assertSame('EUR', $cesty['portugalsko']['mena']);
        $this->assertSame('CZK', $cesty['sumava']['mena']);
    }

    // ——— dnes v archivu ———

    /** Největší výdaj dne se vybírá podle hodnoty v korunách, ne podle holého čísla. */
    public function test_nejvetsi_vydaj_dne_srovnava_prepoctene_castky(): void
    {
        $this->kurzEura();
        $loni = $this->dnes()->subYear()->toDateString();
        $this->transakce(['amount_from' => 1000, 'occurred_at' => $loni, 'description' => 'Nákup v korunách']);
        $this->transakce(['amount_from' => 100, 'currency_from' => 'EUR', 'occurred_at' => $loni, 'description' => 'Večeře v eurech']);

        $vydaj = collect($this->getJson('/api/data/dnes')->assertOk()->json('data.DNES.archiv'))->firstWhere(0, 'Výdaj');

        // 100 € je 2 500 Kč, víc než 1 000 Kč.
        $this->assertSame('Večeře v eurech', $vydaj[2]);
        $this->assertStringContainsString('100 €', $this->mezery($vydaj[3]));
    }

    public function test_nejvetsi_vydaj_dne_bez_kurzu_bere_hlavni_menu(): void
    {
        $loni = $this->dnes()->subYear()->toDateString();
        $this->transakce(['amount_from' => 1000, 'occurred_at' => $loni, 'description' => 'Nákup v korunách']);
        $this->transakce(['amount_from' => 5000, 'currency_from' => 'EUR', 'occurred_at' => $loni, 'description' => 'Hotel v eurech']);

        $vydaj = collect($this->getJson('/api/data/dnes')->assertOk()->json('data.DNES.archiv'))->firstWhere(0, 'Výdaj');

        // Bez kurzu se eura s korunami srovnat nedají — ukáže se výdaj v hlavní měně.
        $this->assertSame('Nákup v korunách', $vydaj[2]);
    }

    // ——— pomůcky ———

    /** Kurz EUR → CZK podvržený na úrovni HTTP, ať ho dostanou všechny cesty ke kurzu stejně. */
    private function kurzEura(): void
    {
        Http::fake([
            'api.frankfurter.dev/*' => Http::response(['date' => '2026-09-24', 'base' => 'EUR', 'quote' => 'CZK', 'rate' => 25.0]),
        ]);
    }

    /** @return array<string, mixed> */
    private function radekZdravi(string $popisek): array
    {
        $radek = collect($this->getJson('/api/data/system')->assertOk()->json('data.DATA_HEALTH'))->firstWhere('label', $popisek);
        $this->assertNotNull($radek, 'Řádek „'.$popisek.'" chybí.');

        return $radek;
    }

    /** Pevné mezery (tisíce v některých obrazovkách) jako obyčejné — test hlídá čísla, ne typografii. */
    private function mezery(string $text): string
    {
        return str_replace("\u{00A0}", ' ', $text);
    }

    private function penezenka(string $mena, float $castka): void
    {
        DB::table('wallets')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'name' => 'Účet '.$mena.' '.$castka,
            'kind' => 'bank', 'currency' => $mena, 'opening_balance' => $castka, 'is_active' => true, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rozpocet(float $limit): void
    {
        $rozpocet = DB::table('budgets')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id,
            'name' => 'Rozpočet', 'currency' => 'CZK', 'starts_on' => $this->dnes()->startOfMonth()->toDateString(),
            'is_shared' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $kategorie = DB::table('finance_categories')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'name' => 'Potraviny',
            'kind' => 'expense', 'is_favourite' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('budget_category_limits')->insert([
            'budget_id' => $rozpocet, 'finance_category_id' => $kategorie, 'amount' => $limit, 'priority' => 50,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function transakce(array $navic = []): void
    {
        DB::table('transactions')->insert(array_merge([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id,
            'type' => 'expense', 'occurred_at' => $this->dnes()->toDateString(), 'amount_from' => 100,
            'currency_from' => 'CZK', 'description' => 'Výdaj', 'created_at' => now(), 'updated_at' => now(),
        ], $navic));
    }

    private function cesta(array $navic = []): int
    {
        return DB::table('trips')->insertGetId(array_merge([
            'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id, 'name' => 'Cesta', 'description' => '',
            'start_date' => $this->dnes()->addWeek()->toDateString(), 'end_date' => $this->dnes()->addWeeks(2)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ], $navic));
    }

    private function utrata(int $cesta, float $castka, string $mena, mixed $kdy = null, string $kategorie = 'food'): void
    {
        DB::table('trip_expenses')->insert([
            'trip_id' => $cesta, 'created_by' => $this->adri->id, 'title' => 'Útrata '.$castka.' '.$mena,
            'category' => $kategorie, 'amount' => $castka, 'currency' => $mena, 'state' => 'actual',
            'occurred_at' => $kdy ?? now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
