<?php

namespace Tests\Feature\Cesty;

use Illuminate\Support\Facades\DB;

/**
 * Připomínky se doručují porovnáním `remind_at` s `now()` v UTC — musí tedy
 * být uložené jako okamžik v UTC. Obrazovka „Teď" patří k dnešku dvojice,
 * ne serveru.
 */
class CasCestyTest extends CestyTestCase
{
    public function test_pripominka_rezervace_je_ulozena_v_utc(): void
    {
        $this->travelTo('2026-10-01 05:00:00');
        $tripId = $this->cesta('2026-10-01', '2026-10-02');
        $import = $this->postJson("/api/v1/trips/{$tripId}/reservation-imports", ['source_text' => 'Rezervace ABCD1234'])->assertCreated()->json('import');

        // 10:00 v Praze (CEST) je 08:00 UTC; dvě hodiny předem = 06:00 UTC.
        $this->putJson("/api/v1/trips/{$tripId}/reservation-imports/{$import['uuid']}/confirm", [
            'type' => 'ticket', 'title' => 'Vlak', 'starts_at' => '2026-10-01 10:00', 'reminder_hours' => [2],
        ])->assertOk();

        $remindAt = DB::table('event_reminders')->where('user_id', $this->owner->id)->where('automation_source', 'reservation_import')->value('remind_at');
        $this->assertSame('2026-10-01 06:00:00', substr((string) $remindAt, 0, 19));
    }

    public function test_pripominka_pripravy_cesty_je_ulozena_v_utc(): void
    {
        $this->travelTo('2026-09-01 08:00:00');
        $tripId = $this->cesta('2026-10-20', '2026-10-21');

        $this->postJson("/api/v1/trips/{$tripId}/preparation-timeline/sync")->assertOk();

        // Hlavní karta začíná 20. 10. v 9:00 pražského času = 07:00 UTC; 120 min předem = 05:00 UTC.
        $remindAt = DB::table('event_reminders')->where('event_id', $this->tripEventId)->where('user_id', $this->owner->id)->where('automation_key', 'trip_start_120')->value('remind_at');
        $this->assertSame('2026-10-20 05:00:00', substr((string) $remindAt, 0, 19));
    }

    public function test_obrazovka_ted_bere_dnesek_dvojice(): void
    {
        // 22:30 UTC 11. 7. je v Praze už 00:30 12. 7.
        $this->travelTo('2026-07-11 22:30:00');
        $tripId = $this->cesta('2026-07-11', '2026-07-12');
        DB::table('trip_days')->insert([
            ['trip_id' => $tripId, 'date' => '2026-07-11', 'title' => 'Den 1', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['trip_id' => $tripId, 'date' => '2026-07-12', 'title' => 'Den 2', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->getJson("/api/v1/trips/{$tripId}/now")->assertOk()->assertJsonPath('day.date', '2026-07-12');

        // Zápis do deníku se přiřadí ke stejnému dni.
        $this->postJson("/api/v1/trips/{$tripId}/journal", ['type' => 'note', 'content' => 'Půlnoční procházka'])->assertCreated();
        $dayId = DB::table('trip_days')->where('trip_id', $tripId)->where('date', '2026-07-12')->value('id');
        $this->assertSame($dayId, (int) DB::table('travel_journal_entries')->where('trip_id', $tripId)->value('trip_day_id'));
    }
}
