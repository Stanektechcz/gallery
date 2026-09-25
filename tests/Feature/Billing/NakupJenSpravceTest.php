<?php

namespace Tests\Feature\Billing;

use App\Http\Middleware\JenDvojice;
use App\Models\GallerySpace;
use App\Models\User;
use Database\Seeders\BillingCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Nákup předplatného zahájí správce **toho prostoru**.
 *
 * `CheckoutController::start` se ptal na `users.role` — `owner` má každý, kdo
 * se sám zaregistroval, i když je v galerii jen partner nebo host. Správce
 * s jinou globální rolí naopak nakoupit nemohl. Rozhoduje teď vlastník
 * prostoru nebo členství owner/admin, stejně jako v `BillingController`.
 */
class NakupJenSpravceTest extends TestCase
{
    use RefreshDatabase;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BillingCatalogSeeder::class);
        config(['comgate.merchant' => '123456', 'comgate.secret' => 'tajne', 'gallery.operator_emails' => 'provoz@vzpominky.test']);
        Http::fake(['*/create' => Http::response('code=0&message=OK&transId=AB12-CD34&redirect=https://brana.test/platba')]);

        $adri = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->prostor = GallerySpace::create(['name' => 'My dva', 'slug' => 'my-dva', 'owner_id' => $adri->id, 'is_default' => true]);
        $this->prostor->members()->attach($adri->id, ['role' => 'owner', 'joined_at' => now()]);
    }

    public function test_partner_se_zaregistrovanou_roli_owner_nakup_nezahaji(): void
    {
        $partner = $this->clen('editor', 'owner');

        $this->actingAs($partner)->postJson('/api/v1/billing/checkout', ['type' => 'plan', 'code' => 'duo_plus'])
            ->assertForbidden();
    }

    public function test_spravce_prostoru_nakoupi_i_bez_globalni_role_owner(): void
    {
        $spravce = $this->clen('admin', 'partner');

        $this->actingAs($spravce)->postJson('/api/v1/billing/checkout', ['type' => 'plan', 'code' => 'duo_plus'])
            ->assertOk()
            ->assertJsonPath('redirect', 'https://brana.test/platba');
    }

    /**
     * I bez brány `JenDvojice`: host nesmí za cizí prostor objednat nic, ani
     * kdyby se ke kontrolérům dostal jinou cestou.
     */
    public function test_host_ciziho_prostoru_nakup_nezahaji(): void
    {
        $host = $this->clen('viewer', 'owner');

        $this->withoutMiddleware(JenDvojice::class)
            ->actingAs($host)->postJson('/api/v1/billing/checkout', ['type' => 'module', 'code' => 'burps'])
            ->assertForbidden();
    }

    public function test_provozovatel_nakup_zahaji(): void
    {
        $provoz = $this->clen('editor', 'partner', 'provoz@vzpominky.test');

        $this->actingAs($provoz)->postJson('/api/v1/billing/checkout', ['type' => 'plan', 'code' => 'duo_plus'])
            ->assertOk();
    }

    private function clen(string $roleVProstoru, string $globalniRole, ?string $email = null): User
    {
        $user = User::factory()->create(array_filter([
            'role' => $globalniRole, 'is_active' => true, 'email' => $email,
        ]));
        $this->prostor->members()->attach($user->id, ['role' => $roleVProstoru, 'joined_at' => now()]);

        return $user;
    }
}
