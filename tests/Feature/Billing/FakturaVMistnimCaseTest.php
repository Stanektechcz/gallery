<?php

namespace Tests\Feature\Billing;

use App\Models\GallerySpace;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\InvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faktura nese rok a datum podle Prahy, ne podle UTC.
 *
 * Číslo bralo rok z `now()->year` a šablona formátovala okamžiky v UTC. Platba
 * ve 00:30 prvního ledna tak dostala loňskou řadu čísel a datum 31. prosince —
 * na dokladu, který se vykazuje za rok.
 */
class FakturaVMistnimCaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_platba_po_pulnoci_na_novy_rok_patri_do_noveho_roku(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30', 'Europe/Prague'));

        $adri = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $prostor = GallerySpace::create(['name' => 'My dva', 'slug' => 'my-dva', 'owner_id' => $adri->id, 'is_default' => true]);
        $prostor->members()->attach($adri->id, ['role' => 'owner', 'joined_at' => now()]);

        // Loňská řada už běží — nová se nesmí napojit na ni.
        Invoice::create([
            'uuid' => 'a0000000-0000-0000-0000-000000000001', 'number' => '20260007',
            'gallery_space_id' => $prostor->id, 'description' => 'Tarif', 'amount' => 100,
            'issued_at' => now()->subDay(),
        ]);

        $platba = Payment::create([
            'gallery_space_id' => $prostor->id, 'created_by' => $adri->id, 'purchase_type' => 'plan',
            'billing_period' => 'monthly', 'amount' => 14_900, 'currency' => 'CZK',
            'status' => 'paid', 'paid_at' => now(),
        ]);

        $faktura = app(InvoiceService::class)->forPayment($platba);

        $this->assertSame('20270001', $faktura->number);

        $this->actingAs($adri)->get('/faktury/'.$faktura->uuid)
            ->assertOk()
            ->assertSee('Vystaveno 1. 1. 2027')
            ->assertSee('Uhrazeno 1. 1. 2027');
    }
}
