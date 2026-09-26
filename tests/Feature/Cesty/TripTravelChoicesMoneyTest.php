<?php

namespace Tests\Feature\Cesty;

/**
 * Cestovní volby (doprava, ubytování) — `trip_travel_choices.amount` je
 * `decimal(12,2)` a z MySQL by dorazilo jako řetězec. Test tu na SQLite
 * projde i bez převodu; MySQL v CI teprve prokáže, že kontroler přetypoval.
 */
class TripTravelChoicesMoneyTest extends CestyTestCase
{
    public function test_ulozena_doprava_vraci_castku_jako_cislo(): void
    {
        $this->cesta('2026-11-01', '2026-11-05');

        $volba = $this->postJson("/api/v1/trips/{$this->tripId}/travel-choices/transport", [
            'title' => 'Vlak do Vídně', 'amount' => 690,
        ])->assertCreated()->json();

        $this->assertIsNotString($volba['amount']);
        $this->assertEquals(690, $volba['amount']);
    }

    public function test_seznam_cestovnich_voleb_vraci_castky_jako_cisla(): void
    {
        $this->cesta('2026-11-01', '2026-11-05');
        $this->postJson("/api/v1/trips/{$this->tripId}/travel-choices/transport", [
            'title' => 'Autobus', 'amount' => 350,
        ])->assertCreated();

        $data = $this->getJson("/api/v1/trips/{$this->tripId}/travel-choices")->assertOk()->json();

        $this->assertIsNotString($data[0]['amount']);
        $this->assertEquals(350, $data[0]['amount']);
    }
}
