<?php

namespace Tests\Feature\Cesty;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Uložení termínu cesty posouvá navázané události kalendáře jen tehdy, když
 * se termín opravdu změnil — a posouvá je o rozdíl, ne na první den.
 */
class TerminCestyTest extends CestyTestCase
{
    private int $reservationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cesta('2026-10-10', '2026-10-12');
        $this->reservationId = DB::table('calendar_events')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'trip_id' => $this->tripId, 'title' => 'Vlak domů', 'type' => 'reservation', 'status' => 'planned',
            'starts_at' => '2026-10-12 18:00:00', 'ends_at' => '2026-10-12 21:30:00',
            'timezone' => 'Europe/Prague', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_ulozeni_se_stejnym_terminem_rezervaci_neposune(): void
    {
        $this->patchJson("/api/v1/trips/{$this->tripId}", ['name' => 'Vídeň a Bratislava', 'start_date' => '2026-10-10', 'end_date' => '2026-10-12'])->assertOk();

        $rezervace = DB::table('calendar_events')->find($this->reservationId);
        $this->assertSame('2026-10-12 18:00:00', substr((string) $rezervace->starts_at, 0, 19));
        $this->assertSame('2026-10-12 21:30:00', substr((string) $rezervace->ends_at, 0, 19));
    }

    public function test_posun_cesty_posune_rezervaci_o_rozdil_a_hlavni_kartu_na_novy_termin(): void
    {
        $pripominka = DB::table('event_reminders')->insertGetId(['event_id' => $this->reservationId, 'user_id' => $this->owner->id, 'channel' => 'database', 'remind_at' => '2026-10-12 14:00:00', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        $this->patchJson("/api/v1/trips/{$this->tripId}", ['start_date' => '2026-10-11', 'end_date' => '2026-10-13'])->assertOk();

        $this->assertSame('2026-10-13 14:00:00', substr((string) DB::table('event_reminders')->where('id', $pripominka)->value('remind_at'), 0, 19));
        $rezervace = DB::table('calendar_events')->find($this->reservationId);
        $this->assertSame('2026-10-13 18:00:00', substr((string) $rezervace->starts_at, 0, 19));
        $this->assertSame('2026-10-13 21:30:00', substr((string) $rezervace->ends_at, 0, 19));
        $hlavni = DB::table('calendar_events')->find($this->tripEventId);
        $this->assertSame('2026-10-11 09:00:00', substr((string) $hlavni->starts_at, 0, 19));
        $this->assertSame('2026-10-13 20:00:00', substr((string) $hlavni->ends_at, 0, 19));
    }

    public function test_prodlouzeni_konce_nechava_rezervace_na_miste(): void
    {
        $this->patchJson("/api/v1/trips/{$this->tripId}", ['end_date' => '2026-10-14'])->assertOk();

        $this->assertSame('2026-10-12 18:00:00', substr((string) DB::table('calendar_events')->where('id', $this->reservationId)->value('starts_at'), 0, 19));
        $this->assertSame('2026-10-14 20:00:00', substr((string) DB::table('calendar_events')->where('id', $this->tripEventId)->value('ends_at'), 0, 19));
    }

    public function test_konec_pred_zacatkem_se_odmitne_i_kdyz_prijde_jen_jedno_datum(): void
    {
        $this->patchJson("/api/v1/trips/{$this->tripId}", ['start_date' => '2026-10-12', 'end_date' => '2026-10-11'])->assertStatus(422)->assertJsonValidationErrors('end_date');
        $this->patchJson("/api/v1/trips/{$this->tripId}", ['end_date' => '2026-10-01'])->assertStatus(422)->assertJsonValidationErrors('end_date');
        $this->patchJson("/api/v1/trips/{$this->tripId}", ['start_date' => '2026-10-20'])->assertStatus(422)->assertJsonValidationErrors('end_date');
        $this->assertSame('2026-10-12', (string) DB::table('trips')->where('id', $this->tripId)->value('end_date'));
    }

    public function test_rozpocet_a_delka_cesty_maji_horni_mez(): void
    {
        $this->patchJson("/api/v1/trips/{$this->tripId}", ['budget' => 99999999999])->assertStatus(422)->assertJsonValidationErrors('budget');
        $this->postJson('/api/v1/trips', ['name' => 'Drahá', 'start_date' => '2026-10-01', 'end_date' => '2026-10-02', 'budget' => 99999999999])->assertStatus(422)->assertJsonValidationErrors('budget');

        // Každý den cesty je řádek v `trip_days` — cesta na tisíc let by je založila všechny.
        $this->postJson('/api/v1/trips', ['name' => 'Nekonečná', 'start_date' => '2026-10-01', 'end_date' => '3026-10-01'])->assertStatus(422)->assertJsonValidationErrors('end_date');
        $this->patchJson("/api/v1/trips/{$this->tripId}", ['end_date' => '2027-12-31'])->assertStatus(422)->assertJsonValidationErrors('end_date');
        $this->postJson('/api/v1/trips', ['name' => 'Rok', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'])->assertCreated();
    }
}
