<?php

namespace Tests\Feature\Penize;

use App\Models\FinanceAccess;
use App\Models\FinanceProject;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Planovani\DvojiceSHostem;
use Tests\TestCase;

/**
 * Přiřazení platby k cestě smí jen ten, kdo tu cestu smí upravovat.
 *
 * Formulář nabízí jen cesty, které uživatel vidí (`FinanceAccess::viditelne`), ale
 * zápis dřív ověřoval jen to, že cesta patří do stejného prostoru — jakýkoli druh,
 * včetně soukromé cesty někoho jiného. Partner tak mohl přiřadit platbu k cestě,
 * kterou ve formuláři vůbec nevidí, a přidat tím útratu do cizího soukromého
 * rozpočtu. Totéž platilo pro pravidelnou platbu a pro rozpočet na cestu.
 */
class CestaKPlatbePrivatniTest extends TestCase
{
    use DvojiceSHostem;
    use RefreshDatabase;

    public function test_partner_nepriradi_platbu_k_soukrome_ceste_vlastnika(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();

        $ucet = Wallet::create([
            'gallery_space_id' => $prostor->id, 'name' => 'Účet', 'kind' => 'bank',
            'currency' => 'EUR', 'opening_balance' => 1000, 'is_active' => true,
        ]);

        // Soukromá cesta vlastníka — bez FinanceAccess ji nikdo jiný nesmí ani vidět.
        $cesta = FinanceProject::create([
            'gallery_space_id' => $prostor->id, 'kind' => 'trip', 'name' => 'Vlastníkova cesta',
            'starts_on' => '2026-09-01', 'base_currency' => 'EUR', 'owner_user_id' => $vlastnik->id,
        ]);

        $odpoved = $this->actingAs($partner)->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'expense', 'occurred_at' => '2026-09-10',
            'wallet_from' => $ucet->uuid, 'amount_from' => 50,
            'trip' => $cesta->uuid,
            'potvrzeno' => true,
        ]);

        $odpoved->assertStatus(422)->assertJsonValidationErrors('trip');
        $this->assertSame(0, Transaction::count());
    }

    public function test_partner_nepriradi_pravidelnou_platbu_k_soukrome_ceste(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();

        $ucet = Wallet::create([
            'gallery_space_id' => $prostor->id, 'name' => 'Účet', 'kind' => 'bank',
            'currency' => 'EUR', 'opening_balance' => 1000, 'is_active' => true,
        ]);

        $cesta = FinanceProject::create([
            'gallery_space_id' => $prostor->id, 'kind' => 'trip', 'name' => 'Vlastníkova cesta',
            'starts_on' => '2026-09-01', 'base_currency' => 'EUR', 'owner_user_id' => $vlastnik->id,
        ]);

        $odpoved = $this->actingAs($partner)->postJson('/api/v1/rozpocet/pravidelne', [
            'name' => 'Nájem', 'amount' => 100, 'wallet_uuid' => $ucet->uuid,
            'day_of_month' => 1, 'starts_on' => '2026-09-01',
            'trip_uuid' => $cesta->uuid,
        ]);

        $odpoved->assertStatus(422)->assertJsonValidationErrors('trip_uuid');
        $this->assertSame(0, DB::table('finance_recurring')->count());
    }

    public function test_partner_nepriradi_rozpocet_k_soukrome_ceste(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();

        $cesta = FinanceProject::create([
            'gallery_space_id' => $prostor->id, 'kind' => 'trip', 'name' => 'Vlastníkova cesta',
            'starts_on' => '2026-09-01', 'base_currency' => 'EUR', 'owner_user_id' => $vlastnik->id,
        ]);

        $odpoved = $this->actingAs($partner)->postJson('/api/v1/rozpocet/rozpocty', [
            'name' => 'Cesta', 'budget_kind' => 'trip', 'currency' => 'EUR', 'amount' => 1000,
            'trip_uuid' => $cesta->uuid,
        ]);

        $odpoved->assertStatus(422)->assertJsonValidationErrors('trip_uuid');
        $this->assertSame(0, DB::table('budgets')->count());
    }

    /** Sdílená cesta s právem úpravy dál funguje — kontrola nesmí zavřít i to, co má projít. */
    public function test_sdilena_editovatelna_cesta_projde(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();

        $ucet = Wallet::create([
            'gallery_space_id' => $prostor->id, 'name' => 'Účet', 'kind' => 'bank',
            'currency' => 'EUR', 'opening_balance' => 1000, 'is_active' => true,
        ]);

        $cesta = FinanceProject::create([
            'gallery_space_id' => $prostor->id, 'kind' => 'trip', 'name' => 'Sdílená cesta',
            'starts_on' => '2026-09-01', 'base_currency' => 'EUR', 'owner_user_id' => $vlastnik->id,
        ]);

        FinanceAccess::create([
            'gallery_space_id' => $prostor->id, 'subject_type' => 'trip',
            'subject_id' => $cesta->id, 'user_id' => $partner->id, 'can_edit' => true,
        ]);

        $odpoved = $this->actingAs($partner)->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'expense', 'occurred_at' => '2026-09-10',
            'wallet_from' => $ucet->uuid, 'amount_from' => 50,
            'trip' => $cesta->uuid,
            'potvrzeno' => true,
        ]);

        $odpoved->assertCreated();
        $this->assertSame($cesta->id, Transaction::first()->finance_project_id);
    }

    /** Vidí, ale nesmí upravovat: přístup jen ke čtení platbu do cesty taky nepustí. */
    public function test_pristup_jen_ke_cteni_platbu_nepusti(): void
    {
        [$vlastnik, $partner, , $prostor] = $this->dvojiceSHostem();

        $ucet = Wallet::create([
            'gallery_space_id' => $prostor->id, 'name' => 'Účet', 'kind' => 'bank',
            'currency' => 'EUR', 'opening_balance' => 1000, 'is_active' => true,
        ]);

        $cesta = FinanceProject::create([
            'gallery_space_id' => $prostor->id, 'kind' => 'trip', 'name' => 'Cesta ke čtení',
            'starts_on' => '2026-09-01', 'base_currency' => 'EUR', 'owner_user_id' => $vlastnik->id,
        ]);

        FinanceAccess::create([
            'gallery_space_id' => $prostor->id, 'subject_type' => 'trip',
            'subject_id' => $cesta->id, 'user_id' => $partner->id, 'can_edit' => false,
        ]);

        $odpoved = $this->actingAs($partner)->postJson('/api/v1/rozpocet/transakce', [
            'type' => 'expense', 'occurred_at' => '2026-09-10',
            'wallet_from' => $ucet->uuid, 'amount_from' => 50,
            'trip' => $cesta->uuid,
            'potvrzeno' => true,
        ]);

        $odpoved->assertStatus(422)->assertJsonValidationErrors('trip');
        $this->assertSame(0, Transaction::count());
    }
}
