<?php

namespace Tests\Feature\Obsah41;

use App\Models\GallerySpace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Partnerův soukromý rozpočet (`owner_user_id`) nepatří na obrazovky druhého.
 *
 * Obsah obrazovek četl `budgets` napřímo — bez pravidla `FinanceAccess::viditelne()`
 * a často i bez smazaných. Soukromé fondy, limity, odhady i vyrovnání tak
 * prosakovaly do rozborů, financí i zdraví.
 */
class SoukromeRozpoctyTest extends TestCase
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

    public function test_sezonni_fondy_bez_cizich_soukromych_a_smazanych_rozpoctu(): void
    {
        $spolecny = $this->rozpocet(null);
        $jeji = $this->rozpocet($this->maki->id, 'Makinčin');
        $smazany = $this->rozpocet(null, 'Starý', ['deleted_at' => now()]);

        $this->cil($spolecny, 'Dovolená');
        $this->cil($jeji, 'Její překvapení');
        $this->cil($smazany, 'Smazaný fond');
        $this->transakce(['amount_from' => 100]);

        $nazvy = collect($this->getJson('/api/data/rozbory')->assertOk()->json('data.SEASON'))->pluck('name')->all();

        $this->assertSame(['Dovolená'], $nazvy);
    }

    public function test_odhad_proti_skutecnosti_bez_ciziho_soukromeho_limitu(): void
    {
        $jidlo = $this->kategorie('Jídlo');
        $darky = $this->kategorie('Dárky');
        $this->limit($this->rozpocet(null), $jidlo, 5000);
        $this->limit($this->rozpocet($this->maki->id, 'Makinčin'), $darky, 3000);
        $this->transakce(['category_id' => $jidlo, 'amount_from' => 1200]);
        $this->transakce(['category_id' => $darky, 'amount_from' => 900]);

        $nazvy = collect($this->getJson('/api/data/rozbory')->assertOk()->json('data.EST'))->pluck('name')->all();

        $this->assertContains('Jídlo', $nazvy);
        $this->assertNotContains('Dárky', $nazvy);
    }

    /** Kategorie s limitem jen v partnerově soukromém rozpočtu je pro druhého „nečekaná". */
    public function test_necekane_vydaje_neznaji_cizi_soukromy_limit(): void
    {
        $darky = $this->kategorie('Dárky');
        $this->limit($this->rozpocet($this->maki->id, 'Makinčin'), $darky, 3000);

        foreach (range(1, 5) as $i) {
            $this->transakce(['amount_from' => 100, 'description' => 'Drobnost '.$i]);
        }
        $this->transakce(['category_id' => $darky, 'amount_from' => 5000, 'description' => 'Velký dárek']);

        $co = collect($this->getJson('/api/data/rozbory')->assertOk()->json('data.SURPRISE'))->pluck('what')->all();

        $this->assertContains('Velký dárek', $co);
    }

    public function test_limit_obalky_neni_z_ciziho_soukromeho_rozpoctu(): void
    {
        $obalka = $this->kategorie('Osobní obálka');
        $this->limit($this->rozpocet(null), $obalka, 2000);
        $this->limit($this->rozpocet($this->maki->id, 'Makinčin'), $obalka, 9000);
        $this->transakce(['category_id' => $obalka, 'amount_from' => 100]);

        $this->assertSame(2000, $this->getJson('/api/data/rozbory')->assertOk()->json('data.ENV.limit'));
    }

    public function test_vyrovnani_bez_ciziho_soukromeho_rozpoctu(): void
    {
        $spolecny = $this->rozpocet(null, 'Domácnost');
        $jeji = $this->rozpocet($this->maki->id, 'Makinčin tajný');
        $this->vyrovnani($spolecny, $this->dnes()->subDays(40), 'Společné');
        $this->vyrovnani($jeji, $this->dnes()->subDays(41), 'Tajné');

        $radky = collect($this->getJson('/api/data/finance')->assertOk()->json('data.AL.balancing'))->pluck(0)->all();

        $this->assertSame(['Společné'], $radky);
    }

    /** Vyrovnání v cizím soukromém rozpočtu neposouvá „kdo co zaplatil" v tom společném. */
    public function test_kdo_co_zaplatil_neposouva_cizi_soukrome_vyrovnani(): void
    {
        $this->rozpocet(null, 'Domácnost', ['starts_on' => $this->dnes()->startOfMonth()->toDateString()]);
        $jeji = $this->rozpocet($this->maki->id, 'Makinčin tajný');
        $this->vyrovnani($jeji, $this->dnes(), 'Tajné');
        $this->transakce(['amount_from' => 250, 'occurred_at' => $this->dnes()->startOfMonth()->toDateTimeString()]);

        $zaplaceno = $this->getJson('/api/data/finance')->assertOk()->json('data.BUD.paid');

        $this->assertSame([['Adrian', 'Nezařazeno', 250]], $zaplaceno);
    }

    /**
     * Běžící rozpočet se pozná podle dneška dvojice, ne podle okamžiku v UTC.
     *
     * `ends_on >= now()` porovnávalo datum s časem — poslední den rozpočtu
     * odpoledne už „neběžel" a obrazovka skočila na rozpočet, který začne
     * až za měsíc.
     */
    public function test_rozpocet_bezi_i_posledni_den(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));

        $this->rozpocet(null, 'Září', ['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'monthly_income' => 1000]);
        $this->rozpocet(null, 'Listopad', ['starts_on' => '2026-11-01', 'monthly_income' => 2000]);

        $this->assertSame(1000, $this->getJson('/api/data/finance')->assertOk()->json('data.BUD.income'));
    }

    public function test_den_pres_limit_bez_ciziho_soukromeho_limitu_a_jen_ze_skutecnych_utrat(): void
    {
        $jidlo = $this->kategorie('Jídlo');
        $darky = $this->kategorie('Dárky');
        $this->limit($this->rozpocet(null), $jidlo, 500);
        $this->limit($this->rozpocet($this->maki->id, 'Makinčin'), $darky, 500);
        $this->nalada();

        // Nic z toho není útrata v měně rozpočtu.
        $this->transakce(['category_id' => $darky, 'amount_from' => 800]);
        $this->transakce(['category_id' => $jidlo, 'amount_from' => 800, 'type' => 'transfer']);
        $this->transakce(['category_id' => $jidlo, 'amount_from' => 800, 'state' => 'draft']);
        $this->transakce(['category_id' => $jidlo, 'amount_from' => 800, 'currency_from' => 'EUR']);
        $this->transakce(['category_id' => $jidlo, 'amount_from' => 800, 'excluded_from_budget' => true, 'exclusion_reason' => 'dar']);

        $druhy = collect($this->getJson('/api/data/zdravi')->assertOk()->json('data.KL_EV'))->pluck('kind')->all();
        $this->assertNotContains('Výdaj přes limit', $druhy);

        // Kontrola: skutečná útrata přes limit se hlásí.
        $this->transakce(['category_id' => $jidlo, 'amount_from' => 600]);
        $druhy = collect($this->getJson('/api/data/zdravi')->assertOk()->json('data.KL_EV'))->pluck('kind')->all();
        $this->assertContains('Výdaj přes limit', $druhy);
    }

    // ——— pomůcky ———

    private function rozpocet(?int $vlastnik, string $nazev = 'Rozpočet', array $navic = []): int
    {
        return DB::table('budgets')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $vlastnik,
            'created_by' => $vlastnik ?? $this->adri->id,
            'name' => $nazev,
            'currency' => 'CZK',
            'starts_on' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'is_shared' => $vlastnik === null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
    }

    private function cil(int $rozpocet, string $nazev): void
    {
        DB::table('budget_goals')->insert([
            'uuid' => (string) Str::uuid(), 'budget_id' => $rozpocet, 'name' => $nazev,
            'target_amount' => 10000, 'saved_amount' => 1000, 'currency' => 'CZK',
            'target_on' => now()->addMonths(4)->toDateString(), 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function limit(int $rozpocet, int $kategorie, int $castka): void
    {
        DB::table('budget_category_limits')->insert([
            'budget_id' => $rozpocet, 'finance_category_id' => $kategorie, 'amount' => $castka,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function vyrovnani(int $rozpocet, CarbonImmutable $do, string $poznamka): void
    {
        DB::table('budget_settlements')->insert([
            'uuid' => (string) Str::uuid(), 'budget_id' => $rozpocet, 'currency' => 'CZK',
            'settled_through' => $do->toDateString(), 'amount' => 500, 'note' => $poznamka,
            'from_user_id' => $this->maki->id, 'to_user_id' => $this->adri->id, 'created_by' => $this->maki->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function kategorie(string $nazev): int
    {
        return DB::table('finance_categories')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->prostor->id, 'name' => $nazev,
            'kind' => 'expense', 'is_favourite' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function transakce(array $navic = []): void
    {
        DB::table('transactions')->insert(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'created_by' => $this->adri->id,
            'type' => 'expense',
            'occurred_at' => $this->dnes()->toDateTimeString(),
            'amount_from' => 100,
            'currency_from' => 'CZK',
            'description' => 'Výdaj',
            'created_at' => now(),
            'updated_at' => now(),
        ], $navic));
    }

    private function nalada(): void
    {
        DB::table('wellbeing_moods')->insert([
            'gallery_space_id' => $this->prostor->id, 'user_id' => $this->adri->id,
            'day' => $this->dnes()->toDateString(), 'value' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
