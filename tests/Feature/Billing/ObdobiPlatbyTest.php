<?php

namespace Tests\Feature\Billing;

use App\Models\BillingModule;
use App\Models\BillingPlan;
use App\Models\GallerySpace;
use App\Models\Payment;
use App\Models\SpaceModule;
use App\Models\SpaceSubscription;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\EntitlementService;
use Carbon\CarbonImmutable;
use Database\Seeders\BillingCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Zaplacené období opravdu skončí.
 *
 * `assignPlan` i `enableModule` psaly `ends_at = null` a konec období jen do
 * `current_period_ends_at`. Jenže `plan()` a `activeModules()` rozhodují podle
 * `ends_at` — jedna měsíční platba tak odemkla tarif navždy, upomínky o konci
 * předplatného nikdy neodešly a zápočet nevyužité části při přechodu na dražší
 * tarif vždy vyšel nula.
 */
class ObdobiPlatbyTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BillingCatalogSeeder::class);
        config(['comgate.merchant' => '123456', 'comgate.secret' => 'tajne']);
        $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00'));

        $this->adri = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->prostor = GallerySpace::create(['name' => 'My dva', 'slug' => 'my-dva', 'owner_id' => $this->adri->id, 'is_default' => true]);
        $this->prostor->members()->attach($this->adri->id, ['role' => 'owner', 'joined_at' => now()]);

        $this->falesnaBrana();
    }

    public function test_mesicni_platba_tarif_po_mesici_neodemyka(): void
    {
        $this->zaplat($this->platbaZaTarif('duo_plus', 14_900));
        $this->assertSame('duo_plus', $this->tarif());

        $this->travel(32)->days();

        $this->assertSame('duo', $this->tarif(), 'Po skončení zaplaceného měsíce platí zase výchozí tarif.');
    }

    public function test_prechod_na_drazsi_tarif_zapocte_nevyuzitou_cast(): void
    {
        $this->zaplat($this->platbaZaTarif('duo_plus', 14_900));
        $this->travel(10)->days();

        $nakup = app(CheckoutService::class)->startPlanPurchase(
            $this->prostor, BillingPlan::where('code', 'rodina')->firstOrFail(), $this->adri, 'monthly'
        );

        $this->assertGreaterThan(0, $nakup['credit'], 'Zbylé dny zaplaceného tarifu se mají odečíst.');
        $this->assertSame(24_900 - $nakup['credit'], $nakup['payment']->amount);
    }

    public function test_opakovana_platba_modulu_prodlouzi_od_konce_ne_od_ted(): void
    {
        $modul = BillingModule::where('code', 'burps')->firstOrFail();

        $this->zaplat($this->platbaZaModul($modul));
        $prvniKonec = SpaceModule::where('billing_module_id', $modul->id)->firstOrFail()->ends_at;
        $this->assertTrue($prvniKonec->equalTo(now()->addMonth()));

        $this->travel(10)->days();
        $this->zaplat($this->platbaZaModul($modul));

        $konec = SpaceModule::where('billing_module_id', $modul->id)->firstOrFail()->ends_at;
        $this->assertTrue($konec->equalTo($prvniKonec->copy()->addMonth()),
            'Zbylých dvacet dní zaplaceného měsíce nesmí propadnout.');

        $this->travel(32)->days();
        $this->assertTrue(app(EntitlementService::class)->hasModule($this->prostor->fresh(), 'burps'));
        $this->travel(30)->days();
        app(EntitlementService::class)->forget();
        $this->assertFalse(app(EntitlementService::class)->hasModule($this->prostor->fresh(), 'burps'));
    }

    /**
     * Období a poslední platba se opravdu uloží.
     *
     * `billing_period`, `current_period_ends_at` a `last_payment_id` chyběly
     * v `$fillable` modelů, takže je Eloquent potichu zahodil: přehled
     * předplatného nikdy neukázal, do kdy je zaplaceno, a roční předplatné se
     * v tržbách počítalo jako měsíční.
     */
    public function test_zaplacene_obdobi_a_posledni_platba_se_ulozi(): void
    {
        $platba = $this->platbaZaTarif('duo_plus', 149_000);
        $platba->update(['billing_period' => 'yearly']);
        $this->zaplat($platba);

        $predplatne = SpaceSubscription::where('gallery_space_id', $this->prostor->id)->firstOrFail();
        $this->assertSame('yearly', $predplatne->billing_period);
        $this->assertSame($platba->id, (int) $predplatne->last_payment_id);
        $this->assertTrue($predplatne->current_period_ends_at?->equalTo(now()->addYear()));

        $prehled = app(EntitlementService::class)->overview($this->prostor->fresh());
        $this->assertSame(now()->addYear()->toIso8601String(), $prehled['subscription']['current_period_ends_at']);
        $this->assertSame('yearly', $prehled['subscription']['billing_period']);

        $modul = BillingModule::where('code', 'burps')->firstOrFail();
        $zaModul = $this->platbaZaModul($modul);
        $zaModul->update(['billing_period' => 'yearly', 'amount' => $modul->price_monthly * 10]);
        $this->zaplat($zaModul);

        $radek = SpaceModule::where('billing_module_id', $modul->id)->firstOrFail();
        $this->assertSame('yearly', $radek->billing_period);
        $this->assertTrue($radek->current_period_ends_at?->equalTo(now()->addYear()));
    }

    private function tarif(): ?string
    {
        $sluzba = app(EntitlementService::class);
        $sluzba->forget();

        return $sluzba->plan($this->prostor->fresh())?->code;
    }

    private function platbaZaTarif(string $kod, int $castka): Payment
    {
        return Payment::create([
            'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id,
            'purchase_type' => 'plan', 'billing_plan_id' => BillingPlan::where('code', $kod)->value('id'),
            'billing_period' => 'monthly', 'amount' => $castka, 'currency' => 'CZK',
            'transaction_id' => 'T-'.$kod.'-'.now()->timestamp,
        ]);
    }

    private function platbaZaModul(BillingModule $modul): Payment
    {
        return Payment::create([
            'gallery_space_id' => $this->prostor->id, 'created_by' => $this->adri->id,
            'purchase_type' => 'module', 'billing_module_id' => $modul->id,
            'billing_period' => 'monthly', 'amount' => $modul->price_monthly, 'currency' => 'CZK',
            'transaction_id' => 'T-'.$modul->code.'-'.now()->timestamp,
        ]);
    }

    private function zaplat(Payment $platba): void
    {
        $this->assertTrue(app(CheckoutService::class)->settle($platba)->isPaid());
    }

    /**
     * Brána jako Comgate: `/create` založí transakci, `/status` potvrdí tu, na
     * kterou se ptáme. Jeden falešný server pro celý test — druhé `Http::fake`
     * by první nepřepsalo, vyhrává dřív zaregistrovaná shoda.
     */
    private function falesnaBrana(): void
    {
        Http::fake(function (Request $pozadavek) {
            if (str_ends_with($pozadavek->url(), '/create')) {
                return Http::response('code=0&message=OK&transId=NOVA-'.$pozadavek['refId'].'&redirect=https://brana.test/platba');
            }

            $platba = Payment::where('transaction_id', $pozadavek['transId'])->firstOrFail();

            return Http::response('code=0&message=OK&status=PAID&refId='.$platba->reference.'&price='.$platba->amount.'&curr=CZK');
        });
    }
}
