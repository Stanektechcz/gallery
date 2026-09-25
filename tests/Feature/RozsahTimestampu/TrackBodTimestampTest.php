<?php

namespace Tests\Feature\RozsahTimestampu;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Cesty\CestyTestCase;

/**
 * `trip_track_points.recorded_at` je sloupec MySQL `TIMESTAMP` a vkládal se
 * jako syrový řetězec z prohlížeče — ISO tvar s `Z` nebo posunem pásma by na
 * MySQL zápis pravděpodobně odmítl (SQLite v testech to nepozná). Ukládá se
 * proto přepočtený na UTC ve tvaru `Y-m-d H:i:s`, mimo rozsah 1970–2038 se
 * zápis odmítne s 422 místo pádu na 500.
 */
class TrackBodTimestampTest extends CestyTestCase
{
    public function test_bod_s_iso_casem_a_z_se_ulozi_prepocitany_na_utc(): void
    {
        $tripId = $this->cesta('2026-10-10', '2026-10-12');

        $this->postJson("/api/v1/trips/{$tripId}/track-points", [
            'latitude' => 48.2,
            'longitude' => 16.37,
            'recorded_at' => '2026-09-25T10:00:00Z',
        ])->assertCreated();

        $this->assertSame('2026-09-25 10:00:00',
            DB::table('trip_track_points')->where('trip_id', $tripId)->value('recorded_at'));
    }

    public function test_bod_s_datem_mimo_rozsah_dostane_422(): void
    {
        $tripId = $this->cesta('2026-10-10', '2026-10-12');

        $this->postJson("/api/v1/trips/{$tripId}/track-points", [
            'latitude' => 48.2,
            'longitude' => 16.37,
            'recorded_at' => '1965-06-01',
        ])->assertStatus(422)->assertJsonValidationErrors('recorded_at');
    }
}
