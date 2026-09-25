<?php

namespace Tests\Feature\Cesty41;

use App\Models\Budget;
use App\Models\BudgetGoal;
use App\Models\FinanceCategory;
use App\Models\FinanceRecurring;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Peníze z galerie při souběhu dvou požadavků.
 *
 * Vklad do vyhrazené částky, „Opakovat" platbu a přesun mezi limity četly
 * hodnotu, počítaly v PHP a zapsaly výsledek. Druhý požadavek mezi čtením
 * a zápisem (oba z dvojice naráz, dvojklik) se tím ztratil — nebo z jedné
 * platby vznikly dva předpisy a budoucí platby se zapisovaly dvakrát.
 *
 * Souběh se tu napodobí tak, že „druhý požadavek" zapíše do databáze hned
 * po tom, co si ji první přečetl.
 */
class FinanceSoubehTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    private Wallet $ucet;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-14 10:00:00');

        $this->adri = User::factory()->create(['name' => 'Adrian Staněk']);
        $maki = User::factory()->create(['name' => 'Makinka Kubíčková']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([
            $this->adri->id => ['role' => 'owner'],
            $maki->id => ['role' => 'editor'],
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

    public function test_soubezny_vklad_se_neztrati(): void
    {
        $cil = $this->cil(0);
        $this->poPrecteniCile(fn () => DB::table('budget_goals')->where('id', $cil->id)->update(['saved_amount' => DB::raw('saved_amount + 3000')]));

        $this->postJson('/api/finance/cile/'.$cil->uuid.'/vklad', ['castka' => 5000])->assertOk();

        $this->assertEquals(8000, (float) $cil->fresh()->saved_amount, 'Vklad druhého z dvojice se přepsal.');
    }

    public function test_soubezny_vyber_neprecerpa_vyhrazenou_castku(): void
    {
        $cil = $this->cil(5000);
        // Druhý z dvojice mezitím vybral 4000 — zbývá 1000.
        $this->poPrecteniCile(fn () => DB::table('budget_goals')->where('id', $cil->id)->update(['saved_amount' => DB::raw('saved_amount - 4000')]));

        $this->postJson('/api/finance/cile/'.$cil->uuid.'/vklad', ['castka' => -3000])->assertStatus(422);

        $this->assertEquals(1000, (float) $cil->fresh()->saved_amount);
    }

    public function test_vyber_do_nuly_projde(): void
    {
        $cil = $this->cil(5000);

        $this->postJson('/api/finance/cile/'.$cil->uuid.'/vklad', ['castka' => -5000])->assertOk();
        $this->postJson('/api/finance/cile/'.$cil->uuid.'/vklad', ['castka' => -0.01])->assertStatus(422);

        $this->assertEquals(0, (float) $cil->fresh()->saved_amount);
    }

    public function test_dvojite_opakovat_nezalozi_dva_predpisy(): void
    {
        $t = Transaction::create([
            'gallery_space_id' => $this->prostor->id, 'type' => 'expense', 'occurred_at' => '2026-09-05',
            'wallet_from_id' => $this->ucet->id, 'amount_from' => 15000, 'currency_from' => 'CZK',
            'description' => 'Nájem', 'state' => 'approved', 'created_by' => $this->adri->id,
        ]);

        // První kliknutí doběhlo mezi tím, co si druhé platbu přečetlo, a zápisem.
        $jednou = false;
        Transaction::retrieved(function (Transaction $nactena) use (&$jednou): void {
            if ($jednou) {
                return;
            }
            $jednou = true;
            $prvni = FinanceRecurring::create([
                'gallery_space_id' => $this->prostor->id, 'name' => 'Nájem', 'type' => 'expense', 'amount' => 15000,
                'currency' => 'CZK', 'wallet_id' => $this->ucet->id, 'day_of_month' => 5, 'starts_on' => '2026-10-05',
                'created_by' => $this->adri->id, 'is_active' => true,
            ]);
            DB::table('transactions')->where('id', $nactena->id)->update(['recurring_id' => $prvni->id]);
        });

        $this->postJson('/api/finance/transakce/'.$t->uuid.'/opakovat')->assertStatus(422);

        $this->assertSame(1, FinanceRecurring::count(), 'Druhý předpis by budoucí nájem zapisoval dvakrát.');
        $this->assertSame(FinanceRecurring::sole()->id, $t->fresh()->recurring_id);
    }

    /**
     * Limit, ze kterého se přesouvá, se čte v transakci (pod zámkem řádku).
     *
     * SQLite `lockForUpdate()` nezná, takže se hlídá, kde se čte: mimo
     * transakci ho souběžná úprava limitu stihne změnit před zápisem.
     */
    public function test_presun_cte_limit_v_transakci(): void
    {
        $rozpocet = $this->rozpocet();
        $zakladni = DB::transactionLevel();
        $urovne = [];
        $zapsano = false;
        // Jen čtení před prvním zápisem limitu — obsah obrazovky po akci je čte znovu.
        DB::listen(function (QueryExecuted $dotaz) use (&$urovne, &$zapsano): void {
            $sql = strtolower($dotaz->sql);
            if (! str_contains($sql, 'budget_category_limits') || $zapsano) {
                return;
            }
            if (str_starts_with($sql, 'update')) {
                $zapsano = true;
            } elseif (str_starts_with($sql, 'select')) {
                $urovne[] = DB::transactionLevel();
            }
        });

        $this->postJson('/api/finance/rozpocet/presun', ['z' => 'Potraviny', 'do' => 'Zábava', 'castka' => 1000])->assertOk();

        $this->assertNotEmpty($urovne);
        $this->assertGreaterThan($zakladni, min($urovne), 'Limit se četl mimo transakci.');
        $limity = DB::table('budget_category_limits as l')->join('finance_categories as k', 'k.id', '=', 'l.finance_category_id')
            ->where('l.budget_id', $rozpocet->id)->pluck('l.amount', 'k.name')->map(fn ($v) => (float) $v)->all();
        $this->assertEquals(['Potraviny' => 4000.0, 'Zábava' => 2000.0], $limity);
    }

    private function poPrecteniCile(callable $druhyPozadavek): void
    {
        $jednou = false;
        BudgetGoal::retrieved(function () use (&$jednou, $druhyPozadavek): void {
            if (! $jednou) {
                $jednou = true;
                $druhyPozadavek();
            }
        });
    }

    private function cil(float $nasporeno): BudgetGoal
    {
        return BudgetGoal::create([
            'budget_id' => $this->rozpocet()->id, 'name' => 'Island', 'target_amount' => 120000,
            'currency' => 'CZK', 'saved_amount' => $nasporeno, 'sort_order' => 1,
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
}
