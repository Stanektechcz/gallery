<?php

namespace Tests\Feature\Galerie;

use App\Models\Budget;
use App\Models\FinanceCategory;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Finance ve tvaru, ve kterém je kreslí prototyp.
 *
 * Obrazovky Přehled, Transakce, Rozpočty a Účty braly data z `galerie-data.js`,
 * takže dvojice viděla cizí nákupy místo svých — a Makinčin rozpočet na Německo,
 * který v aplikaci je, se v nich neobjevil vůbec.
 */
class ObsahFinanceTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create();
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    /** Bez financí se nic neposílá — klient si nechá ukázková data. */
    public function test_bez_financi_se_skupina_neposila(): void
    {
        $this->assertSame([], $this->getJson('/api/data/finance')->assertOk()->json('data'));
    }

    /**
     * Kategorie k zařazení jsou ty, které dvojice opravdu má.
     *
     * Prototyp je měl napsané v souboru s ukázkovými daty, takže nabízel cizí
     * jména — a zapsané zařazení pak mířilo na kategorii, kterou dvojice
     * v účetnictví nemá.
     */
    public function test_kategorie_k_zarazeni_jsou_skutecne(): void
    {
        $this->regensburg();

        FinanceCategory::create([
            'gallery_space_id' => $this->prostor->id, 'name' => 'Kavárny',
            'kind' => 'expense', 'is_active' => true, 'sort_order' => 90,
        ]);
        FinanceCategory::create([
            'gallery_space_id' => $this->prostor->id, 'name' => 'Mzda',
            'kind' => 'income', 'is_active' => true, 'sort_order' => 91,
        ]);
        FinanceCategory::create([
            'gallery_space_id' => $this->prostor->id, 'name' => 'Schovaná',
            'kind' => 'expense', 'is_active' => false, 'sort_order' => 92,
        ]);

        $kategorie = $this->getJson('/api/data/finance')->assertOk()->json('data.TXCATS');

        $this->assertContains('Kavárny', $kategorie);
        // Příjem ani schovaná kategorie k zařazení nákupu nepatří.
        $this->assertNotContains('Mzda', $kategorie);
        $this->assertNotContains('Schovaná', $kategorie);
    }

    /**
     * Limity jsou za období, obrazovka je měsíční.
     *
     * Rozpočet na Německo má u ubytování 1 680 € — šest měsíců po 280. Ukázat to
     * jako měsíční limit by znamenalo tvrdit, že na nájem má šestkrát víc, než má.
     */
    public function test_limit_obdobi_se_prepocita_na_mesic(): void
    {
        $this->regensburg();

        $kategorie = collect($this->getJson('/api/data/finance')->assertOk()->json('data.BUD.cats'));

        $this->assertSame(280, $kategorie->firstWhere(0, 'Ubytování')[1]);
    }

    /**
     * Nedotknutelné je to s **nejnižší** prioritou.
     *
     * Aplikace řadí priority vzestupně: co má nižší číslo, dostane peníze první
     * a shazuje se poslední. Obráceně by obrazovka označila za jisté zrovna to,
     * co odpadne první.
     */
    public function test_nedotknutelne_je_to_co_se_neshazuje(): void
    {
        $this->regensburg();

        $kategorie = collect($this->getJson('/api/data/finance')->assertOk()->json('data.BUD.cats'));

        $this->assertSame('nedotknutelné', $kategorie->firstWhere(0, 'Ubytování')[5]);
        $this->assertNull($kategorie->firstWhere(0, 'Volný čas a výlety')[5]);
    }

    /** Rozpočet v eurech nesmí mít u částek koruny. */
    public function test_castky_nesou_menu_rozpoctu(): void
    {
        $this->regensburg();

        $kategorie = collect($this->getJson('/api/data/finance')->assertOk()->json('data.BUD.cats'));

        $this->assertStringContainsString('€', $kategorie->firstWhere(0, 'Ubytování')[4]);
        $this->assertStringNotContainsString('Kč', json_encode($kategorie));
    }

    /**
     * Jednorázově složené prostředky se rozpočítají na měsíce.
     *
     * Nula by v hlavičce znamenala „nemáme z čeho žít", což není totéž jako
     * „příjem nechodí měsíčně".
     */
    public function test_slozene_prostredky_se_prepoctou_na_mesic(): void
    {
        $this->regensburg();

        $data = $this->getJson('/api/data/finance')->assertOk()->json('data');

        // 2 891,37 € na šest měsíců
        $this->assertSame(482, $data['BUD']['income']);
        $this->assertSame(482, $data['INCOMES'], 'Dvě různá čísla by si na dvou obrazovkách protiřečila.');
    }

    /**
     * Prázdná kniha znamená prázdný seznam, ne ukázkové nákupy.
     *
     * Rozpočet skutečný a pod ním dvacet vymyšlených nákupů je horší než nic:
     * „zatím nic" je pravda, cizí nákupy jsou lež.
     */
    public function test_prazdna_kniha_neposila_ukazkove_nakupy(): void
    {
        $this->regensburg();

        $data = $this->getJson('/api/data/finance')->assertOk()->json('data');

        $this->assertArrayHasKey('TX', $data);
        $this->assertSame([], $data['TX']);
    }

    public function test_transakce_maji_tvar_prototypu(): void
    {
        $this->regensburg();
        $penezenka = Wallet::create([
            'gallery_space_id' => $this->prostor->id, 'name' => 'EUR hotovost',
            'kind' => 'cash', 'currency' => 'EUR', 'opening_balance' => 0, 'is_active' => true,
        ]);
        $kategorie = FinanceCategory::where('gallery_space_id', $this->prostor->id)->where('name', 'Potraviny')->sole();

        Transaction::create([
            'gallery_space_id' => $this->prostor->id, 'type' => 'expense',
            'occurred_at' => '2026-09-03 10:00:00', 'wallet_from_id' => $penezenka->id,
            'amount_from' => 12.40, 'currency_from' => 'EUR', 'category_id' => $kategorie->id,
            'description' => 'Lidl Regensburg', 'state' => 'approved', 'created_by' => $this->adri->id,
        ]);

        $radek = $this->getJson('/api/data/finance')->assertOk()->json('data.TX.0');

        $this->assertCount(9, $radek);
        $this->assertSame('3. 9.', $radek[1]);
        $this->assertSame('Lidl Regensburg', $radek[2]);
        $this->assertSame('Potraviny', $radek[3]);
        $this->assertSame(-12, $radek[4], 'Výdaj má být záporný — podle znaménka volí prototyp barvu.');
        $this->assertSame('EUR hotovost', $radek[5]);
        $this->assertStringStartsWith('ph-', $radek[6], 'Prototyp kreslí ikony Phosphor.');
    }

    /** Ikony aplikace jsou z Lucide, prototyp kreslí Phosphor. */
    public function test_ikony_dostanou_predponu_prototypu(): void
    {
        $this->regensburg();

        foreach ($this->getJson('/api/data/finance')->assertOk()->json('data.BUD.cats') as $kategorie) {
            $this->assertStringStartsWith('ph-', $kategorie[3]);
        }
    }

    public function test_ucty_pochazeji_z_penezenek(): void
    {
        $this->regensburg();
        Wallet::create([
            'gallery_space_id' => $this->prostor->id, 'name' => 'EUR karta',
            'kind' => 'bank', 'currency' => 'EUR', 'opening_balance' => 500, 'is_active' => true,
        ]);

        $ucty = $this->getJson('/api/data/finance')->assertOk()->json('data.FIN.accounts');

        $this->assertSame('EUR karta', $ucty[0][0]);
        $this->assertSame(500, $ucty[0][2]);
        $this->assertSame('napojeno', $ucty[0][4]);
    }

    public function test_neznama_skupina_je_404(): void
    {
        $this->getJson('/api/data/vymyslena')->assertNotFound();
    }

    public function test_bez_prihlaseni_neprojde(): void
    {
        $this->app['auth']->forgetGuards();
        auth()->guard('sanctum')->forgetUser();

        $this->getJson('/api/data/finance')->assertUnauthorized();
    }

    // ——— pomocné ———

    /** Rozpočet na Německo tak, jak ho zakládá `rozpocet:regensburg`. */
    private function regensburg(): Budget
    {
        $rozpocet = Budget::create([
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'name' => 'Německo — Regensburg',
            'currency' => 'EUR',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-02-28',
            'starting_funds' => 2891.37,
            'created_by' => $this->adri->id,
        ]);

        // [částka za celé období, priorita]; nižší priorita = financuje se první
        $plan = [
            'Ubytování' => [1680, 10],
            'Potraviny' => [720, 10],
            'Doprava' => [120, 20],
            'Drogerie a domácnost' => [120, 50],
            'Volný čas a výlety' => [60, 90],
        ];

        foreach ($plan as $nazev => [$castka, $priorita]) {
            $kategorie = FinanceCategory::firstOrCreate(
                ['gallery_space_id' => $this->prostor->id, 'name' => $nazev, 'kind' => 'expense'],
                ['icon' => 'bed', 'is_active' => true],
            );

            DB::table('budget_category_limits')->insert([
                'budget_id' => $rozpocet->id,
                'finance_category_id' => $kategorie->id,
                'amount' => $castka,
                'baseline_amount' => $castka,
                'priority' => $priorita,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $rozpocet;
    }
}
