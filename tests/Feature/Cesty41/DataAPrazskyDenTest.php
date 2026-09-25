<?php

namespace Tests\Feature\Cesty41;

use App\Models\Budget;
use App\Models\BudgetGoal;
use App\Models\FinanceCategory;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Data z galerie: přesný tvar a pražský den.
 *
 * Pravidlo `date` bere i „tomorrow" nebo datum s časem a hodnota šla surově
 * do sloupce DATE. „Dnes" se bralo v UTC — po půlnoci v Praze to byl ještě
 * včerejšek (termín tisku, odhad rozpočtu, výběr aktuálního rozpočtu, čas
 * výdaje na cestě).
 */
class DataAPrazskyDenTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->adri = User::factory()->create(['name' => 'Adrian Staněk']);
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->prostor->members()->syncWithoutDetaching([$this->adri->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_datum_alba_jen_jako_rok_mesic_den(): void
    {
        $album = $this->postJson('/api/alba', ['nazev' => 'Pálava'])->assertOk()->json('album');

        foreach (['tomorrow', '2026-09-05T10:00:00', '5. 9. 2026'] as $spatne) {
            $this->patchJson('/api/alba/'.$album, ['nazev' => 'Pálava', 'datum' => $spatne])->assertStatus(422)->assertJsonValidationErrors('datum');
        }
        $this->patchJson('/api/alba/'.$album, ['nazev' => 'Pálava', 'datum' => '2026-09-05'])->assertOk();
        $this->patchJson('/api/alba/'.$album, ['nazev' => 'Pálava', 'datum' => null])->assertOk();
    }

    public function test_termin_vyhrazene_castky_jen_jako_rok_mesic_den(): void
    {
        Carbon::setTestNow('2026-09-14 10:00:00');
        $this->rozpocet('2026-09-01');

        $this->postJson('/api/finance/cile', ['nazev' => 'Island', 'castka' => 1000, 'termin' => 'tomorrow'])->assertStatus(422)->assertJsonValidationErrors('termin');
        $this->postJson('/api/finance/cile', ['nazev' => 'Island', 'castka' => 1000, 'termin' => '2027-06-30 12:00'])->assertStatus(422);
        $this->postJson('/api/finance/cile', ['nazev' => 'Island', 'castka' => 1000, 'termin' => '2027-06-30'])->assertStatus(201);

        $this->assertSame('2027-06-30', substr((string) BudgetGoal::sole()->getRawOriginal('target_on'), 0, 10));
    }

    /** Pondělí 22:30 UTC je v Praze úterý — deset pracovních dní od úterka. */
    public function test_odhad_tisku_pocita_od_prazskeho_dne(): void
    {
        Carbon::setTestNow('2026-09-28 22:30:00');

        $this->postJson('/api/tisk/objednavka', ['title' => 'Fotokniha Pálava'])->assertOk();

        $this->assertSame('2026-10-13', substr((string) DB::table('print_orders')->value('due_on'), 0, 10));
    }

    /**
     * Rozpočet končící dnes je dnes ještě aktuální.
     *
     * `starts_on <= now()` porovnávalo DATE s časem: rozpočet s koncem
     * `2026-09-30` přestal být aktuální už 30. 9. v 0:00 UTC a zápis šel do
     * rozpočtu, který teprve začne.
     */
    public function test_zapis_jde_do_rozpoctu_ktery_dnes_plati(): void
    {
        Carbon::setTestNow('2026-09-30 10:00:00');
        $zari = $this->rozpocet('2026-09-01', '2026-09-30');
        $rijen = $this->rozpocet('2026-10-01');

        $this->postJson('/api/finance/rozpocet/limity', ['limity' => ['Potraviny' => 7000]])->assertOk();

        $this->assertEquals(7000, (float) $this->limit($zari, 'Potraviny'));
        $this->assertEquals(5000, (float) $this->limit($rijen, 'Potraviny'));
    }

    /** 30. 9. 22:30 UTC je v Praze už 1. 10. — odhad je z července až září. */
    public function test_odhad_noveho_rozpoctu_z_prazskeho_mesice(): void
    {
        Carbon::setTestNow('2026-09-30 22:30:00');
        $ucet = Wallet::create([
            'gallery_space_id' => $this->prostor->id, 'name' => 'Společný účet', 'kind' => 'bank',
            'currency' => 'CZK', 'opening_balance' => 0, 'is_active' => true, 'sort_order' => 0,
        ]);
        $potraviny = FinanceCategory::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Potraviny', 'kind' => 'expense', 'is_active' => true]);
        foreach (['2026-06-10' => 9000, '2026-07-10' => 3000, '2026-08-10' => 3000, '2026-09-10' => 3000] as $den => $castka) {
            Transaction::create([
                'gallery_space_id' => $this->prostor->id, 'type' => 'expense', 'occurred_at' => $den,
                'wallet_from_id' => $ucet->id, 'amount_from' => $castka, 'currency_from' => 'CZK', 'category_id' => $potraviny->id,
                'description' => 'Albert', 'state' => 'approved', 'created_by' => $this->adri->id,
            ]);
        }

        $this->postJson('/api/finance/rozpocet/zalozit')->assertStatus(201);

        $this->assertEquals(3000, (float) DB::table('budget_category_limits')->where('finance_category_id', $potraviny->id)->value('amount'));
    }

    /** Výdaj po pražské půlnoci patří k novému dni cesty, ne ke včerejšku v UTC. */
    public function test_vydaj_cesty_ma_mistni_cas(): void
    {
        Carbon::setTestNow('2026-09-28 22:30:00');
        $cesta = DB::table('trips')->insertGetId([
            'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id, 'name' => 'Vídeň',
            'start_date' => '2026-09-28', 'end_date' => '2026-09-30', 'timezone' => 'Europe/Prague',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/cesty/'.$cesta.'/vydaj', ['nazev' => 'Taxi', 'castka' => 300])->assertStatus(201);

        $this->assertSame('2026-09-29 00:30:00', substr((string) DB::table('trip_expenses')->value('occurred_at'), 0, 19));
    }

    private function rozpocet(string $od, ?string $do = null): Budget
    {
        $rozpocet = Budget::create([
            'gallery_space_id' => $this->prostor->id, 'owner_user_id' => $this->adri->id,
            'name' => 'Domácnost '.$od, 'currency' => 'CZK', 'starts_on' => $od, 'ends_on' => $do, 'period_mode' => 'rolling',
            'monthly_income' => 60000, 'created_by' => $this->adri->id,
        ]);
        $k = FinanceCategory::firstOrCreate(['gallery_space_id' => $this->prostor->id, 'name' => 'Potraviny', 'kind' => 'expense'], ['is_active' => true]);
        DB::table('budget_category_limits')->insert([
            'budget_id' => $rozpocet->id, 'finance_category_id' => $k->id, 'amount' => 5000,
            'baseline_amount' => 5000, 'priority' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $rozpocet;
    }

    private function limit(Budget $rozpocet, string $kategorie): mixed
    {
        return DB::table('budget_category_limits as l')->join('finance_categories as k', 'k.id', '=', 'l.finance_category_id')
            ->where('l.budget_id', $rozpocet->id)->where('k.name', $kategorie)->value('l.amount');
    }
}
