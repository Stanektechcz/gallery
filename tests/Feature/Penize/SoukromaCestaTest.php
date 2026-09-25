<?php

namespace Tests\Feature\Penize;

use App\Models\FinanceAccess;
use App\Models\FinanceProject;
use App\Models\GallerySpace;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Soukromá cesta jednoho z dvojice — druhý ji nevidí nikde, ne jen v seznamu.
 *
 * Seznam cest ji skrýval, ale detail, shrnutí, filtr `cesta` i „aktivní cesta" na
 * přehledu ji podle uuid vydaly komukoli z prostoru.
 */
class SoukromaCestaTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private User $maki;

    private GallerySpace $space;

    private FinanceProject $cesta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->maki = User::factory()->create(['name' => 'Maki']);
        $this->adri = User::factory()->create(['name' => 'Adri']);

        // Prostor založil třetí člověk: majitel prostoru smí i do cizí cesty zapisovat
        // (`FinanceAccess::smiUpravit`), na něm by se čtení nedalo ověřit.
        $zakladatel = User::factory()->create();
        $this->space = GallerySpace::create(['name' => 'Zkouška', 'owner_id' => $zakladatel->id]);

        foreach ([$this->adri, $this->maki] as $u) {
            $u->gallerySpaces()->syncWithoutDetaching([$this->space->id => ['role' => 'owner']]);
        }

        $ucet = Wallet::create([
            'gallery_space_id' => $this->space->id, 'name' => 'EUR', 'kind' => 'bank',
            'currency' => 'EUR', 'opening_balance' => 1000, 'is_active' => true,
        ]);

        $this->cesta = FinanceProject::create([
            'gallery_space_id' => $this->space->id, 'kind' => 'trip', 'name' => 'Makinčino Německo',
            'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31', 'base_currency' => 'EUR',
            'budget_amount' => 3000, 'is_active' => true, 'owner_user_id' => $this->maki->id,
        ]);

        Transaction::create([
            'gallery_space_id' => $this->space->id, 'type' => 'expense', 'occurred_at' => '2026-09-10',
            'wallet_from_id' => $ucet->id, 'amount_from' => 40, 'currency_from' => 'EUR',
            'finance_project_id' => $this->cesta->id, 'state' => 'approved', 'created_by' => $this->maki->id,
        ]);
    }

    public function test_druhy_z_dvojice_soukromou_cestu_neotevre(): void
    {
        $this->actingAs($this->adri);

        $this->getJson("/api/v1/rozpocet/cesty/{$this->cesta->uuid}/detail")->assertNotFound();
        $this->getJson("/api/v1/rozpocet/cesty/{$this->cesta->uuid}/shrnuti")->assertNotFound();
    }

    public function test_soukroma_cesta_neprosakuje_pres_filtr_ani_prehled(): void
    {
        $this->actingAs($this->adri);

        $prehled = $this->getJson("/api/v1/rozpocet/prehled?obdobi=cesta&cesta={$this->cesta->uuid}")->assertOk();
        $this->assertNull($prehled->json('filter.trip'), 'Filtr cizí soukromou cestu nenačte.');
        $this->assertNull($prehled->json('active_trip'), 'Ani jako aktivní cestu.');
        $this->assertNull($prehled->json('budget.name'), 'Ani její rozpočet.');

        $this->assertNull($this->getJson('/api/v1/rozpocet/ciselniky')->assertOk()->json('active_trip'));

        // Seznam podle neviditelné cesty je prázdný, ne „všechno bez filtru".
        $this->assertSame(0, $this->getJson("/api/v1/rozpocet/transakce?obdobi=vlastni&od=2026-09-01&do=2026-09-30&cesta={$this->cesta->uuid}")
            ->assertOk()->json('found'));
    }

    public function test_vlastnik_a_ten_s_pristupem_ji_vidi(): void
    {
        $this->actingAs($this->maki);
        $this->getJson("/api/v1/rozpocet/cesty/{$this->cesta->uuid}/detail")->assertOk();

        FinanceAccess::create([
            'gallery_space_id' => $this->space->id, 'subject_type' => 'trip',
            'subject_id' => $this->cesta->id, 'user_id' => $this->adri->id, 'can_edit' => false,
        ]);

        $this->actingAs($this->adri);
        $this->getJson("/api/v1/rozpocet/cesty/{$this->cesta->uuid}/detail")->assertOk();
        $this->getJson("/api/v1/rozpocet/cesty/{$this->cesta->uuid}/shrnuti")->assertOk();
        $this->assertSame($this->cesta->uuid, $this->getJson('/api/v1/rozpocet/ciselniky')->json('active_trip.uuid'));
    }
}
