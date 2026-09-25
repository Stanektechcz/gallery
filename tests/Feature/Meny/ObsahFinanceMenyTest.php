<?php

namespace Tests\Feature\Meny;

use App\Models\Budget;
use App\Models\FinanceCategory;
use App\Models\FinanceRecurring;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Integrations\FreeTravelDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Finance obrazovek v hlavní měně (CZK).
 *
 * Dvojice rozhodla: „Hlavní měna je CZK, další měny jsou EUR, USD". Obrazovky
 * přitom sčítaly zůstatky účtů, „kdo co zaplatil" i čerpání rozpočtu přes měny,
 * jako by koruna a euro byly totéž — a znak měny celé aplikace se řídil tím,
 * v jaké měně je zrovna viditelný rozpočet. Eurový rozpočet na Německo tak
 * z každé částky v aplikaci udělal eura.
 */
class ObsahFinanceMenyTest extends TestCase
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

    // ——— účty ———

    public function test_ucty_nesou_menu_a_korunovy_ekvivalent(): void
    {
        $this->kurzEura();
        $this->ucet('Běžný', 'CZK', 1000);
        $this->ucet('Eurový', 'EUR', 100);

        $ucty = collect($this->finance()['FIN']['accounts'])->keyBy(0);

        $this->assertSame(1000, $ucty['Běžný'][2], 'Zůstatek ve vlastní měně účtu zůstává na svém místě.');
        $this->assertSame('CZK', $ucty['Běžný'][9]);
        $this->assertSame(1000, $ucty['Běžný'][10]);
        $this->assertSame('EUR', $ucty['Eurový'][9]);
        $this->assertSame(2500, $ucty['Eurový'][10], '100 € po 25 Kč.');
        $this->assertSame('100 €', $ucty['Eurový'][11]);
        // Podíl z korunových ekvivalentů: 1 000 z 3 500 a 2 500 z 3 500 — ne 1 000 ze 1 100.
        $this->assertSame(29, $ucty['Běžný'][7]);
        $this->assertSame(71, $ucty['Eurový'][7]);
    }

    public function test_souhrn_uctu_je_v_korunach_s_datem_kurzu(): void
    {
        $this->kurzEura();
        $this->ucet('Běžný', 'CZK', 1000);
        $this->ucet('Eurový', 'EUR', 100);
        $this->predpis('Nájem', 500, 'CZK');
        $this->predpis('Parkování v Řezně', 20, 'EUR');

        $souhrn = $this->finance()['FIN']['souhrn'];

        $this->assertSame(3500, $souhrn['celkem']);
        $this->assertSame(3500, $souhrn['bezne']);
        $this->assertSame(0, $souhrn['odlozeno']);
        $this->assertSame(1000, $souhrn['ceka'], '500 Kč a 20 € po 25 Kč.');
        $this->assertSame('CZK', $souhrn['mena']);
        $this->assertSame('Kč', $souhrn['znak']);
        $this->assertTrue($souhrn['uplne']);
        $this->assertSame('2026-09-24', $souhrn['kurzKeDni']);
        $this->assertSame('přepočteno kurzem ECB k 24. 9. 2026', $souhrn['popisek']);
        $this->assertEquals(['CZK' => 1000, 'EUR' => 100], $souhrn['poMenach']['celkem']);
        $this->assertSame('3 500 Kč', $souhrn['texty']['celkem']);
    }

    public function test_bez_kurzu_souhrn_neukaze_smisene_cislo(): void
    {
        $this->ucet('Běžný', 'CZK', 1000);
        $this->ucet('Eurový', 'EUR', 100);

        $data = $this->finance();
        $souhrn = $data['FIN']['souhrn'];

        $this->assertNull($souhrn['celkem'], '1 100 „něčeho" je nesmysl, ne součet.');
        $this->assertFalse($souhrn['uplne']);
        $this->assertSame(['EUR'], $souhrn['chybi']);
        $this->assertNull($souhrn['popisek']);
        $this->assertEquals(['CZK' => 1000, 'EUR' => 100], $souhrn['poMenach']['celkem']);
        $this->assertSame('1 000 Kč · 100 €', $souhrn['texty']['celkem']);

        $ucty = collect($data['FIN']['accounts'])->keyBy(0);
        $this->assertNull($ucty['Eurový'][10], 'Bez kurzu korunový ekvivalent neznáme — nula by lhala.');
        $this->assertSame(1000, $ucty['Běžný'][10]);
    }

    // ——— kdo co zaplatil ———

    public function test_kdo_co_zaplatil_nescita_eura_s_korunami(): void
    {
        $this->kurzEura();
        $this->rozpocet('CZK');
        $potraviny = $this->kategorie('Potraviny');
        $this->vydaj($potraviny, 250, 'CZK');
        $this->vydaj($potraviny, 20, 'EUR');

        $bud = $this->finance()['BUD'];

        // Dluh mezi dvojicí se počítá z `paid` — ten smí nést jen koruny.
        $this->assertSame([['Adrian', 'Potraviny', 250]], $bud['paid'], 'Ne 270 smíchaných jednotek.');
        $this->assertSame([['Adrian', 'Potraviny', 20]], $bud['paidPoMenach']['EUR']);
        $this->assertSame([['Adrian', 'Potraviny', 250]], $bud['paidPoMenach']['CZK']);
        // Korunový ekvivalent jen jako označená informace navíc.
        $this->assertSame(750, $bud['paidPrepocet']['osoby']['Adrian']);
        $this->assertSame('přepočteno kurzem ECB k 24. 9. 2026', $bud['paidPrepocet']['popisek']);
    }

    // ——— znak měny ———

    public function test_mena_je_koruna_i_kdyz_je_videt_eurovy_rozpocet(): void
    {
        $this->rozpocet('EUR');

        $data = $this->finance();

        $this->assertSame('Kč', $data['MENA'], 'Eurový rozpočet nesmí z každé částky v aplikaci udělat eura.');
        $this->assertSame('€', $data['BUD']['mena'], 'Vlastní čísla rozpočtu nesou jeho znak.');
        $this->assertSame(['CZK', 'EUR', 'USD'], $data['MENY']);
    }

    public function test_z_bezicich_rozpoctu_ma_prednost_ten_v_hlavni_mene(): void
    {
        $this->rozpocet('CZK', ['starts_on' => $this->dnes()->startOfMonth()->subMonths(2)->toDateString(), 'name' => 'Domácnost']);
        $this->rozpocet('EUR', ['starts_on' => $this->dnes()->startOfMonth()->toDateString(), 'name' => 'Německo']);

        $this->assertSame('Kč', $this->finance()['BUD']['mena']);
    }

    // ——— čerpání rozpočtu ———

    public function test_eurova_utrata_v_korunovem_rozpoctu_se_prepocte_a_oznaci(): void
    {
        $this->kurzEura();
        $rozpocet = $this->rozpocet('CZK');
        $potraviny = $this->kategorie('Potraviny');
        $this->limit($rozpocet, $potraviny, 6000);
        $this->vydaj($potraviny, 1000, 'CZK');
        $this->vydaj($potraviny, 100, 'EUR');

        $bud = $this->finance()['BUD'];
        $radek = collect($bud['cats'])->firstWhere(0, 'Potraviny');

        $this->assertSame(3500, $radek[2], '1 000 Kč a 100 € po 25 Kč.');
        $this->assertEquals(['EUR' => 100], $radek[7]['mimoMenu']);
        $this->assertStringContainsString('100 €', $radek[7]['text']);
        $this->assertStringContainsString('přepočteno', $radek[7]['text']);
        $this->assertEquals(['EUR' => 100], $bud['mimoMenu']['poMenach']);
        $this->assertSame(2500, $bud['mimoMenu']['prepocteno']);
        $this->assertSame('přepočteno kurzem ECB k 24. 9. 2026', $bud['mimoMenu']['popisek']);
    }

    public function test_bez_kurzu_se_eurova_utrata_hlasi_jako_nezapoctena(): void
    {
        $rozpocet = $this->rozpocet('CZK');
        $potraviny = $this->kategorie('Potraviny');
        $this->limit($rozpocet, $potraviny, 6000);
        $this->vydaj($potraviny, 1000, 'CZK');
        $this->vydaj($potraviny, 100, 'EUR');

        $bud = $this->finance()['BUD'];
        $radek = collect($bud['cats'])->firstWhere(0, 'Potraviny');

        $this->assertSame(1000, $radek[2]);
        $this->assertEquals(['EUR' => 100], $radek[7]['nezapocteno']);
        $this->assertStringContainsString('+100 € nezapočteno', $radek[7]['text']);
        $this->assertStringContainsString('+100 € nezapočteno', $bud['mimoMenu']['text']);
    }

    // ——— transakce, nadcházející, hledání ———

    public function test_transakce_nesou_menu_a_castku_v_hlavni_mene(): void
    {
        $this->kurzEura();
        $this->rozpocet('CZK');
        $potraviny = $this->kategorie('Potraviny');
        $this->vydaj($potraviny, 12.40, 'EUR', 'Lidl Regensburg');
        $this->vydaj($potraviny, 640, 'CZK', 'Albert');
        $this->predpis('Parkování v Řezně', 20, 'EUR');

        $data = $this->finance();
        $tx = collect($data['TX'])->keyBy(2);

        $this->assertSame('EUR', $tx['Lidl Regensburg'][7]['mena']);
        $this->assertSame('€', $tx['Lidl Regensburg'][7]['znak']);
        $this->assertSame(-310, $tx['Lidl Regensburg'][7]['hl'], '12,40 € po 25 Kč, výdaj záporně.');
        $this->assertSame('CZK', $tx['Albert'][7]['mena']);
        $this->assertSame(-640, $tx['Albert'][7]['hl']);

        $this->assertSame('EUR', collect($data['FIN']['upcoming'])->firstWhere(1, 'Parkování v Řezně')[8]);
        $this->assertSame('EUR', collect($data['RULEXP'])->firstWhere(1, 'Lidl Regensburg')[4]);
    }

    public function test_bez_kurzu_transakce_nema_castku_v_hlavni_mene(): void
    {
        $this->rozpocet('CZK');
        $this->vydaj($this->kategorie('Potraviny'), 12.40, 'EUR', 'Lidl Regensburg');

        $meta = $this->finance()['TX'][0][7];

        $this->assertSame('EUR', $meta['mena']);
        $this->assertArrayHasKey('hl', $meta);
        $this->assertNull($meta['hl']);
    }

    // ——— pomocné ———

    /** @return array<string, mixed> */
    private function finance(): array
    {
        return $this->getJson('/api/data/finance')->assertOk()->json('data');
    }

    /** Kurz eura, jak ho vrací Frankfurter (ECB): 25 Kč k 24. 9. 2026. */
    private function kurzEura(): void
    {
        $this->mock(FreeTravelDataService::class, function ($mock) {
            $mock->shouldReceive('rate')
                ->with('EUR', 'CZK', null, Mockery::on(fn ($cekat) => is_int($cekat) && $cekat <= 3))
                ->andReturn(['date' => '2026-09-24', 'base' => 'EUR', 'quote' => 'CZK', 'rate' => 25.0]);
        });
    }

    private function ucet(string $nazev, string $mena, float $zustatek): Wallet
    {
        return Wallet::create([
            'gallery_space_id' => $this->prostor->id, 'name' => $nazev,
            'kind' => 'bank', 'currency' => $mena, 'opening_balance' => $zustatek, 'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $navic */
    private function rozpocet(string $mena, array $navic = []): Budget
    {
        return Budget::create($navic + [
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => null,
            'is_shared' => true,
            'name' => 'Rozpočet '.$mena,
            'currency' => $mena,
            'starts_on' => $this->dnes()->startOfMonth()->toDateString(),
            'monthly_income' => 40000,
            'created_by' => $this->adri->id,
        ]);
    }

    private function kategorie(string $nazev): FinanceCategory
    {
        return FinanceCategory::firstOrCreate(
            ['gallery_space_id' => $this->prostor->id, 'name' => $nazev, 'kind' => 'expense'],
            ['icon' => 'shopping-cart', 'is_active' => true],
        );
    }

    private function limit(Budget $rozpocet, FinanceCategory $kategorie, float $castka): void
    {
        DB::table('budget_category_limits')->insert([
            'budget_id' => $rozpocet->id, 'finance_category_id' => $kategorie->id,
            'amount' => $castka, 'baseline_amount' => $castka, 'priority' => 50,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function vydaj(FinanceCategory $kategorie, float $castka, string $mena, string $popis = 'Nákup'): Transaction
    {
        return Transaction::create([
            'gallery_space_id' => $this->prostor->id, 'type' => 'expense',
            'category_id' => $kategorie->id, 'occurred_at' => $this->dnes()->toDateString(),
            'amount_from' => $castka, 'currency_from' => $mena, 'description' => $popis,
            'state' => 'approved', 'created_by' => $this->adri->id,
        ]);
    }

    /** Pravidelná platba s termínem posledního dne tohoto měsíce — „čeká do konce měsíce". */
    private function predpis(string $nazev, float $castka, string $mena): void
    {
        FinanceRecurring::create([
            'gallery_space_id' => $this->prostor->id, 'name' => $nazev, 'type' => 'expense',
            'amount' => $castka, 'currency' => $mena, 'day_of_month' => $this->dnes()->daysInMonth,
            'starts_on' => $this->dnes()->startOfMonth()->toDateString(), 'is_active' => true,
            'created_by' => $this->adri->id,
        ]);
    }
}
