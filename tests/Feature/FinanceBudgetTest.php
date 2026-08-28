<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\FinanceCategory;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Rozpočty modulu.
 *
 * Rozpočet je strop nad knihou, ne vlastní evidence. Testy míří na to, kde se čerpání
 * dá spočítat špatně tak, že to vypadá věrohodně: měsíční limit proti půlročním
 * útratám, směna započítaná jako výdaj, vrácené peníze, které rozpočet zatíží navždy.
 */
class FinanceBudgetTest extends TestCase
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
            'gallery_space_id' => $this->space->id, 'name' => 'EUR', 'kind' => 'card',
            'currency' => 'EUR', 'opening_balance' => 10000, 'is_active' => true,
        ]);

        FinanceCategory::nachystej($this->space->id);
    }

    private function vydaj(float $castka, string $den, ?string $kategorie = null): Transaction
    {
        $k = $kategorie
            ? FinanceCategory::where('gallery_space_id', $this->space->id)->where('name', $kategorie)->first()
            : null;

        return Transaction::create([
            'gallery_space_id' => $this->space->id, 'type' => 'expense',
            'occurred_at' => $den, 'wallet_from_id' => $this->ucet->id,
            'amount_from' => $castka, 'currency_from' => 'EUR',
            'category_id' => $k?->id, 'state' => 'approved', 'created_by' => $this->uzivatel->id,
        ]);
    }

    private function mesicni(float $limit, float $rezerva = 0): string
    {
        return $this->postJson('/api/v1/rozpocet/rozpocty', [
            'name' => 'Měsíční', 'budget_kind' => 'monthly', 'currency' => 'EUR',
            'amount' => $limit, 'reserve_amount' => $rezerva,
        ])->assertCreated()->json('budget.uuid');
    }

    /** Měsíční rozpočet měří aktuální měsíc, ne všechno od založení. */
    public function test_mesicni_rozpocet_meri_jen_tento_mesic(): void
    {
        $this->vydaj(300, Carbon::today()->subMonths(3)->toDateString());
        $this->vydaj(120, Carbon::today()->toDateString());

        $this->mesicni(500);

        $r = collect($this->getJson('/api/v1/rozpocet/rozpocty')->json('budgets'))->first();

        $this->assertEqualsWithDelta(120, $r['spent'], 0.001, 'Útraty z minulých měsíců se nepočítají.');
        $this->assertEqualsWithDelta(380, $r['remaining'], 0.001);
        $this->assertSame(24, $r['percent']);
        $this->assertSame(Carbon::today()->startOfMonth()->toDateString(), $r['starts_on']);
    }

    /** Směna ani převod čerpání nezvýší — jen poplatek. */
    public function test_smena_necerpa_rozpocet(): void
    {
        $czk = Wallet::create(['gallery_space_id' => $this->space->id, 'name' => 'CZK', 'kind' => 'bank',
            'currency' => 'CZK', 'opening_balance' => 100000, 'is_active' => true]);

        Transaction::create([
            'gallery_space_id' => $this->space->id, 'type' => 'exchange',
            'occurred_at' => Carbon::today()->toDateString(),
            'wallet_from_id' => $czk->id, 'wallet_to_id' => $this->ucet->id,
            'amount_from' => 24000, 'currency_from' => 'CZK',
            'amount_to' => 1000, 'currency_to' => 'EUR',
            'fee_amount' => 5, 'fee_currency' => 'EUR', 'fee_included' => false,
            'state' => 'approved', 'created_by' => $this->uzivatel->id,
        ]);

        $this->mesicni(500);
        $r = collect($this->getJson('/api/v1/rozpocet/rozpocty')->json('budgets'))->first();

        // Tisíc eur přibylo, ale nikdo je neutratil. Do čerpání jde jen poplatek.
        $this->assertEqualsWithDelta(5, $r['spent'], 0.001);
    }

    /** Vrácené peníze čerpání sníží. */
    public function test_refundace_snizi_cerpani(): void
    {
        $nakup = $this->vydaj(200, Carbon::today()->toDateString(), 'Oblečení a nákupy');

        Transaction::create([
            'gallery_space_id' => $this->space->id, 'type' => 'income',
            'occurred_at' => Carbon::today()->toDateString(),
            'wallet_to_id' => $this->ucet->id,
            'amount_to' => 80, 'currency_to' => 'EUR', 'currency_from' => 'EUR',
            'refund_of_id' => $nakup->id, 'state' => 'approved', 'created_by' => $this->uzivatel->id,
        ]);

        $this->mesicni(500);
        $r = collect($this->getJson('/api/v1/rozpocet/rozpocty')->json('budgets'))->first();

        $this->assertEqualsWithDelta(120, $r['spent'], 0.001, 'Reklamovaný nákup nesmí zatížit rozpočet navždy.');
        $this->assertEqualsWithDelta(80, $r['refunded'], 0.001);
    }

    /** Limity kategorií se ukazují i bez útrat — jinak by vypadaly jako neuložené. */
    public function test_limity_kategorii(): void
    {
        $potraviny = FinanceCategory::where('gallery_space_id', $this->space->id)->where('name', 'Potraviny')->first();
        $doprava = FinanceCategory::where('gallery_space_id', $this->space->id)->where('name', 'Doprava')->first();

        $this->vydaj(90, Carbon::today()->toDateString(), 'Potraviny');

        $this->postJson('/api/v1/rozpocet/rozpocty', [
            'name' => 'Měsíční', 'budget_kind' => 'monthly', 'currency' => 'EUR', 'amount' => 500,
            'limits' => [
                ['category_uuid' => $potraviny->uuid, 'amount' => 150],
                ['category_uuid' => $doprava->uuid, 'amount' => 60],
            ],
        ])->assertCreated();

        $kategorie = collect(collect($this->getJson('/api/v1/rozpocet/rozpocty')->json('budgets'))->first()['categories']);

        $this->assertCount(2, $kategorie);

        $p = $kategorie->firstWhere('name', 'Potraviny');
        $this->assertEqualsWithDelta(90, $p['spent'], 0.001);
        $this->assertEqualsWithDelta(60, $p['remaining'], 0.001);
        $this->assertSame(60, $p['percent']);

        $d = $kategorie->firstWhere('name', 'Doprava');
        $this->assertEqualsWithDelta(0, $d['spent'], 0.001, 'Limit bez útrat musí být pořád vidět.');
    }

    /** Hranice upozornění hlásí tu nejvyšší překročenou. */
    public function test_hranice_upozorneni(): void
    {
        $this->vydaj(460, Carbon::today()->toDateString());
        $this->mesicni(500);

        $r = collect($this->getJson('/api/v1/rozpocet/rozpocty')->json('budgets'))->first();

        $this->assertSame(92, $r['percent']);
        $this->assertSame(90, $r['alert'], 'Při 92 % platí hranice 90, ne 80.');
    }

    /** Rezerva se odečte z denní částky, ale ne ze zbývajícího. */
    public function test_rezerva_ovlivni_denni_castku(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01'));

        $this->vydaj(100, '2026-08-01');
        $this->mesicni(500, 100);

        $r = collect($this->getJson('/api/v1/rozpocet/rozpocty')->json('budgets'))->first();

        $this->assertEqualsWithDelta(400, $r['remaining'], 0.001, 'Rezerva zbývající částku nesnižuje.');
        // (500 − 100 utraceno − 100 rezerva) / 31 dní
        $this->assertEqualsWithDelta(9.68, $r['safe_daily']['per_day'], 0.01);

        Carbon::setTestNow();
    }

    /** Odhad konce se nedělá z pár dní. */
    public function test_odhad_az_kdyz_je_z_ceho(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-02'));
        $this->vydaj(100, '2026-08-01');
        $uuid = $this->mesicni(500);

        $r = collect($this->getJson('/api/v1/rozpocet/rozpocty')->json('budgets'))->first();
        $this->assertNull($r['projected_total'], 'Ze dvou dnů se celý měsíc odhadovat nedá.');
        $this->assertSame('unknown', $r['projected_verdict']);

        // Po deseti dnech už ano — a tempo 20/den na 31 dní znamená překročení.
        Carbon::setTestNow(Carbon::parse('2026-08-10'));
        $r = collect($this->getJson('/api/v1/rozpocet/rozpocty')->json('budgets'))->first();
        $this->assertNotNull($r['projected_total']);
        $this->assertSame('ok', $r['projected_verdict']);

        Carbon::setTestNow();
    }

    public function test_rezerva_nesmi_prevysit_rozpocet(): void
    {
        $this->postJson('/api/v1/rozpocet/rozpocty', [
            'name' => 'Nesmysl', 'budget_kind' => 'monthly', 'currency' => 'EUR',
            'amount' => 300, 'reserve_amount' => 500,
        ])->assertStatus(422);

        $this->assertSame(0, Budget::count());
    }

    /** Smazání rozpočtu nechá transakce být — je to jen strop. */
    public function test_smazani_rozpoctu_nechá_transakce(): void
    {
        $this->vydaj(50, Carbon::today()->toDateString());
        $uuid = $this->mesicni(500);

        $this->deleteJson("/api/v1/rozpocet/rozpocty/{$uuid}")->assertOk();

        $this->assertSame(0, Budget::count());
        $this->assertSame(1, Transaction::count());
    }
}
