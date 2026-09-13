<?php

namespace Tests\Feature\Billing;

use App\Models\GallerySpace;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Zaplaceno je jen to, co brána potvrdí **pro tuhle platbu**.
 *
 * Notifikace smí platbě doplnit `transId`; kdyby se jen zeptalo „je ta
 * transakce zaplacená?", odemkla by drahý tarif i cizí nebo levnější platba.
 */
class PlatbaOvereniTest extends TestCase
{
    use RefreshDatabase;

    private Payment $platba;

    protected function setUp(): void
    {
        parent::setUp();

        config(['comgate.merchant' => '123456', 'comgate.secret' => 'tajne']);

        $adri = User::factory()->create();
        $prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $adri->id]);

        $this->platba = Payment::create([
            'gallery_space_id' => $prostor->id,
            'created_by' => $adri->id,
            'purchase_type' => 'module',
            'billing_period' => 'yearly',
            'amount' => 99000,
            'currency' => 'CZK',
            'transaction_id' => 'AB12-CD34-EF56',
        ]);
    }

    public function test_zaplacena_cizi_transakce_platbu_neodemkne(): void
    {
        Http::fake(['*/status' => Http::response('code=0&status=PAID&refId=MG-CIZI&price=9900&curr=CZK')]);

        $vysledek = app(CheckoutService::class)->settle($this->platba);

        $this->assertFalse($vysledek->isPaid());
    }

    public function test_nizsi_castka_platbu_neodemkne(): void
    {
        Http::fake(['*/status' => Http::response('code=0&status=PAID&refId='.$this->platba->reference.'&price=9900&curr=CZK')]);

        $this->assertFalse(app(CheckoutService::class)->settle($this->platba)->isPaid());
    }

    public function test_odpovidajici_potvrzeni_platbu_zaplati(): void
    {
        Http::fake(['*/status' => Http::response('code=0&status=PAID&refId='.$this->platba->reference.'&price=99000&curr=CZK')]);

        $this->assertTrue(app(CheckoutService::class)->settle($this->platba)->isPaid());
    }
}
