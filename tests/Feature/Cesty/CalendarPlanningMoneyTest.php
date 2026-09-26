<?php

namespace Tests\Feature\Cesty;

use Illuminate\Support\Facades\DB;

/**
 * Výdaje a variantry trasy — `decimal` sloupce `trip_expenses.amount`
 * a `trip_route_variants.estimated_cost` chodí z MySQL jako řetězec
 * ("1200.00"). Kontroler je musí přetypovat, jinak prototyp částky sčítá
 * jako text. Testy tu na SQLite procházejí i bez převodu — ověřují tvar
 * odpovědi; MySQL v CI teprve prokáže, že převod v kontroleru je.
 */
class CalendarPlanningMoneyTest extends CestyTestCase
{
    public function test_ulozeny_vydaj_vraci_castku_jako_cislo(): void
    {
        $this->cesta('2026-11-01', '2026-11-05');

        $vydaj = $this->postJson("/api/v1/trips/{$this->tripId}/expenses", [
            'title' => 'Hotel', 'category' => 'accommodation', 'amount' => 1200, 'state' => 'planned',
        ])->assertCreated()->json();

        $this->assertIsNotString($vydaj['amount']);
        $this->assertEquals(1200, $vydaj['amount']);
    }

    public function test_upraveny_vydaj_vraci_castku_jako_cislo(): void
    {
        $this->cesta('2026-11-01', '2026-11-05');
        $id = DB::table('trip_expenses')->insertGetId([
            'trip_id' => $this->tripId, 'created_by' => $this->owner->id, 'title' => 'Vlak',
            'category' => 'transport', 'amount' => 500, 'currency' => 'CZK', 'state' => 'planned',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $vydaj = $this->patchJson("/api/v1/trips/{$this->tripId}/expenses/{$id}", ['amount' => 750])
            ->assertOk()->json();

        $this->assertIsNotString($vydaj['amount']);
        $this->assertEquals(750, $vydaj['amount']);
    }

    public function test_plan_cesty_vraci_castky_vydaju_a_variant_jako_cisla(): void
    {
        $this->cesta('2026-11-01', '2026-11-05');
        DB::table('trip_expenses')->insert([
            'trip_id' => $this->tripId, 'created_by' => $this->owner->id, 'title' => 'Muzeum',
            'category' => 'activities', 'amount' => 300, 'currency' => 'CZK', 'state' => 'actual',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('trip_route_variants')->insert([
            'trip_id' => $this->tripId, 'created_by' => $this->owner->id, 'title' => 'Vlakem',
            'strategy' => 'cheapest', 'estimated_cost' => 890, 'currency' => 'CZK',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->getJson("/api/v1/trips/{$this->tripId}/planning")->assertOk()->json();

        $this->assertIsNotString($data['expenses'][0]['amount']);
        $this->assertEquals(300, $data['expenses'][0]['amount']);
        $this->assertIsNotString($data['route_variants'][0]['estimated_cost']);
        $this->assertEquals(890, $data['route_variants'][0]['estimated_cost']);
    }

    public function test_ulozena_a_vybrana_varianta_trasy_vraci_castku_jako_cislo(): void
    {
        $this->cesta('2026-11-01', '2026-11-05');

        $varianta = $this->postJson("/api/v1/trips/{$this->tripId}/route-variants", [
            'title' => 'Autem', 'strategy' => 'fastest', 'estimated_cost' => 1500,
        ])->assertCreated()->json();
        $this->assertIsNotString($varianta['estimated_cost']);
        $this->assertEquals(1500, $varianta['estimated_cost']);

        $vybrana = $this->postJson("/api/v1/trips/{$this->tripId}/route-variants/{$varianta['id']}/select")
            ->assertOk()->json();
        $this->assertIsNotString($vybrana['estimated_cost']);
        $this->assertEquals(1500, $vybrana['estimated_cost']);
    }
}
