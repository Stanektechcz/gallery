<?php

namespace Tests\Feature\Penize;

use App\Models\GallerySpace;
use App\Models\Partner;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\FinanceService;
use App\Services\Finance\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saldo partnerů a poplatky v zůstatcích.
 *
 * Tři místa, kde se dalo spočítat špatně tak, že to vypadalo věrohodně: výdaj ze
 * společného účtu schoval skutečný dluh, galerie ukazovala jiný zůstatek než Rozpočet
 * a poplatek v eurech se odečetl z korunového účtu.
 */
class SaldoAPoplatkyTest extends TestCase
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

    private function ucet(string $jmeno, string $mena, float $pocatek = 0, ?int $partner = null): Wallet
    {
        return Wallet::create([
            'gallery_space_id' => $this->space->id, 'name' => $jmeno, 'kind' => 'bank',
            'currency' => $mena, 'opening_balance' => $pocatek, 'partner_id' => $partner, 'is_active' => true,
        ]);
    }

    private function partner(string $jmeno): Partner
    {
        return Partner::create(['gallery_space_id' => $this->space->id, 'kind' => 'person', 'name' => $jmeno, 'is_active' => true]);
    }

    private function zustatky(): array
    {
        return collect(app(FinanceService::class)->balances($this->space)['wallets'])
            ->mapWithKeys(fn (array $r) => [$r['name'] => $r['balance']])->all();
    }

    public function test_vydaj_ze_spolecneho_uctu_neschova_osobni_dluh(): void
    {
        $adri = $this->partner('Adri');
        $maki = $this->partner('Maki');
        $ucetAdri = $this->ucet('Adri CZK', 'CZK', 5000, $adri->id);
        $spolecny = $this->ucet('Společný CZK', 'CZK', 5000);

        // Adri zaplatil ze svého sto korun za oba; tisíc šlo ze společného účtu.
        $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'expense', 'occurred_at' => '2026-09-10',
            'wallet_from' => $ucetAdri->uuid, 'amount_from' => 100,
        ])->assertCreated();
        $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'expense', 'occurred_at' => '2026-09-10',
            'wallet_from' => $spolecny->uuid, 'amount_from' => 1000,
        ])->assertCreated();

        $pohyby = Transaction::with(['walletFrom', 'walletTo', 'shares'])->get();
        $saldo = app(FinanceService::class)->partnerBalance($pohyby, collect([$adri, $maki]));
        $czk = collect($saldo['by_currency'])->firstWhere('currency', 'CZK');

        $this->assertSame([[
            'from' => 'Maki', 'from_id' => $maki->id, 'to' => 'Adri', 'to_id' => $adri->id,
            'amount' => 50.0, 'currency' => 'CZK',
        ]], $czk['settlement'], 'Maki dluží půlku ze stovky; společný tisíc na tom nic nemění.');
    }

    public function test_zahrnuty_poplatek_galerie_neodecita_podruhe(): void
    {
        $czk = $this->ucet('CZK', 'CZK', 30000);
        $eur = $this->ucet('EUR', 'EUR', 0);

        Transaction::create([
            'gallery_space_id' => $this->space->id, 'type' => 'exchange', 'occurred_at' => '2026-09-10',
            'wallet_from_id' => $czk->id, 'wallet_to_id' => $eur->id,
            'amount_from' => 1000, 'currency_from' => 'CZK', 'amount_to' => 40, 'currency_to' => 'EUR',
            'fee_amount' => 20, 'fee_currency' => 'CZK', 'fee_included' => true, 'state' => 'approved',
            'created_by' => $this->uzivatel->id,
        ]);

        $galerie = app(LedgerService::class)->walletBalances($this->space)
            ->mapWithKeys(fn (array $r) => [$r['name'] => $r['balance']])->all();

        $this->assertEquals($this->zustatky(), $galerie, 'Galerie a Rozpočet ukazují týž zůstatek.');
        $this->assertEqualsWithDelta(29000, $galerie['CZK'], 0.001, 'Zahrnutý poplatek je už v tisícovce.');
    }

    public function test_poplatek_v_cilove_mene_jde_z_ciloveho_uctu(): void
    {
        $czk = $this->ucet('CZK', 'CZK', 30000);
        $eur = $this->ucet('EUR', 'EUR', 0);

        $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'exchange', 'occurred_at' => '2026-09-10',
            'wallet_from' => $czk->uuid, 'wallet_to' => $eur->uuid,
            'amount_from' => 2500, 'amount_to' => 100,
            'fee_amount' => 2, 'fee_currency' => 'EUR', 'fee_included' => false,
            'potvrzeno' => true,
        ])->assertCreated();

        $rozpocet = $this->zustatky();
        $this->assertEqualsWithDelta(27500, $rozpocet['CZK'], 0.001, 'Z korun odešlo jen 2500.');
        $this->assertEqualsWithDelta(98, $rozpocet['EUR'], 0.001, 'Dvě eura poplatku si banka vzala z eur.');

        $galerie = app(LedgerService::class)->walletBalances($this->space)
            ->mapWithKeys(fn (array $r) => [$r['name'] => $r['balance']])->all();
        $this->assertEquals($rozpocet, $galerie);
    }

    public function test_poplatek_v_cizi_mene_se_odmitne(): void
    {
        $czk = $this->ucet('CZK', 'CZK', 30000);
        $eur = $this->ucet('EUR', 'EUR', 0);

        $this->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'exchange', 'occurred_at' => '2026-09-10',
            'wallet_from' => $czk->uuid, 'wallet_to' => $eur->uuid,
            'amount_from' => 2500, 'amount_to' => 100,
            'fee_amount' => 2, 'fee_currency' => 'USD',
            'potvrzeno' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('fee_currency');

        $this->assertSame(0, Transaction::count());
    }
}
