<?php

namespace Tests\Feature\Billing;

use App\Models\AuditLog;
use App\Models\BillingModule;
use App\Models\GallerySpace;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SpaceModule;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\InvoiceService;
use Carbon\CarbonImmutable;
use Database\Seeders\BillingCatalogSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Jedna platba = jedno zaplacení, jedno prodloužení, jedna faktura.
 *
 * Potvrzení chodí dvěma cestami zároveň: notifikace z brány a dotaz prohlížeče,
 * který se po návratu z platby ptá na stav. Obě měly v ruce vlastní kopii
 * platby, obě se zeptaly „už je zaplacená?" na model v paměti a obě ji
 * zaplatily — modul se prodloužil dvakrát, v protokolu byla platba dvakrát
 * a bez unikátního `invoices.payment_id` mohly vzniknout dvě faktury.
 */
class DvojePotvrzeniPlatbyTest extends TestCase
{
    use RefreshDatabase;

    private Payment $platba;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BillingCatalogSeeder::class);
        config(['comgate.merchant' => '123456', 'comgate.secret' => 'tajne']);
        $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00'));

        $adri = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $prostor = GallerySpace::create(['name' => 'My dva', 'slug' => 'my-dva', 'owner_id' => $adri->id, 'is_default' => true]);
        $modul = BillingModule::where('code', 'burps')->firstOrFail();

        $this->platba = Payment::create([
            'gallery_space_id' => $prostor->id, 'created_by' => $adri->id,
            'purchase_type' => 'module', 'billing_module_id' => $modul->id,
            'billing_period' => 'monthly', 'amount' => $modul->price_monthly, 'currency' => 'CZK',
            'transaction_id' => 'AB12-CD34-EF56',
        ]);

        Http::fake(['*/status' => Http::response(
            'code=0&status=PAID&refId='.$this->platba->reference.'&price='.$this->platba->amount.'&curr=CZK'
        )]);
    }

    public function test_dve_soubezna_potvrzeni_zaplati_platbu_jen_jednou(): void
    {
        // Dvě samostatné kopie téže platby — jako notifikace a dotaz prohlížeče.
        $zNotifikace = Payment::findOrFail($this->platba->id);
        $zProhlizece = Payment::findOrFail($this->platba->id);

        app(CheckoutService::class)->settle($zNotifikace);
        app(CheckoutService::class)->settle($zProhlizece);

        $this->assertSame(1, Invoice::where('payment_id', $this->platba->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'billing.payment.paid')->count());

        $konec = SpaceModule::where('gallery_space_id', $this->platba->gallery_space_id)->firstOrFail()->ends_at;
        $this->assertTrue($konec->equalTo(now()->addMonth()), 'Jedna měsíční platba = jeden měsíc, ne dva.');
    }

    public function test_faktura_k_platbe_se_vystavi_jen_jednou(): void
    {
        $prvni = app(InvoiceService::class)->forPayment(Payment::findOrFail($this->platba->id));
        $druha = app(InvoiceService::class)->forPayment(Payment::findOrFail($this->platba->id));

        $this->assertSame($prvni->id, $druha->id);
        $this->assertSame(1, Invoice::count());
    }

    public function test_databaze_druhou_fakturu_k_platbe_odmitne(): void
    {
        app(InvoiceService::class)->forPayment($this->platba);

        $this->expectException(UniqueConstraintViolationException::class);

        Invoice::create($this->faktura('20260099'));
    }

    /**
     * Migrace s unikátním indexem nesmí spadnout na starých dvojicích a nesmí
     * je mazat — faktura je daňový doklad. Index v tom případě prostě nevznikne.
     */
    public function test_migrace_indexu_preskoci_existujici_duplicity_a_nic_nesmaze(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropUnique('invoices_payment_id_unique'));
        DB::table('invoices')->insert([$this->faktura('20260001'), $this->faktura('20260002')]);

        $migrace = require database_path('migrations/2026_09_25_090000_invoices_payment_id_unique.php');
        $migrace->up();

        $this->assertSame(2, Invoice::count());
        $this->assertFalse(Schema::hasIndex('invoices', 'invoices_payment_id_unique'));

        // Bez duplicit index vznikne.
        DB::table('invoices')->where('number', '20260002')->update(['payment_id' => null]);
        $migrace->up();
        $this->assertTrue(Schema::hasIndex('invoices', 'invoices_payment_id_unique'));
    }

    /** @return array<string, mixed> */
    private function faktura(string $cislo): array
    {
        return [
            'uuid' => (string) Str::uuid(), 'number' => $cislo, 'payment_id' => $this->platba->id,
            'gallery_space_id' => $this->platba->gallery_space_id, 'description' => 'Modul',
            'amount' => $this->platba->amount, 'currency' => 'CZK', 'vat_rate' => 0,
            'issued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ];
    }
}
