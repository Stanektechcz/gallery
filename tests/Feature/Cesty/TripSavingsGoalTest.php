<?php

namespace Tests\Feature\Cesty;

use Illuminate\Support\Facades\DB;

/**
 * Cíl spoření na cestu — `PUT .../savings-goal`.
 *
 * `updateOrInsert` bral `saved_amount ?? 0` při každém uložení, i když formulář
 * pole vůbec neposílal (mění se jen cílová částka nebo datum) — uspořeno se
 * tak vynulovalo. `created_at` navíc přepisoval i update, takže cíl vypadal,
 * jako by vznikl znovu při každé úpravě.
 */
class TripSavingsGoalTest extends CestyTestCase
{
    /** Úprava cílové částky bez pole `saved_amount` uspořené nevynuluje. */
    public function test_zmena_cile_nevynuluje_nasporeno(): void
    {
        $this->cesta('2026-11-01', '2026-11-05');

        DB::table('trip_savings_goals')->insert([
            'trip_id' => $this->tripId, 'target_amount' => 10000, 'saved_amount' => 1200,
            'currency' => 'CZK', 'created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10),
        ]);
        $puvodniZalozeno = DB::table('trip_savings_goals')->where('trip_id', $this->tripId)->value('created_at');

        $this->putJson("/api/v1/trips/{$this->tripId}/savings-goal", ['target_amount' => 15000])
            ->assertOk()
            ->assertJsonPath('saved_amount', 1200)
            ->assertJsonPath('target_amount', 15000);

        $radek = DB::table('trip_savings_goals')->where('trip_id', $this->tripId)->first();
        $this->assertSame(1200.0, (float) $radek->saved_amount);
        $this->assertSame((string) $puvodniZalozeno, (string) $radek->created_at);
    }

    /** Pole `saved_amount` v požadavku uspořeno pořád nastaví — vklad se zapsat musí. */
    public function test_poslane_usporeno_se_ulozi(): void
    {
        $this->cesta('2026-11-01', '2026-11-05');

        DB::table('trip_savings_goals')->insert([
            'trip_id' => $this->tripId, 'target_amount' => 10000, 'saved_amount' => 1200,
            'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->putJson("/api/v1/trips/{$this->tripId}/savings-goal", ['target_amount' => 10000, 'saved_amount' => 3000])
            ->assertOk()
            ->assertJsonPath('saved_amount', 3000);
    }

    /** Nový cíl bez `saved_amount` založí s nulou, ne s chybou. */
    public function test_novy_cil_bez_usporeneho_zacina_na_nule(): void
    {
        $this->cesta('2026-11-01', '2026-11-05');

        $this->putJson("/api/v1/trips/{$this->tripId}/savings-goal", ['target_amount' => 20000])
            ->assertOk()
            ->assertJsonPath('saved_amount', 0)
            ->assertJsonPath('target_amount', 20000);
    }
}
