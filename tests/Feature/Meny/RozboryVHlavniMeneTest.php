<?php

namespace Tests\Feature\Meny;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Integrations\FreeTravelDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Rozbory financí v hlavní měně (CZK).
 *
 * Předpověď, denní průměr, obálka, cena cesty i „co ta útrata znamenala" sčítaly
 * koruny s eury jako jednu měnu: 200 € na účtu přidalo předpovědi dvě stě korun
 * a směna korun na eura zůstatek snížila, přestože peníze nikam neodešly.
 * Teď se všechno sčítá po měnách a přepočte kurzem ECB — a bez kurzu se ostatní
 * měny vynechají s poznámkou, místo aby se tiše přičetly.
 */
class RozboryVHlavniMeneTest extends TestCase
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

    public function test_predpoved_prepocte_eurovou_penezenku_kurzem(): void
    {
        $this->kurzEura(25.0);
        $this->penezenka('CZK', 10000);
        $this->penezenka('EUR', 200);
        $this->pravidelna('Nájem', 1000, 'CZK');

        $p60 = $this->rozbory('P60');

        // 10 000 Kč + 200 € × 25 — ne 10 200 „korun".
        $this->assertSame(15000, $p60['start']);
        $this->assertSame('CZK', $p60['mena']);
        $this->assertTrue($p60['prepocteno']);
        $this->assertSame('přepočteno kurzem ECB k 24. 9. 2026', $p60['popisek']);
        $this->assertSame([], $p60['vynechano']);
        $this->assertNull($p60['poznamka']);
    }

    public function test_smena_korun_na_eura_zustatek_nesnizi(): void
    {
        $this->kurzEura(25.0);
        $koruny = $this->penezenka('CZK', 10000);
        $eura = $this->penezenka('EUR', 0);
        $this->pravidelna('Nájem', 1000, 'CZK');

        // 2 500 Kč na 100 €, banka si vzala 2 € navíc — z eur, ne z korun.
        $this->transakce([
            'type' => 'exchange', 'wallet_from_id' => $koruny, 'wallet_to_id' => $eura,
            'amount_from' => 2500, 'currency_from' => 'CZK', 'amount_to' => 100, 'currency_to' => 'EUR',
            'fee_amount' => 2, 'fee_currency' => 'EUR', 'fee_included' => false,
        ]);

        // 7 500 Kč + 98 € × 25 = 9 950. Dřív 10 000 − 2 500 − 2 + 100 = 7 598.
        $this->assertSame(9950, $this->rozbory('P60')['start']);
    }

    public function test_zahrnuty_poplatek_se_neodecita_dvakrat(): void
    {
        $this->bezKurzu();
        $ucet = $this->penezenka('CZK', 10000);
        $this->pravidelna('Nájem', 1000, 'CZK');

        // Poplatek 50 Kč je už v tisícovce, která z účtu odešla.
        $this->transakce([
            'wallet_from_id' => $ucet, 'amount_from' => 1000, 'currency_from' => 'CZK',
            'fee_amount' => 50, 'fee_currency' => 'CZK', 'fee_included' => true,
        ]);

        $this->assertSame(9000, $this->rozbory('P60')['start']);
    }

    public function test_denni_prumer_a_pravidelne_platby_nemichaji_meny(): void
    {
        $this->kurzEura(25.0);
        $this->penezenka('CZK', 10000);
        $this->pravidelna('Mzda', 30000, 'CZK', 'income');
        $this->pravidelna('Předplatné', 10, 'EUR');

        $this->transakce(['amount_from' => 9000, 'currency_from' => 'CZK', 'occurred_at' => $this->dnes()->subDays(10)->toDateTimeString()]);
        $this->transakce(['amount_from' => 90, 'currency_from' => 'EUR', 'occurred_at' => $this->dnes()->subDays(20)->toDateTimeString()]);

        $p60 = $this->rozbory('P60');

        // (9 000 + 90 × 25 − 10 × 25 × 3) / 90 = 116,7. Dřív (9 090 − 30) / 90 = 100,7.
        $this->assertSame(117, $p60['daily']);

        $predplatne = collect($p60['events'])->firstWhere('label', 'Předplatné');
        $this->assertSame(-250, $predplatne['amt']);
        $this->assertSame('EUR', $predplatne['mena']);
        $this->assertSame(-10, $predplatne['puvodne']);

        $mzda = collect($p60['events'])->firstWhere('label', 'Mzda');
        $this->assertSame(30000, $mzda['amt']);
        $this->assertSame('CZK', $mzda['mena']);
    }

    public function test_bez_kurzu_je_predpoved_jen_v_korunach_s_poznamkou(): void
    {
        $this->bezKurzu();
        $this->penezenka('CZK', 10000);
        $this->penezenka('EUR', 200);
        $this->pravidelna('Nájem', 1000, 'CZK');
        $this->pravidelna('Předplatné', 10, 'EUR');
        $this->transakce(['amount_from' => 900, 'currency_from' => 'EUR', 'occurred_at' => $this->dnes()->subDays(5)->toDateTimeString()]);

        $p60 = $this->rozbory('P60');

        $this->assertSame(10000, $p60['start']);
        $this->assertSame(0, $p60['daily']);
        $this->assertSame(['Nájem'], array_values(array_unique(array_column($p60['events'], 'label'))));
        $this->assertFalse($p60['prepocteno']);
        $this->assertNull($p60['popisek']);
        $this->assertSame(['EUR'], $p60['vynechano']);
        $this->assertStringContainsString('EUR', (string) $p60['poznamka']);
    }

    public function test_obalka_nebere_koncept_ani_prijem(): void
    {
        $this->bezKurzu();
        $kategorie = $this->kategorie('Osobní obálka');
        $adrian = $this->partner($this->adri);

        $this->transakce(['category_id' => $kategorie, 'amount_from' => 500, 'payer_partner_id' => $adrian]);
        $this->transakce(['category_id' => $kategorie, 'amount_from' => 1000, 'payer_partner_id' => $adrian, 'state' => 'draft']);
        $this->transakce(['category_id' => $kategorie, 'amount_from' => 300, 'amount_to' => 300, 'currency_to' => 'CZK', 'type' => 'income', 'payer_partner_id' => $adrian]);

        $tento = collect($this->rozbory('ENV')['months'])->last();

        $this->assertSame(500, $tento['a']);
    }

    public function test_obalka_prepocte_eura_do_meny_limitu(): void
    {
        $this->kurzEura(25.0);
        $kategorie = $this->kategorie('Osobní obálka');
        $adrian = $this->partner($this->adri);
        $this->limit($this->rozpocet('CZK'), $kategorie, 2000);

        $this->transakce(['category_id' => $kategorie, 'amount_from' => 500, 'payer_partner_id' => $adrian]);
        $this->transakce(['category_id' => $kategorie, 'amount_from' => 20, 'currency_from' => 'EUR', 'payer_partner_id' => $adrian]);

        $obalka = $this->rozbory('ENV');

        $this->assertSame(1000, collect($obalka['months'])->last()['a']);
        $this->assertSame('CZK', $obalka['mena']);
        $this->assertTrue($obalka['prepocteno']);
    }

    public function test_kc_se_neukaze_u_eurove_platby(): void
    {
        $this->kurzEura(25.0);
        $this->transakce();
        $this->pravidelna('Streaming', 10, 'EUR', 'expense', $this->dnes()->subMonthsNoOverflow(6)->toDateString());

        $radek = $this->rozbory('HORIZON')[0];

        $this->assertSame('Streaming 10 € / měs.', $radek['what']);
        $this->assertStringContainsString('60 €', $radek['note']);
        $this->assertStringNotContainsString('Kč', $radek['note']);
        // Deset let se počítá v korunách, jako ostatní řádky.
        $this->assertSame(250, $radek['monthly']);
        $this->assertSame('CZK', $radek['mena']);
        $this->assertSame('EUR', $radek['puvodniMena']);
    }

    public function test_co_to_znamenalo_pocita_podil_v_korunach(): void
    {
        $this->kurzEura(25.0);
        $this->transakce(['amount_from' => 7500, 'category_id' => $this->kategorie('Potraviny')]);
        $this->transakce(['amount_from' => 100, 'currency_from' => 'EUR', 'category_id' => $this->kategorie('Kultura')]);

        $z = collect($this->rozbory('COSTMEAN'))->keyBy(0);

        $this->assertSame(2500, $z['Kultura'][1]);
        $this->assertSame('75 % letošních výdajů', $z['Potraviny'][2]);
        $this->assertSame('CZK', $z['Kultura'][5]);
    }

    public function test_odhad_prepocte_eura_do_meny_rozpoctu(): void
    {
        $this->kurzEura(25.0);
        $jidlo = $this->kategorie('Potraviny');
        $this->limit($this->rozpocet('CZK'), $jidlo, 6000);
        $this->transakce(['amount_from' => 1000, 'category_id' => $jidlo]);
        $this->transakce(['amount_from' => 100, 'currency_from' => 'EUR', 'category_id' => $jidlo]);

        $e = $this->rozbory('EST')[0];

        $this->assertSame(3500, $e['real']);
        $this->assertSame('CZK', $e['mena']);
    }

    public function test_necekany_vydaj_se_meri_v_korunach(): void
    {
        $this->kurzEura(25.0);

        // Obvyklá útrata je 200 Kč, hranice tedy 2 000 Kč.
        foreach (range(1, 10) as $i) {
            $this->transakce(['amount_from' => 200]);
        }

        // 100 € je 2 500 Kč — nečekané. Dřív „100" pod hranicí zmizelo.
        $this->transakce(['amount_from' => 100, 'currency_from' => 'EUR', 'description' => 'Pokuta v Rakousku']);

        $n = $this->rozbory('SURPRISE');

        $this->assertSame(['Pokuta v Rakousku'], array_column($n, 'what'));
        $this->assertSame(2500, $n[0]['cost']);
        $this->assertSame('EUR', $n[0]['puvodniMena']);
        $this->assertSame(100, $n[0]['puvodne']);
    }

    public function test_cena_cesty_secte_eura_s_korunami(): void
    {
        $this->kurzEura(25.0);
        $cesta = $this->cesta('Vídeň', $this->dnes()->subDays(5), $this->dnes()->subDays(4));
        $this->utrata($cesta, ['category' => 'food', 'amount' => 100, 'currency' => 'EUR']);
        $this->utrata($cesta, ['category' => 'transport', 'amount' => 1000, 'currency' => 'CZK']);

        $c = $this->rozbory('TRIPCOST')['viden'];

        $this->assertSame(3500, $c['total']);
        $this->assertSame('CZK', $c['mena']);
        $this->assertSame('Jídlo venku', $c['items'][0]['name']);
        $this->assertSame(2500, $c['items'][0]['amount']);
    }

    // ——— pomůcky ———

    /** @return mixed */
    private function rozbory(string $klic)
    {
        return $this->getJson('/api/data/rozbory')->assertOk()->json('data.'.$klic);
    }

    private function kurzEura(float $kurz): void
    {
        $this->mock(FreeTravelDataService::class, function ($mock) use ($kurz) {
            $mock->shouldReceive('rate')
                ->with('EUR', 'CZK', null, Mockery::any())
                ->andReturn(['date' => '2026-09-24', 'base' => 'EUR', 'quote' => 'CZK', 'rate' => $kurz]);
        });
    }

    private function bezKurzu(): void
    {
        $this->mock(FreeTravelDataService::class, function ($mock) {
            $mock->shouldReceive('rate')->andThrow(new \RuntimeException('mimo provoz'));
        });
    }

    private function penezenka(string $mena, float $pocatek): int
    {
        return DB::table('wallets')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'name' => 'Účet '.$mena, 'kind' => 'bank',
            'currency' => $mena, 'opening_balance' => $pocatek, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function pravidelna(string $nazev, float $castka, string $mena, string $typ = 'expense', ?string $od = null): void
    {
        DB::table('finance_recurring')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'name' => $nazev, 'type' => $typ,
            'amount' => $castka, 'currency' => $mena, 'day_of_month' => (int) $this->dnes()->addDays(5)->day,
            'starts_on' => $od ?? $this->dnes()->toDateString(), 'is_active' => true, 'created_by' => $this->adri->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $navic */
    private function transakce(array $navic = []): void
    {
        DB::table('transactions')->insert(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'type' => 'expense',
            'state' => 'approved',
            'occurred_at' => $this->dnes()->toDateTimeString(),
            'amount_from' => 100,
            'currency_from' => 'CZK',
            'description' => 'Výdaj',
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
    }

    private function kategorie(string $nazev): int
    {
        return DB::table('finance_categories')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'name' => $nazev, 'kind' => 'expense',
            'is_favourite' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function partner(User $kdo): int
    {
        return DB::table('partners')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'kind' => 'person',
            'name' => $kdo->name, 'user_id' => $kdo->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rozpocet(string $mena): int
    {
        return DB::table('budgets')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id,
            'name' => 'Rozpočet', 'currency' => $mena, 'starts_on' => $this->dnes()->startOfMonth()->toDateString(),
            'is_shared' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function limit(int $rozpocet, int $kategorie, float $castka): void
    {
        DB::table('budget_category_limits')->insert([
            'budget_id' => $rozpocet, 'finance_category_id' => $kategorie, 'amount' => $castka,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cesta(string $nazev, $od, $do): int
    {
        return DB::table('trips')->insertGetId([
            'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id, 'name' => $nazev,
            'start_date' => $od->toDateString(), 'end_date' => $do->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $navic */
    private function utrata(int $cesta, array $navic = []): void
    {
        DB::table('trip_expenses')->insert(array_merge([
            'trip_id' => $cesta, 'created_by' => $this->adri->id, 'title' => 'Útrata', 'category' => 'other',
            'amount' => 100, 'currency' => 'CZK', 'state' => 'actual', 'occurred_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $navic));
    }
}
