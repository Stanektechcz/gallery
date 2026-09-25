<?php

namespace Tests\Feature\Billing;

use App\Http\Middleware\JenDvojice;
use App\Models\BillingModule;
use App\Models\BillingPlan;
use App\Models\GallerySpace;
use App\Models\SpaceModule;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use Database\Seeders\BillingCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tarif a moduly mění správce **toho prostoru** — a placené se kupují.
 *
 * `users.role = owner` dostane každý, kdo se sám zaregistruje. Oprávnění se
 * podle něj posuzovalo, takže si kterýkoli zákazník přes `PUT /billing/plan`
 * přidělil nejdražší tarif zadarmo, a s `gallery_space_id` i prostoru, kde je
 * jen host. Rozhoduje teď role v prostoru; bez platby přidělí placený tarif
 * nebo modul jen provozovatel.
 */
class SpravaPredplatnehoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BillingCatalogSeeder::class);
        config(['gallery.operator_emails' => 'provoz@vzpominky.test']);
    }

    public function test_zakaznik_si_placeny_tarif_neprideli(): void
    {
        [$adri] = $this->prostor('adri@vzpominky.test');

        $this->actingAs($adri)->putJson('/api/v1/billing/plan', ['plan_code' => 'skupina'])
            ->assertStatus(422);

        $this->getJson('/api/v1/billing/overview')->assertJsonPath('plan.code', 'duo');
    }

    public function test_zakaznik_si_placeny_modul_nezapne(): void
    {
        [$adri, $prostor] = $this->prostor('adri@vzpominky.test');

        $this->actingAs($adri)->putJson('/api/v1/billing/modules/burps', ['enabled' => true])
            ->assertStatus(422);

        $this->assertFalse(app(EntitlementService::class)->hasModule($prostor->fresh(), 'burps'));
    }

    public function test_tarif_zdarma_si_spravce_zvolit_smi(): void
    {
        [$adri, $prostor] = $this->prostor('adri@vzpominky.test');
        app(EntitlementService::class)->assignPlan($prostor, BillingPlan::where('code', 'rodina')->firstOrFail());

        $this->actingAs($adri)->putJson('/api/v1/billing/plan', ['plan_code' => 'duo'])
            ->assertOk()
            ->assertJsonPath('plan.code', 'duo');
    }

    public function test_provozovatel_prideli_placeny_tarif_i_modul(): void
    {
        [$provoz, $prostor] = $this->prostor('provoz@vzpominky.test');

        $this->actingAs($provoz)->putJson('/api/v1/billing/plan', ['plan_code' => 'rodina'])
            ->assertOk()
            ->assertJsonPath('plan.code', 'rodina');
        $this->putJson('/api/v1/billing/modules/burps', ['enabled' => true])->assertOk();

        $this->assertTrue(app(EntitlementService::class)->hasModule($prostor->fresh(), 'burps'));
    }

    public function test_cizi_prostor_pres_gallery_space_id_nezmeni_host_ani_nekdo_zvenku(): void
    {
        [$adri] = $this->prostor('adri@vzpominky.test');
        [, $cizi] = $this->prostor('cizi@vzpominky.test');
        [$provoz] = $this->prostor('provoz@vzpominky.test');

        // Host cizího prostoru: členem je, správcem ne.
        $cizi->members()->attach($adri->id, ['role' => 'viewer', 'joined_at' => now()]);

        $this->actingAs($adri)
            ->putJson('/api/v1/billing/plan', ['plan_code' => 'duo', 'gallery_space_id' => $cizi->id])
            ->assertForbidden();
        $this->putJson('/api/v1/billing/modules/burps', ['enabled' => false, 'gallery_space_id' => $cizi->id])
            ->assertForbidden();

        // Provozovatel není člen — prostor pro něj přes tenhle endpoint neexistuje.
        $this->actingAs($provoz)
            ->putJson('/api/v1/billing/plan', ['plan_code' => 'rodina', 'gallery_space_id' => $cizi->id])
            ->assertNotFound();

        $this->assertSame('duo', app(EntitlementService::class)->plan($cizi->fresh())?->code);
    }

    public function test_partner_bez_spravcovske_role_zkusebni_obdobi_nespusti(): void
    {
        [, $prostor] = $this->prostor('adri@vzpominky.test');
        $partner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $prostor->members()->attach($partner->id, ['role' => 'editor', 'joined_at' => now()]);

        $this->actingAs($partner)->postJson('/api/v1/billing/trial', ['plan' => 'rodina'])->assertForbidden();
        $this->putJson('/api/v1/billing/plan', ['plan_code' => 'duo'])->assertForbidden();
    }

    public function test_spravce_placeny_modul_vypnout_smi_a_zaplacene_obdobi_mu_zustane(): void
    {
        [$adri, $prostor] = $this->prostor('adri@vzpominky.test');
        $modul = BillingModule::where('code', 'burps')->firstOrFail();
        $zaplaceno = now()->addDays(20)->startOfSecond();
        app(EntitlementService::class)->enableModule($prostor, $modul, $adri, 'monthly', $zaplaceno);

        $this->actingAs($adri)->putJson('/api/v1/billing/modules/burps', ['enabled' => false])->assertOk();
        $this->assertFalse(app(EntitlementService::class)->hasModule($prostor->fresh(), 'burps'));

        // Znovu zapnout zaplacený modul jde — kupuje se jen čas, který ještě nemá.
        $this->putJson('/api/v1/billing/modules/burps', ['enabled' => true])->assertOk();
        $this->assertTrue(app(EntitlementService::class)->hasModule($prostor->fresh(), 'burps'));
        $radek = SpaceModule::where('gallery_space_id', $prostor->id)->where('billing_module_id', $modul->id)->firstOrFail();
        $this->assertTrue($radek->ends_at?->equalTo($zaplaceno), 'Vypnutím a zapnutím se zaplacený konec nesmí ztratit.');
    }

    /**
     * Volby funkcí mění dvojice, ne host.
     *
     * Brána `JenDvojice` hosta k `v1` nepustí, ale jen proto, že posuzuje
     * týž prostor. Kontrolér se na to nespoléhá: volba funkcí platí pro celou
     * galerii, takže ji smí měnit jen vlastník a role dvojice
     * (`PristupDoGalerie::ROLE_DVOJICE`) — partner ano, host ne.
     */
    public function test_volby_funkci_meni_dvojice_ne_host(): void
    {
        [, $prostor] = $this->prostor('adri@vzpominky.test');
        $partner = User::factory()->create(['role' => 'partner', 'is_active' => true]);
        $host = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $prostor->members()->attach($partner->id, ['role' => 'editor', 'joined_at' => now()]);
        $prostor->members()->attach($host->id, ['role' => 'viewer', 'joined_at' => now()]);

        $this->withoutMiddleware(JenDvojice::class);

        $this->actingAs($host)->putJson('/api/v1/billing/features/sharing', ['enabled' => false])->assertForbidden();
        $this->assertDatabaseMissing('space_features', ['gallery_space_id' => $prostor->id]);

        $this->actingAs($partner)->putJson('/api/v1/billing/features/sharing', ['enabled' => false])->assertOk();
        $this->assertDatabaseHas('space_features', ['gallery_space_id' => $prostor->id, 'enabled' => false]);
    }

    public function test_vychozi_prostor_je_stejny_jako_u_kontroly_pristupu(): void
    {
        [$adri, $vlastni] = $this->prostor('adri@vzpominky.test');
        [, $druhy] = $this->prostor('druhy@vzpominky.test');
        $druhy->members()->attach($adri->id, ['role' => 'admin', 'joined_at' => now()]);
        app(EntitlementService::class)->assignPlan($druhy, BillingPlan::where('code', 'rodina')->firstOrFail());

        // Oba prostory jsou výchozí; rozhoduje nižší id jako v `gallerySpaces()`.
        $this->assertSame($vlastni->id, $adri->gallerySpaces()->first()->id);
        $this->actingAs($adri)->getJson('/api/v1/billing/overview')->assertJsonPath('plan.code', 'duo');
    }

    /** @return array{0: User, 1: GallerySpace} */
    private function prostor(string $email): array
    {
        // Tak, jak účet vznikne registrací: `users.role = owner` pro každého.
        $user = User::factory()->create(['email' => $email, 'role' => 'owner', 'is_active' => true]);
        $prostor = GallerySpace::create([
            'name' => 'Prostor '.$email, 'slug' => 'p-'.md5($email), 'owner_id' => $user->id, 'is_default' => true,
        ]);
        $prostor->members()->attach($user->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);

        return [$user, $prostor];
    }
}
