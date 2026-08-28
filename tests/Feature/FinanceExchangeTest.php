<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tab Směny.
 *
 * Podstatné je porovnání poskytovatelů. Reklamní kurz o výsledku nevypovídá —
 * poskytovatel s lepším kurzem a vyšším poplatkem vyjde hůř. Měří se proto, kolik eur
 * doopravdy přišlo, a závěr se nedělá z jediné směny.
 */
class FinanceExchangeTest extends TestCase
{
    use RefreshDatabase;

    private User $uzivatel;
    private GallerySpace $space;
    private Wallet $czk;
    private Wallet $eur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uzivatel = User::factory()->create();
        $this->space = GallerySpace::create(['name' => 'Zkouška', 'owner_id' => $this->uzivatel->id]);
        $this->uzivatel->gallerySpaces()->syncWithoutDetaching([$this->space->id => ['role' => 'owner']]);
        $this->actingAs($this->uzivatel);

        $this->czk = $this->ucet('CZK účet', 'CZK', 500000);
        $this->eur = $this->ucet('EUR účet', 'EUR');
    }

    private function ucet(string $jmeno, string $mena, float $pocatek = 0): Wallet
    {
        return Wallet::create([
            'gallery_space_id' => $this->space->id, 'name' => $jmeno, 'kind' => 'bank',
            'currency' => $mena, 'opening_balance' => $pocatek, 'is_active' => true,
        ]);
    }

    private function smena(string $den, float $czk, float $eur, ?string $poskytovatel = null, float $poplatek = 0): Transaction
    {
        return Transaction::create([
            'gallery_space_id' => $this->space->id, 'type' => 'exchange',
            'occurred_at' => $den,
            'wallet_from_id' => $this->czk->id, 'wallet_to_id' => $this->eur->id,
            'amount_from' => $czk, 'currency_from' => 'CZK',
            'amount_to' => $eur, 'currency_to' => 'EUR',
            'fee_amount' => $poplatek, 'fee_currency' => 'CZK', 'fee_included' => false,
            'provider' => $poskytovatel, 'state' => 'approved', 'created_by' => $this->uzivatel->id,
        ]);
    }

    /** Lepší kurz s vyšším poplatkem může dopadnout hůř — a musí to být vidět. */
    public function test_poskytovatel_s_lepsim_kurzem_ale_vyssim_poplatkem_vyjde_hur(): void
    {
        // Revolut: kurz 24,00, ale poplatek 500 → skutečně 24,50
        $this->smena('2026-08-01', 24000, 1000, 'Revolut', 500);
        $this->smena('2026-08-05', 24000, 1000, 'Revolut', 500);

        // Směnárna: horší nabízený kurz 24,20, ale bez poplatku → skutečně 24,20
        $this->smena('2026-08-02', 24200, 1000, 'Směnárna');
        $this->smena('2026-08-06', 24200, 1000, 'Směnárna');

        $odpoved = $this->getJson('/api/v1/rozpocet/smeny')->assertOk();
        $poskytovatele = collect($odpoved->json('providers'));

        $revolut = $poskytovatele->firstWhere('name', 'Revolut');
        $smenarna = $poskytovatele->firstWhere('name', 'Směnárna');

        $this->assertEqualsWithDelta(24.5, $revolut['average_rate'], 0.001, 'Poplatek se musí promítnout do kurzu.');
        $this->assertEqualsWithDelta(24.2, $smenarna['average_rate'], 0.001);

        // Ten s hezčím reklamním kurzem je ve skutečnosti horší.
        $this->assertTrue($smenarna['is_best']);
        $this->assertTrue($revolut['is_worst']);
    }

    /** Z jedné směny se závěr nedělá. */
    public function test_z_jedne_smeny_se_nedela_zaver(): void
    {
        $this->smena('2026-08-01', 23000, 1000, 'Náhoda');   // výborný kurz
        $this->smena('2026-08-02', 25000, 1000, 'Banka');
        $this->smena('2026-08-03', 25000, 1000, 'Banka');

        $poskytovatele = collect($this->getJson('/api/v1/rozpocet/smeny')->json('providers'));

        $nahoda = $poskytovatele->firstWhere('name', 'Náhoda');

        $this->assertFalse($nahoda['comparable'], 'Jedna směna není základ pro porovnání.');
        $this->assertFalse($nahoda['is_best'], 'A nesmí se označit za nejlepší.');
    }

    /** Průměr je vážený objemem, ne prostý průměr kurzů. */
    public function test_prumer_je_vazeny_objemem(): void
    {
        // 100 € za 30,00 a 1000 € za 24,00. Prostý průměr by dal 27, vážený 24,55.
        $this->smena('2026-08-01', 3000, 100, 'Banka');
        $this->smena('2026-08-02', 24000, 1000, 'Banka');

        $banka = collect($this->getJson('/api/v1/rozpocet/smeny')->json('providers'))->firstWhere('name', 'Banka');

        $this->assertEqualsWithDelta(24.55, $banka['average_rate'], 0.01,
            'Malá směna nesmí vážit stejně jako desetkrát větší.');
    }

    /** KPI se počítají z celé historie, objem a poplatky ze zvoleného období. */
    public function test_kpi_z_historie_objem_z_obdobi(): void
    {
        $this->smena('2026-06-10', 24000, 1000, 'Banka', 100);
        $this->smena(now()->toDateString(), 26000, 1000, 'Banka', 200);

        $odpoved = $this->getJson('/api/v1/rozpocet/smeny?obdobi=dnes')->assertOk();

        // Držená eura a průměrný kurz z obojího.
        $this->assertEqualsWithDelta(2000, $odpoved->json('acquisition.held_eur'), 0.001);
        $this->assertEqualsWithDelta(25.15, $odpoved->json('acquisition.average_rate'), 0.01);

        // Objem a poplatky jen za dnešek.
        $this->assertEqualsWithDelta(26000, collect($odpoved->json('period_volume'))->firstWhere('currency', 'CZK')['amount'], 0.001);
        $this->assertEqualsWithDelta(200, collect($odpoved->json('period_fees'))->firstWhere('currency', 'CZK')['amount'], 0.001);
    }

    /** Seznam vrací i vypočtený kurz u každé směny, od nejnovější. */
    public function test_seznam_smen(): void
    {
        $this->smena('2026-08-01', 24000, 1000, 'Revolut', 120);
        $this->smena('2026-08-10', 25000, 1000, 'Banka');

        $smeny = collect($this->getJson('/api/v1/rozpocet/smeny')->json('exchanges'));

        $this->assertSame('2026-08-10', $smeny->first()['occurred_at'], 'Nejnovější nahoře.');
        $this->assertEqualsWithDelta(24.12, $smeny->last()['rate']['effective'], 0.01);
        $this->assertEqualsWithDelta(24.0, $smeny->last()['rate']['nominal'], 0.01);
    }

    public function test_prazdna_historie(): void
    {
        $odpoved = $this->getJson('/api/v1/rozpocet/smeny')->assertOk();

        $this->assertSame(0, $odpoved->json('count'));
        $this->assertSame([], $odpoved->json('exchanges'));
        $this->assertNull($odpoved->json('acquisition.average_rate'));
    }
}
