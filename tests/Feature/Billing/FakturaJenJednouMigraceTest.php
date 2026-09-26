<?php

namespace Tests\Feature\Billing;

use App\Models\GallerySpace;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\SchemaMimoTransakce;
use Tests\TestCase;

/**
 * Migrace s unikátním `invoices.payment_id` nesmí spadnout na starých
 * dvojicích a nesmí je mazat — faktura je daňový doklad. Index v tom případě
 * prostě nevznikne.
 *
 * Dřív v `DvojePotvrzeniPlatbyTest` pod `RefreshDatabase`: odebrání indexu
 * je DDL a MySQL by jím transakci testu potvrdil. A odebíral se holým
 * `dropUnique()`, který na MySQL spadne na „needed in a foreign key
 * constraint" — `payment_id` má cizí klíč a unikátní index mu slouží za jeho
 * index. Index se proto odebírá `down()` té migrace, který s tím počítá.
 */
class FakturaJenJednouMigraceTest extends TestCase
{
    use SchemaMimoTransakce;

    private const MIGRACE = 'migrations/2026_09_25_090000_invoices_payment_id_unique.php';

    private const INDEX = 'invoices_payment_id_unique';

    private Payment $platba;

    protected function setUp(): void
    {
        parent::setUp();

        $adri = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $prostor = GallerySpace::create(['name' => 'My dva', 'slug' => 'my-dva', 'owner_id' => $adri->id, 'is_default' => true]);

        $this->platba = Payment::create([
            'gallery_space_id' => $prostor->id, 'created_by' => $adri->id,
            'purchase_type' => 'module', 'billing_period' => 'monthly',
            'amount' => 99, 'currency' => 'CZK', 'transaction_id' => 'AB12-CD34-EF56',
        ]);
    }

    public function test_migrace_indexu_preskoci_existujici_duplicity_a_nic_nesmaze(): void
    {
        $migrace = require database_path(self::MIGRACE);
        $migrace->down();
        $this->assertFalse(Schema::hasIndex('invoices', self::INDEX), 'down() má index odebrat i na MySQL.');

        DB::table('invoices')->insert([$this->faktura('20260001'), $this->faktura('20260002')]);

        $migrace->up();

        $this->assertSame(2, Invoice::count());
        $this->assertFalse(Schema::hasIndex('invoices', self::INDEX));

        // Bez duplicit index vznikne.
        DB::table('invoices')->where('number', '20260002')->update(['payment_id' => null]);
        $migrace->up();
        $this->assertTrue(Schema::hasIndex('invoices', self::INDEX));
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
