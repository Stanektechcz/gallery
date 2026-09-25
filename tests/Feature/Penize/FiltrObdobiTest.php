<?php

namespace Tests\Feature\Penize;

use App\Models\FinanceRecurring;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Filtr období — „dnes" podle pražských hodin a vlastní rozsah jen rozumný.
 *
 * Server běží v UTC. Mezi pražskou půlnocí a druhou ráno měl přehled ještě včerejšek
 * a útrata zapsaná po půlnoci prvního nebyla ani v „dnes", ani v „tomto měsíci".
 */
class FiltrObdobiTest extends TestCase
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
            'gallery_space_id' => $this->space->id, 'name' => 'CZK', 'kind' => 'bank',
            'currency' => 'CZK', 'opening_balance' => 10000, 'is_active' => true,
        ]);
    }

    public function test_po_prazske_pulnoci_je_dnes_uz_novy_den(): void
    {
        // 22:30 UTC = 0:30 prvního října v Praze.
        $this->travelTo(Carbon::parse('2026-09-30 22:30:00', 'UTC'));

        Transaction::create([
            'gallery_space_id' => $this->space->id, 'type' => 'expense', 'occurred_at' => '2026-10-01',
            'wallet_from_id' => $this->ucet->id, 'amount_from' => 120, 'currency_from' => 'CZK',
            'state' => 'approved', 'created_by' => $this->uzivatel->id,
        ]);

        $prehled = $this->getJson('/api/v1/rozpocet/prehled')->assertOk();

        $this->assertSame('2026-10-01', $prehled->json('filter.from'), 'Tento měsíc je říjen.');
        $this->assertEqualsWithDelta(120, $prehled->json('today.spent'), 0.001, 'Útrata po půlnoci je dnešní.');
        $this->assertSame(1, $prehled->json('today.count'));
    }

    public function test_po_prazske_pulnoci_vznikne_splatka_noveho_mesice(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 22:30:00', 'UTC'));

        $p = FinanceRecurring::create([
            'gallery_space_id' => $this->space->id, 'name' => 'Nájem', 'type' => 'expense',
            'amount' => 9000, 'currency' => 'CZK', 'wallet_id' => $this->ucet->id,
            'day_of_month' => 1, 'starts_on' => '2026-10-01', 'is_active' => true,
            'created_by' => $this->uzivatel->id,
        ]);

        $this->getJson('/api/v1/rozpocet/prehled')->assertOk();

        $this->assertTrue(Transaction::where('recurring_id', $p->id)->whereDate('occurred_at', '2026-10-01')->exists(),
            'V Praze je prvního — nájem z prvního už odešel.');
    }

    public function test_nesmyslne_vlastni_obdobi_se_odmitne(): void
    {
        $this->getJson('/api/v1/rozpocet/prehled?obdobi=vlastni&od=abc')->assertStatus(422);
        $this->getJson('/api/v1/rozpocet/statistiky?obdobi=vlastni&od=0001-01-01&do=9999-12-31')->assertStatus(422);
        $this->getJson('/api/v1/rozpocet/prehled?obdobi=vlastni&od=2026-09-10&do=2026-09-01')->assertStatus(422);
        $this->getJson('/api/v1/rozpocet/transakce?typ[]=expense')->assertStatus(422);
        $this->getJson('/api/v1/rozpocet/prehled?mena[a]=CZK')->assertStatus(422);

        // Rozumný vlastní rozsah projde.
        $this->getJson('/api/v1/rozpocet/prehled?obdobi=vlastni&od=2026-09-01&do=2026-09-30')
            ->assertOk()->assertJsonCount(30, 'daily');
    }
}
