<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\FinanceRecurring;
use App\Models\GallerySpace;
use App\Models\Partner;
use App\Models\Transaction;
use App\Models\TransactionShare;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\RecurringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pravidelné platby — nájem se zapíše jednou a chodí sám.
 *
 * Nejcitlivější je, aby splátka vznikla **právě jednou** a **jen do dneška**. Zapsat
 * budoucí nájem předem znamená zůstatek, který neodpovídá bance; zapsat ho dvakrát
 * znamená nájem zaplacený dvakrát. Obojí vypadá věrohodně a nepozná se to.
 */
class FinanceRecurringTest extends TestCase
{
    use RefreshDatabase;

    private User $uzivatel;
    private GallerySpace $space;
    private Wallet $ucet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uzivatel = User::factory()->create();
        $this->space = GallerySpace::create(['name' => 'Zkouška', 'owner_id' => $this->uzivatel->id]);
        $this->uzivatel->gallerySpaces()->syncWithoutDetaching([$this->space->id => ['role' => 'owner']]);
        $this->actingAs($this->uzivatel);

        $this->ucet = Wallet::create([
            'gallery_space_id' => $this->space->id, 'name' => 'EUR karta', 'kind' => 'card',
            'currency' => 'EUR', 'opening_balance' => 3000, 'is_active' => true,
        ]);

