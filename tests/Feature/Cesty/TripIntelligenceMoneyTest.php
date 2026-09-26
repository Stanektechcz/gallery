<?php

namespace Tests\Feature\Cesty;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rozpočtové limity, náklady na vozidlo a vyrovnání dvojice — `decimal`
 * sloupce (`trip_budget_limits.amount`, `trip_vehicle_costs.amount` aj.,
 * `trip_settlements.amount`) chodí z MySQL jako řetězec. Testy tu na SQLite
 * projdou i bez převodu; MySQL v CI teprve prokáže, že kontroler je
 * skutečně přetypoval.
 */
class TripIntelligenceMoneyTest extends CestyTestCase
{
    public function test_ulozeny_rozpoctovy_limit_vraci_castku_jako_cislo(): void
    {
        $this->cesta('2026-11-01', '2026-11-05');

        $limit = $this->putJson("/api/v1/trips/{$this->tripId}/budget-limits", [
            'category' => 'food', 'amount' => 5000,
        ])->assertOk()->json();

        $this->assertIsNotString($limit['amount']);
        $this->assertEquals(5000, $limit['amount']);
    }

    public function test_ulozeny_naklad_na_vozidlo_vraci_castku_jako_cislo(): void
    {
        $this->cesta('2026-11-01', '2026-11-05');

        $naklad = $this->postJson("/api/v1/trips/{$this->tripId}/vehicle-costs", [
            'type' => 'fuel', 'title' => 'Benzín', 'amount' => 899.5, 'liters' => 32.4, 'distance_km' => 410,
        ])->assertCreated()->json();

        $this->assertIsNotString($naklad['amount']);
        $this->assertEquals(899.5, $naklad['amount']);
        $this->assertIsNotString($naklad['liters']);
        $this->assertIsNotString($naklad['distance_km']);
    }

    public function test_upraveny_naklad_na_vozidlo_vraci_castku_jako_cislo(): void
    {
        $this->cesta('2026-11-01', '2026-11-05');
        $id = DB::table('trip_vehicle_costs')->insertGetId([
            'uuid' => (string) Str::uuid(), 'trip_id' => $this->tripId,
            'created_by' => $this->owner->id, 'type' => 'toll', 'title' => 'Dálniční známka',
            'amount' => 500, 'currency' => 'CZK', 'occurred_on' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $naklad = $this->patchJson("/api/v1/trips/{$this->tripId}/vehicle-costs/{$id}", ['amount' => 550])
            ->assertOk()->json();

        $this->assertIsNotString($naklad['amount']);
        $this->assertEquals(550, $naklad['amount']);
    }

    public function test_seznam_nakladu_na_vozidlo_vraci_castky_jako_cisla(): void
    {
        $this->cesta('2026-11-01', '2026-11-05');
        DB::table('trip_vehicle_costs')->insert([
            'uuid' => (string) Str::uuid(), 'trip_id' => $this->tripId,
            'created_by' => $this->owner->id, 'type' => 'parking', 'title' => 'Parkoviště',
            'amount' => 120, 'currency' => 'CZK', 'occurred_on' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->getJson("/api/v1/trips/{$this->tripId}/vehicle-costs")->assertOk()->json();

        $this->assertIsNotString($data['items'][0]['amount']);
        $this->assertEquals(120, $data['items'][0]['amount']);
    }

    public function test_pripravenost_cesty_vraci_castku_vyrovnani_jako_cislo(): void
    {
        $this->cesta('2026-11-01', '2026-11-05');
        DB::table('trip_settlements')->insert([
            'trip_id' => $this->tripId, 'from_user_id' => $this->partner->id, 'to_user_id' => $this->owner->id,
            'amount' => 640, 'currency' => 'CZK', 'status' => 'suggested', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->getJson("/api/v1/trips/{$this->tripId}/readiness")->assertOk()->json();

        $this->assertIsNotString($data['settlements'][0]['amount']);
        $this->assertEquals(640, $data['settlements'][0]['amount']);
    }
}
