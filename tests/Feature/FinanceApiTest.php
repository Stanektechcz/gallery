<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\FinanceProject;
use App\Models\GallerySpace;
use App\Models\Partner;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API modulu Rozpočet přes skutečné HTTP.
 *
 * Testuje hlavně hranici mezi **chybou** a **varováním**. Chyba je stav, který nemůže
 * být pravda a formulář ho nesmí pustit. Varování je něco neobvyklého, co pravda být
 * může — a musí jít potvrdit, jinak se lidé naučí, že aplikace lže, a přestanou číst
 * i skutečné chyby.
 */
class FinanceApiTest extends TestCase
{
    use RefreshDatabase;

    private User $uzivatel;
    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uzivatel = User::factory()->create();
        $this->space = GallerySpace::create(['name' => 'Zkouška', 'owner_id' => $this->uzivatel->id]);
        $this->uzivatel->gallerySpaces()->syncWithoutDetaching([$this->space->id => ['role' => 'owner']]);
        $this->actingAs($this->uzivatel);
    }

    private function penezenka(string $jmeno, string $mena, float $pocatek = 0, ?int $partner = null, string $druh = 'bank'): Wallet
    {
        return Wallet::create([
            'gallery_space_id' => $this->space->id, 'name' => $jmeno, 'kind' => $druh,
            'currency' => $mena, 'opening_balance' => $pocatek, 'partner_id' => $partner, 'is_active' => true,
        ]);
    }

    public function test_ciselniky_zalozi_vychozi_kategorie(): void
    {
        $odpoved = $this->getJson('/api/v1/rozpocet/ciselniky')->assertOk();

        $kategorie = collect($odpoved->json('categories'));

        $this->assertGreaterThanOrEqual(14, $kategorie->where('kind', 'expense')->count());
        $this->assertTrue($kategorie->contains(fn ($k) => $k['name'] === 'Potraviny' && $k['is_favourite']));
        $this->assertTrue($kategorie->contains(fn ($k) => $k['name'] === 'Mzda' && $k['kind'] === 'income'));
    }

    /** Scénář A: běžný výdaj se uloží a hned se projeví v zůstatku i v přehledu. */
    public function test_bezny_vydaj_se_hned_projevi(): void
    {
        $ucet = $this->penezenka('EUR karta', 'EUR', 500);
        $this->getJson('/api/v1/rozpocet/ciselniky');
        $potraviny = FinanceCategory::where('gallery_space_id', $this->space->id)->where('name', 'Potraviny')->first();

        $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'expense',
            'occurred_at' => now()->toDateString(),
            'wallet_from' => $ucet->uuid,
            'amount_from' => 12.5,
            'category' => $potraviny->uuid,
        ])->assertCreated();

        $prehled = $this->getJson('/api/v1/rozpocet/prehled')->assertOk();

        $this->assertSame(487.5, collect($prehled->json('wallets'))->firstWhere('name', 'EUR karta')['balance']);
        $this->assertSame(12.5, collect($prehled->json('summary'))->firstWhere('currency', 'EUR')['expense']);
        $this->assertSame(12.5, collect($prehled->json('categories'))->firstWhere('name', 'Potraviny')['amount']);
    }

    /** Převod mezi různými měnami je chyba a hláška má říct, co s tím. */
    public function test_prevod_mezi_menami_je_chyba_s_navodem(): void
    {
        $czk = $this->penezenka('CZK', 'CZK', 50000);
        $eur = $this->penezenka('EUR', 'EUR');

        $odpoved = $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'transfer', 'occurred_at' => now()->toDateString(),
            'wallet_from' => $czk->uuid, 'wallet_to' => $eur->uuid, 'amount_from' => 1000,
        ])->assertStatus(422);

        $this->assertStringContainsString('Směnu', $odpoved->json('errors.wallet_to.0'));
        $this->assertSame(0, Transaction::count());
    }

    /** Směna ve stejné měně je taky chyba — a míří na správný typ. */
    public function test_smena_ve_stejne_mene_je_chyba(): void
    {
        $a = $this->penezenka('EUR A', 'EUR', 500);
        $b = $this->penezenka('EUR B', 'EUR');

        $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'exchange', 'occurred_at' => now()->toDateString(),
            'wallet_from' => $a->uuid, 'wallet_to' => $b->uuid,
            'amount_from' => 100, 'amount_to' => 100,
        ])->assertStatus(422)->assertJsonPath('errors.wallet_to.0', 'Směna je mezi různými měnami. Přesun ve stejné měně je Převod.');
    }

    /** Rozdělení, které nedá celek, je chyba — a hláška ukáže obě čísla. */
    public function test_rozdeleni_musi_dat_celek(): void
    {
        $adri = Partner::create(['gallery_space_id' => $this->space->id, 'kind' => 'person', 'name' => 'Adri', 'is_active' => true]);
        $maki = Partner::create(['gallery_space_id' => $this->space->id, 'kind' => 'person', 'name' => 'Maki', 'is_active' => true]);
        $ucet = $this->penezenka('EUR', 'EUR', 500);

        $odpoved = $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'expense', 'occurred_at' => now()->toDateString(),
            'wallet_from' => $ucet->uuid, 'amount_from' => 60,
            'split' => [
                ['partner_id' => $adri->id, 'amount' => 30],
                ['partner_id' => $maki->id, 'amount' => 20],
            ],
        ])->assertStatus(422);

        $this->assertStringContainsString('50,00 z 60,00', $odpoved->json('errors.split.0'));
    }

    /** Vyřazení z rozpočtu bez důvodu neprojde. */
    public function test_vyrazeni_z_rozpoctu_vyzaduje_duvod(): void
    {
        $ucet = $this->penezenka('EUR', 'EUR', 500);

        $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'expense', 'occurred_at' => now()->toDateString(),
            'wallet_from' => $ucet->uuid, 'amount_from' => 50,
            'excluded_from_budget' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('exclusion_reason');
    }

    /** Neobvyklý kurz se ptá, ale po potvrzení uloží. */
    public function test_neobvykly_kurz_se_pta_ale_neblokuje(): void
    {
        $czk = $this->penezenka('CZK', 'CZK', 500000);
        $eur = $this->penezenka('EUR', 'EUR');

        $telo = [
            'type' => 'exchange', 'occurred_at' => now()->toDateString(),
            'wallet_from' => $czk->uuid, 'wallet_to' => $eur->uuid,
            'amount_from' => 240000, 'amount_to' => 1000,   // 240 Kč za euro
        ];

        $odpoved = $this->postJson('/api/v1/rozpocet/transakce', $telo)->assertStatus(409);

        $this->assertTrue($odpoved->json('needs_confirmation'));
        $this->assertSame('kurz', $odpoved->json('warnings.0.key'));
        $this->assertSame(0, Transaction::count(), 'Dokud se nepotvrdí, nesmí se uložit.');

        // Legitimní směna po potvrzení projde.
        $this->postJson('/api/v1/rozpocet/transakce', $telo + ['potvrzeno' => true])->assertCreated();
        $this->assertSame(1, Transaction::count());
    }

    /** Dva stejné nákupy za den se ptají, ale jsou legitimní. */
    public function test_duplicita_varuje_a_da_se_potvrdit(): void
    {
        $ucet = $this->penezenka('EUR', 'EUR', 500);
        $telo = ['type' => 'expense', 'occurred_at' => now()->toDateString(), 'wallet_from' => $ucet->uuid, 'amount_from' => 9.9];

        $this->postJson('/api/v1/rozpocet/transakce', $telo)->assertCreated();

        $odpoved = $this->postJson('/api/v1/rozpocet/transakce', $telo)->assertStatus(409);
        $this->assertSame('duplicita', collect($odpoved->json('warnings'))->firstWhere('key', 'duplicita')['key']);

        $this->postJson('/api/v1/rozpocet/transakce', $telo + ['potvrzeno' => true])->assertCreated();
        $this->assertSame(2, Transaction::count());
    }

    /** Smazání směny nejdřív vysvětlí dopad na oba účty. */
    public function test_smazani_smeny_vysvetli_dopad(): void
    {
        $czk = $this->penezenka('CZK', 'CZK', 100000);
        $eur = $this->penezenka('EUR', 'EUR');

        $uuid = $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'exchange', 'occurred_at' => now()->toDateString(),
            'wallet_from' => $czk->uuid, 'wallet_to' => $eur->uuid,
            'amount_from' => 24000, 'amount_to' => 1000,
        ])->json('uuid');

        $odpoved = $this->deleteJson("/api/v1/rozpocet/transakce/{$uuid}")->assertStatus(409);

        $this->assertTrue($odpoved->json('needs_confirmation'));
        $this->assertCount(2, $odpoved->json('impact.wallets'), 'Ruší se obě strany.');
        $this->assertNotNull($odpoved->json('impact.note'));

        $this->deleteJson("/api/v1/rozpocet/transakce/{$uuid}", ['potvrzeno' => true])->assertOk();

        // Zůstatky se vrátily tam, kde byly.
        $prehled = $this->getJson('/api/v1/rozpocet/prehled')->json('wallets');
        $this->assertEqualsWithDelta(100000, collect($prehled)->firstWhere('name', 'CZK')['balance'], 0.001);
        $this->assertEqualsWithDelta(0, collect($prehled)->firstWhere('name', 'EUR')['balance'], 0.001);
    }

    /** Filtr období platí pro všechny části odpovědi zároveň. */
    public function test_filtr_obdobi_plati_pro_cely_prehled(): void
    {
        $ucet = $this->penezenka('EUR', 'EUR', 1000);

        foreach ([['2026-08-05', 100], [now()->toDateString(), 30]] as [$den, $castka]) {
            Transaction::create([
                'gallery_space_id' => $this->space->id, 'type' => 'expense',
                'occurred_at' => $den, 'wallet_from_id' => $ucet->id,
                'amount_from' => $castka, 'currency_from' => 'EUR',
                'created_by' => $this->uzivatel->id, 'state' => 'approved',
            ]);
        }

        $dnes = $this->getJson('/api/v1/rozpocet/prehled?obdobi=dnes')->assertOk();

        $this->assertEqualsWithDelta(30, collect($dnes->json('summary'))->firstWhere('currency', 'EUR')['expense'], 0.001);
        $this->assertEqualsWithDelta(30, collect($dnes->json('categories'))->firstWhere('name', 'Bez kategorie')['amount'], 0.001,
            'Kategorie musí počítat totéž období jako souhrn.');
        $this->assertCount(1, $dnes->json('recent'), 'I poslední aktivita respektuje filtr.');

        // Zůstatek účtu je naopak stav ke dnešku, ne součet za období.
        $this->assertEqualsWithDelta(870, collect($dnes->json('wallets'))->firstWhere('name', 'EUR')['balance'], 0.001);
    }

    /** Cesta má přednost před kalendářem a předvyplňuje se do formuláře. */
    public function test_aktivni_cesta(): void
    {
        $ucet = $this->penezenka('EUR', 'EUR', 2000);

        $cesta = FinanceProject::create([
            'gallery_space_id' => $this->space->id, 'kind' => 'trip', 'name' => 'Německo',
            'country' => 'Německo', 'city' => 'Drážďany',
            'starts_on' => now()->subDays(10)->toDateString(),
            'ends_on' => now()->addDays(20)->toDateString(),
            'base_currency' => 'EUR', 'budget_amount' => 1500, 'reserve_amount' => 100,
            'state' => 'active', 'default_wallet_id' => $ucet->id,
        ]);
        $cesta->aktivuj();

        $ciselniky = $this->getJson('/api/v1/rozpocet/ciselniky')->assertOk();
        $this->assertSame('Německo', $ciselniky->json('active_trip.name'));
        $this->assertSame(20, $ciselniky->json('active_trip.days_left'));

        Transaction::create([
            'gallery_space_id' => $this->space->id, 'type' => 'expense',
            'occurred_at' => now()->toDateString(), 'wallet_from_id' => $ucet->id,
            'amount_from' => 300, 'currency_from' => 'EUR', 'finance_project_id' => $cesta->id,
            'created_by' => $this->uzivatel->id, 'state' => 'approved',
        ]);

        $prehled = $this->getJson('/api/v1/rozpocet/prehled?obdobi=cesta')->assertOk();

        $this->assertSame('Německo', $prehled->json('filter.label'));
        $this->assertEqualsWithDelta(1500, $prehled->json('budget.limit'), 0.001);
        $this->assertEqualsWithDelta(300, $prehled->json('budget.spent'), 0.001);
        $this->assertEqualsWithDelta(1200, $prehled->json('budget.remaining'), 0.001);
        // (1500 − 300 − 100 rezerva) / 21 dní včetně dneška
        $this->assertSame(52.38, $prehled->json('budget.safe_daily.per_day'));
        $this->assertSame(21, $prehled->json('budget.safe_daily.days_left'));
    }

    /** Druhá aktivní cesta zhasne tu první — dvě by tiše dělily výdaje. */
    public function test_aktivni_cesta_je_jen_jedna(): void
    {
        $a = FinanceProject::create(['gallery_space_id' => $this->space->id, 'kind' => 'trip', 'name' => 'První',
            'starts_on' => now()->toDateString(), 'base_currency' => 'EUR', 'state' => 'active']);
        $b = FinanceProject::create(['gallery_space_id' => $this->space->id, 'kind' => 'trip', 'name' => 'Druhá',
            'starts_on' => now()->toDateString(), 'base_currency' => 'EUR', 'state' => 'active']);

        $a->aktivuj();
        $b->aktivuj();

        $this->assertFalse($a->fresh()->is_active);
        $this->assertTrue($b->fresh()->is_active);
    }
}
