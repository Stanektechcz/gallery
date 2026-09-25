<?php

namespace Tests\Feature\Penize;

use App\Models\GallerySpace;
use App\Models\Partner;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Poplatek v tom, kolik kdo zaplatil.
 *
 * `partnerPositions()` přičítal poplatek k výdaji vždycky a v měně výdaje. Poplatek
 * zahrnutý v částce se tak zaplatil dvakrát, a dvě eura za platbu v korunách se
 * přičetla jako dvě koruny — dluhy se přitom vedou po měnách a mezi nimi se nepřevádí.
 */
class PoplatekVPozicichPartneruTest extends TestCase
{
    use RefreshDatabase;

    private GallerySpace $space;

    private Partner $adrian;

    private User $uzivatel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uzivatel = $uzivatel = User::factory()->create();
        $this->space = GallerySpace::create(['name' => 'Zkouška', 'owner_id' => $uzivatel->id]);
        $uzivatel->gallerySpaces()->syncWithoutDetaching([$this->space->id => ['role' => 'owner']]);
        $this->actingAs($uzivatel);
        $this->adrian = Partner::create(['gallery_space_id' => $this->space->id, 'kind' => 'person', 'name' => 'Adrian', 'is_active' => true]);
    }

    public function test_zahrnuty_poplatek_se_nepricita_podruhe(): void
    {
        $this->vydaj(['amount_from' => 1000, 'currency_from' => 'CZK', 'fee_amount' => 50, 'fee_currency' => 'CZK', 'fee_included' => true]);

        $this->assertSame(['CZK' => ['paid' => 1000.0, 'should_bear' => 1000.0]], $this->pozice());
    }

    public function test_poplatek_v_jine_mene_se_vede_v_ni(): void
    {
        $this->vydaj(['amount_from' => 1000, 'currency_from' => 'CZK', 'fee_amount' => 2, 'fee_currency' => 'EUR', 'fee_included' => false]);

        $this->assertSame([
            'CZK' => ['paid' => 1000.0, 'should_bear' => 1000.0],
            'EUR' => ['paid' => 2.0, 'should_bear' => 2.0],
        ], $this->pozice());
    }

    /** @param  array<string, mixed>  $navic */
    private function vydaj(array $navic): void
    {
        Transaction::create(array_merge([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->uzivatel->id, 'type' => 'expense', 'state' => 'approved',
            'occurred_at' => now()->toDateString(), 'payer_partner_id' => $this->adrian->id,
        ], $navic));
    }

    /** @return array<string, array{paid: float, should_bear: float}> */
    private function pozice(): array
    {
        $partner = collect(app(LedgerService::class)->partnerPositions($this->space)['partners'])->firstWhere('partner_id', $this->adrian->id);

        return collect($partner['currencies'])
            ->mapWithKeys(fn (array $r) => [$r['currency'] => ['paid' => (float) $r['paid'], 'should_bear' => (float) $r['should_bear']]])
            ->all();
    }
}
