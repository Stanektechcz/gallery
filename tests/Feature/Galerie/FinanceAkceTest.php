<?php

namespace Tests\Feature\Galerie;

use App\Models\Budget;
use App\Models\BudgetGoal;
use App\Models\FinanceCategory;
use App\Models\FinanceRecurring;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\RecurringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Finance z obrazovek galerie — akce, které dřív hlásily „zatím neumíme".
 */
class FinanceAkceTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $maki;

    private GallerySpace $prostor;

    private Wallet $ucet;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-14 10:00:00');

        $this->adri = User::factory()->create(['name' => 'Adrian Staněk']);
        $this->maki = User::factory()->create(['name' => 'Makinka Kubíčková']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $this->maki->id => ['role' => 'editor'],
        ]);

        $this->ucet = Wallet::create([
            'gallery_space_id' => $this->prostor->id, 'name' => 'Společný účet', 'kind' => 'bank',
            'currency' => 'CZK', 'opening_balance' => 0, 'is_active' => true, 'sort_order' => 0,
        ]);

        Sanctum::actingAs($this->adri);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_poznamka_se_ulozi_a_ukaze_v_detailu(): void
    {
        $t = $this->vydaj('Albert', 500);

        $this->postJson('/api/finance/transakce/'.$t->uuid.'/poznamka', ['poznamka' => 'na oslavu'])
            ->assertOk()
            ->assertJsonPath('data.TX.0.8', 'na oslavu');

        $this->assertSame('na oslavu', $t->fresh()->note);
    }

    public function test_vynechani_z_rozpoctu_chce_duvod(): void
    {
        $t = $this->vydaj('Pojistka', 3000);

        $this->postJson('/api/finance/transakce/'.$t->uuid.'/rozpocet', ['vynechat' => true])->assertStatus(422);

        $this->postJson('/api/finance/transakce/'.$t->uuid.'/rozpocet', ['vynechat' => true, 'duvod' => 'platí firma'])
            ->assertOk()->assertJsonPath('data.TX.0.7.mimo', 1);

        $this->assertTrue($t->fresh()->excluded_from_budget);
        $this->assertSame('platí firma', $t->fresh()->exclusion_reason);
    }

    public function test_opakovana_platba_vytvori_predpis_a_nezdvoji_splatku(): void
    {
        $t = $this->vydaj('Nájem', 15000, '2026-09-05');

        $this->postJson('/api/finance/transakce/'.$t->uuid.'/opakovat')->assertOk();

        $predpis = FinanceRecurring::sole();
        $this->assertSame(5, (int) $predpis->day_of_month);
        $this->assertSame($predpis->id, $t->fresh()->recurring_id);

        // Nadcházející platby: říjen a listopad, zářijová splátka už je zapsaná.
        $nadchazejici = $this->getJson('/api/data/finance')->json('data.FIN.upcoming');
        $this->assertSame(['5. 10.', '5. 11.'], array_column($nadchazejici, 0));

        $this->postJson('/api/finance/transakce/'.$t->uuid.'/opakovat')->assertStatus(422);
    }

    public function test_rozdeleni_do_kategorii_sedi_do_halere(): void
    {
        FinanceCategory::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Potraviny', 'kind' => 'expense', 'is_active' => true]);
        FinanceCategory::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Drogerie', 'kind' => 'expense', 'is_active' => true]);
        $t = $this->vydaj('Albert', 1000);

        $this->postJson('/api/finance/transakce/'.$t->uuid.'/rozdelit', ['casti' => [
            ['kategorie' => 'Potraviny', 'castka' => 700],
            ['kategorie' => 'Drogerie', 'castka' => 200],
        ]])->assertStatus(422);

        $this->postJson('/api/finance/transakce/'.$t->uuid.'/rozdelit', ['casti' => [
            ['kategorie' => 'Potraviny', 'castka' => 700],
            ['kategorie' => 'Drogerie', 'castka' => 300],
        ]])->assertOk();

        $castky = Transaction::where('gallery_space_id', $this->prostor->id)->pluck('amount_from')->map(fn ($c) => (float) $c)->sort()->values()->all();
        $this->assertSame([300.0, 700.0], $castky);
    }

    public function test_planovana_platba_a_preskoceni_terminu(): void
    {
        $this->postJson('/api/finance/platby', ['nazev' => 'Telefon', 'castka' => 499, 'den' => 20])->assertStatus(201);

        $radky = $this->getJson('/api/data/finance')->json('data.FIN.upcoming');
        $this->assertSame('20. 9.', $radky[0][0]);
        $this->assertTrue($radky[0][5], 'Září je tento měsíc.');

        $this->postJson('/api/finance/platby/'.$radky[0][6].'/preskocit', ['datum' => '2026-09-20'])->assertOk();

        $po = $this->getJson('/api/data/finance')->json('data.FIN.upcoming');
        $this->assertSame('20. 10.', $po[0][0]);

        // Přeskočený termín generátor nevytvoří.
        app(RecurringService::class)->generovat($this->prostor, Carbon::parse('2026-09-25'));
        $this->assertSame(0, Transaction::where('recurring_id', FinanceRecurring::sole()->id)->count());
    }

    public function test_limity_presun_a_navrat_k_puvodnimu(): void
    {
        $rozpocet = $this->rozpocet();

        $this->postJson('/api/finance/rozpocet/limity', ['limity' => ['Potraviny' => 6000]])->assertOk();
        $this->assertEquals(6000, (float) $this->limit($rozpocet, 'Potraviny'));

        $this->postJson('/api/finance/rozpocet/presun', ['z' => 'Potraviny', 'do' => 'Zábava', 'castka' => 1000])->assertOk();
        $this->assertEquals(5000, (float) $this->limit($rozpocet, 'Potraviny'));
        $this->assertEquals(2000, (float) $this->limit($rozpocet, 'Zábava'));

        $this->postJson('/api/finance/rozpocet/presun', ['z' => 'Zábava', 'do' => 'Potraviny', 'castka' => 99999])->assertStatus(422);

        $this->postJson('/api/finance/rozpocet/puvodni')->assertOk();
        $this->assertEquals(5000, (float) $this->limit($rozpocet, 'Potraviny'));
        $this->assertSame(3, DB::table('finance_plan_log')->where('budget_id', $rozpocet->id)->where('action', '!=', 'puvodni-odhad')->count());
    }

    public function test_vyhrazena_castka_a_vklad(): void
    {
        $this->rozpocet();

        $this->postJson('/api/finance/cile', ['nazev' => 'Island', 'castka' => 120000])->assertStatus(201);
        $cil = BudgetGoal::sole();

        $this->postJson('/api/finance/cile/'.$cil->uuid.'/vklad', ['castka' => 5000])
            ->assertOk()
            ->assertJsonPath('data.BUD.goals.0.2', 5000)
            ->assertJsonPath('data.BUD.goals.0.7', $cil->uuid);

        $this->postJson('/api/finance/cile/'.$cil->uuid.'/vklad', ['castka' => -9000])->assertStatus(422);
    }

    /** Po vyrovnání se platby do dneška mezi dvojicí nepočítají. */
    public function test_vyrovnani_vynuluje_kdo_co_zaplatil(): void
    {
        $this->rozpocet();
        $this->vydaj('Albert', 800, '2026-09-10');

        $this->assertNotEmpty($this->getJson('/api/data/finance')->json('data.BUD.paid'));

        $this->postJson('/api/finance/vyrovnani', ['castka' => 400, 'od' => 'Makinka', 'komu' => 'Adrian'])->assertOk();

        $this->assertSame([], $this->getJson('/api/data/finance')->json('data.BUD.paid'));
        $this->postJson('/api/finance/vyrovnani', ['castka' => 1, 'od' => 'Makinka', 'komu' => 'Cizinec'])->assertStatus(422);
    }

    public function test_novy_rucni_ucet(): void
    {
        $this->postJson('/api/finance/ucty', ['nazev' => 'Hotovost', 'druh' => 'cash', 'mena' => 'eur', 'zustatek' => 120])
            ->assertStatus(201)
            ->assertJsonPath('data.FIN.accounts.1.0', 'Hotovost');

        $ucet = Wallet::where('name', 'Hotovost')->sole();
        $this->assertSame('EUR', $ucet->currency);
        $this->assertSame('cash', $ucet->kind);

        $this->postJson('/api/finance/ucty', ['nazev' => 'hotovost', 'druh' => 'cash', 'mena' => 'CZK'])->assertStatus(422);
    }

    /** Vklad na spoření je převod: přesune zůstatek, do rozpočtu se nepočítá. */
    public function test_prevod_na_sporeni(): void
    {
        Wallet::create([
            'gallery_space_id' => $this->prostor->id, 'name' => 'Spoření', 'kind' => 'other',
            'currency' => 'CZK', 'opening_balance' => 0, 'is_active' => true, 'sort_order' => 10,
        ]);

        $this->postJson('/api/finance/prevod', ['na' => 'Spoření', 'castka' => 3000])->assertStatus(201);

        $t = Transaction::sole();
        $this->assertSame('transfer', $t->type);
        $this->assertSame($this->ucet->id, $t->wallet_from_id);
        $this->assertTrue($t->excluded_from_budget);

        $this->postJson('/api/finance/prevod', ['z' => 'Spoření', 'na' => 'Spoření', 'castka' => 1])->assertStatus(422);
    }

    public function test_cizi_transakce_je_nedostupna(): void
    {
        $cizi = User::factory()->create();
        $ciziProstor = GallerySpace::create(['name' => 'Cizí', 'owner_id' => $cizi->id]);
        $t = Transaction::create([
            'gallery_space_id' => $ciziProstor->id, 'type' => 'expense', 'occurred_at' => '2026-09-10',
            'amount_from' => 100, 'currency_from' => 'CZK', 'description' => 'Cizí', 'state' => 'approved', 'created_by' => $cizi->id,
        ]);

        $this->postJson('/api/finance/transakce/'.$t->uuid.'/poznamka', ['poznamka' => 'x'])->assertNotFound();
        $this->assertNull($t->fresh()->note);
    }

    private function vydaj(string $popis, float $castka, string $den = '2026-09-10'): Transaction
    {
        return Transaction::create([
            'gallery_space_id' => $this->prostor->id, 'type' => 'expense', 'occurred_at' => $den,
            'wallet_from_id' => $this->ucet->id, 'amount_from' => $castka, 'currency_from' => 'CZK',
            'description' => $popis, 'state' => 'approved', 'created_by' => $this->adri->id,
        ]);
    }

    private function rozpocet(): Budget
    {
        $rozpocet = Budget::create([
            'gallery_space_id' => $this->prostor->id, 'owner_user_id' => $this->adri->id,
            'name' => 'Domácnost', 'currency' => 'CZK', 'starts_on' => '2026-09-01', 'period_mode' => 'rolling',
            'monthly_income' => 60000, 'created_by' => $this->adri->id,
        ]);

        foreach (['Potraviny' => 5000, 'Zábava' => 1000] as $nazev => $castka) {
            $k = FinanceCategory::firstOrCreate(['gallery_space_id' => $this->prostor->id, 'name' => $nazev, 'kind' => 'expense'], ['is_active' => true]);
            DB::table('budget_category_limits')->insert([
                'budget_id' => $rozpocet->id, 'finance_category_id' => $k->id, 'amount' => $castka,
                'baseline_amount' => $castka, 'priority' => 50, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $rozpocet;
    }

    private function limit(Budget $rozpocet, string $kategorie): mixed
    {
        return DB::table('budget_category_limits as l')->join('finance_categories as k', 'k.id', '=', 'l.finance_category_id')
            ->where('l.budget_id', $rozpocet->id)->where('k.name', $kategorie)->value('l.amount');
    }
}