        FinanceCategory::nachystej($this->space->id);
    }

    private function najem(string $od, int $den = 1, ?string $do = null): FinanceRecurring
    {
        return FinanceRecurring::create([
            'gallery_space_id' => $this->space->id,
            'name' => 'Nájem', 'type' => 'expense', 'amount' => 280, 'currency' => 'EUR',
            'wallet_id' => $this->ucet->id,
            'finance_category_id' => FinanceCategory::where('gallery_space_id', $this->space->id)
                ->where('name', 'Ubytování')->value('id'),
            'day_of_month' => $den, 'starts_on' => $od, 'ends_on' => $do, 'is_active' => true,
            'created_by' => $this->uzivatel->id,
        ]);
    }

    /** Generuje se jen do dneška — budoucí nájem z účtu ještě neodešel. */
    public function test_generuje_jen_do_dneska(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15'));

        $this->najem('2026-09-01', 1, '2027-02-28');

        $vzniklo = app(RecurringService::class)->generovat($this->space);

        // Září, říjen, listopad — tři splátky. Prosinec až únor ještě ne.
        $this->assertSame(3, $vzniklo);
        $this->assertSame(3, Transaction::count());

        $zustatek = collect(app(\App\Services\Finance\FinanceService::class)
            ->balances($this->space)['wallets'])->firstWhere('name', 'EUR karta')['balance'];

        $this->assertEqualsWithDelta(3000 - 840, $zustatek, 0.001, 'Tři nájmy, ne šest.');

        Carbon::setTestNow();
    }

    /** Opakované spuštění nepřidá nic — splátka vzniká právě jednou. */
    public function test_opakovane_spusteni_nezdvoji(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15'));

        $this->najem('2026-09-01');

        $sluzba = app(RecurringService::class);
        $sluzba->generovat($this->space);
        $sluzba->generovat($this->space);
        $sluzba->generovat($this->space);

        $this->assertSame(3, Transaction::count(), 'Tři spuštění, pořád tři splátky.');

        Carbon::setTestNow();
    }

    /** Den 31 v kratším měsíci padne na konec, ne do dalšího měsíce. */
    public function test_den_31_v_kratsim_mesici(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-05'));

        $this->najem('2027-01-01', 31);
        app(RecurringService::class)->generovat($this->space);

        $dny = Transaction::orderBy('occurred_at')->pluck('occurred_at')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->all();

        $this->assertSame(['2027-01-31', '2027-02-28'], $dny,
            'Únorová splátka je poslední únorový den, ne třetího března.');

        Carbon::setTestNow();
    }

    /** Předpis založený zpětně dopíše i to, co uteklo. */
    public function test_zpetne_zalozeny_predpis(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-20'));

        $this->postJson('/api/v1/rozpocet/pravidelne', [
            'name' => 'Nájem', 'amount' => 280,
            'wallet_uuid' => $this->ucet->uuid,
            'day_of_month' => 1, 'starts_on' => '2026-09-01', 'ends_on' => '2027-02-28',
        ])->assertCreated();

        $this->assertSame(3, Transaction::count(), 'Září až listopad se dopíše hned.');

        Carbon::setTestNow();
    }

    /** Závazky: co ještě přijde do konce, se hlásí zvlášť a nezapisuje se. */
    public function test_zavazky_do_konce(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15'));

        $this->najem('2026-09-01', 1, '2027-02-28');
        app(RecurringService::class)->generovat($this->space);

        $zavazky = app(RecurringService::class)
            ->zavazky($this->space, 'EUR', Carbon::parse('2027-02-28'));

        // Prosinec, leden, únor — tři nájmy po 280.
        $this->assertEqualsWithDelta(840, $zavazky['total'], 0.001);
        $this->assertSame(3, $zavazky['items'][0]['times']);
        $this->assertSame('2026-12-01', $zavazky['items'][0]['next_on']);

        // A pořád jsou zapsané jen tři splátky — závazek není transakce.
        $this->assertSame(3, Transaction::count());

        Carbon::setTestNow();
    }

    /** Rozdělení mezi partnery se přenese z předpisu. */
    public function test_rozdeleni_z_predpisu(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $adri = Partner::create(['gallery_space_id' => $this->space->id, 'kind' => 'person', 'name' => 'Adri', 'is_active' => true]);
        $maki = Partner::create(['gallery_space_id' => $this->space->id, 'kind' => 'person', 'name' => 'Maki', 'is_active' => true]);

        $p = $this->najem('2026-09-01');
        $p->update(['split' => 'equal']);

        app(RecurringService::class)->generovat($this->space);

        $podily = TransactionShare::all();

        $this->assertCount(2, $podily);
        $this->assertEqualsWithDelta(140, $podily->firstWhere('partner_id', $adri->id)->amount, 0.001);
        $this->assertEqualsWithDelta(140, $podily->firstWhere('partner_id', $maki->id)->amount, 0.001);

        Carbon::setTestNow();
    }

    /** Smazání předpisu nechá zapsané splátky být — peníze opravdu odešly. */
    public function test_smazani_predpisu_nechava_splatky(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15'));

        $p = $this->najem('2026-09-01');
        app(RecurringService::class)->generovat($this->space);

        $odpoved = $this->deleteJson("/api/v1/rozpocet/pravidelne/{$p->uuid}")->assertOk();

        $this->assertSame(3, $odpoved->json('kept'));
        $this->assertSame(3, Transaction::count(), 'Splátky zůstávají.');
        $this->assertSame([], $odpoved->json('recurring'));

        Carbon::setTestNow();
    }

    /** Vypnutý předpis přestane generovat, zapsané zůstanou. */
    public function test_vypnuty_predpis_negeneruje(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15'));

        $p = $this->najem('2026-09-01');
        app(RecurringService::class)->generovat($this->space);
        $this->assertSame(2, Transaction::count());

        $this->patchJson("/api/v1/rozpocet/pravidelne/{$p->uuid}", ['is_active' => false])->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-12-15'));
        app(RecurringService::class)->generovat($this->space);

        $this->assertSame(2, Transaction::count(), 'Vypnutý předpis nepřidal listopad ani prosinec.');

        Carbon::setTestNow();
    }

    /** Měna se bere z účtu, ne z formuláře. */
    public function test_mena_z_uctu(): void
    {
        $czk = Wallet::create(['gallery_space_id' => $this->space->id, 'name' => 'CZK', 'kind' => 'bank',
            'currency' => 'CZK', 'opening_balance' => 50000, 'is_active' => true]);

        $this->postJson('/api/v1/rozpocet/pravidelne', [
            'name' => 'Telefon', 'amount' => 500,
            'wallet_uuid' => $czk->uuid, 'day_of_month' => 15,
            'starts_on' => now()->toDateString(),
        ])->assertCreated();

        $this->assertSame('CZK', FinanceRecurring::where('name', 'Telefon')->value('currency'));
    }
}
