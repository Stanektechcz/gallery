<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Travel\TripReservationImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TripReservationImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $partner;
    private GallerySpace $space;
    private int $tripId;
    private int $dayId;
    private int $tripEventId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->partner = User::factory()->create(['role' => 'partner']);
        $this->space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Cesty', 'slug' => 'cesty', 'owner_id' => $this->owner->id]);
        $this->space->members()->attach($this->owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->space->members()->attach($this->partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $start = now()->addMonth()->startOfDay();
        $this->tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Vídeň', 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDay()->toDateString(), 'timezone' => 'Europe/Prague', 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $this->dayId = DB::table('trip_days')->insertGetId(['trip_id' => $this->tripId, 'date' => $start->toDateString(), 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->tripEventId = DB::table('calendar_events')->insertGetId(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'trip_id' => $this->tripId, 'title' => 'Vídeň', 'type' => 'trip', 'status' => 'planned', 'starts_at' => $start, 'ends_at' => $start->copy()->addDay(), 'timezone' => 'Europe/Prague', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->owner);
    }

    public function test_reviewed_reservation_is_projected_across_the_existing_trip_system_without_duplicates(): void
    {
        $date = now()->addMonth()->format('d.m.Y');
        $text = "RegioJet\nKód rezervace: RJABC42\nOdjezd: Brno hlavní nádraží\nPříjezd: Wien Hbf\n{$date} 07:30\n{$date} 09:10\nCena: 499 CZK";
        $created = $this->postJson("/api/v1/trips/{$this->tripId}/reservation-imports", ['source_text' => $text])
            ->assertCreated()->assertJsonPath('duplicate', false)->assertJsonPath('import.extracted_data.provider', 'RegioJet')
            ->assertJsonPath('import.extracted_data.reference', 'RJABC42')->json('import');
        $this->assertStringNotContainsString('RegioJet', DB::table('trip_reservation_imports')->where('uuid', $created['uuid'])->value('source_text'));

        $this->postJson("/api/v1/trips/{$this->tripId}/reservation-imports", ['source_text' => $text])
            ->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('import.uuid', $created['uuid']);

        $payload = ['type' => 'ticket', 'title' => 'RegioJet Brno → Vídeň', 'provider' => 'RegioJet', 'reference' => 'RJABC42',
            'starts_at' => now()->addMonth()->setTime(7, 30)->toDateTimeString(), 'ends_at' => now()->addMonth()->setTime(9, 10)->toDateTimeString(),
            'origin' => 'Brno hlavní nádraží', 'destination' => 'Wien Hbf', 'amount' => 499, 'currency' => 'CZK',
            'trip_day_id' => $this->dayId, 'sync_itinerary' => true, 'sync_calendar' => true, 'reminder_hours' => [24, 2]];
        $this->putJson("/api/v1/trips/{$this->tripId}/reservation-imports/{$created['uuid']}/confirm", $payload)
            ->assertOk()->assertJsonPath('status', 'confirmed')->assertJsonPath('confirmed_data.reference', 'RJABC42');

        $this->assertDatabaseHas('trip_document_checks', ['trip_id' => $this->tripId, 'type' => 'ticket', 'reference' => 'RJABC42', 'status' => 'ready']);
        $this->assertDatabaseHas('trip_activities', ['trip_day_id' => $this->dayId, 'type' => 'transport', 'title' => 'RegioJet Brno → Vídeň', 'cost' => 499]);
        $this->assertDatabaseHas('travel_inbox_items', ['trip_id' => $this->tripId, 'trip_day_id' => $this->dayId, 'kind' => 'reservation', 'state' => 'assigned']);
        $event = DB::table('calendar_events')->where('trip_id', $this->tripId)->where('type', 'reservation')->first();
        $this->assertNotNull($event);
        $this->assertDatabaseHas('event_participants', ['event_id' => $event->id, 'user_id' => $this->partner->id]);
        $this->assertSame(4, DB::table('event_reminders')->where('event_id', $event->id)->where('automation_source', 'reservation_import')->count());
        $this->assertSame(0, DB::table('event_tasks')->where('event_id', $event->id)->where('automation_source', 'trip_preparation')->count());
        $this->assertGreaterThan(0, DB::table('event_tasks')->where('event_id', $this->tripEventId)->where('automation_source', 'trip_preparation')->count());
        $this->getJson("/api/v1/trips/{$this->tripId}/offline-package")->assertOk()
            ->assertJsonPath('reservations.0.reference', 'RJABC42')->assertJsonPath('reservations.0.amount', 499);

        $this->putJson("/api/v1/trips/{$this->tripId}/reservation-imports/{$created['uuid']}/confirm", $payload)->assertOk();
        $this->assertSame(1, DB::table('trip_document_checks')->where('trip_id', $this->tripId)->where('reference', 'RJABC42')->count());
        $this->assertSame(1, DB::table('trip_activities')->where('trip_day_id', $this->dayId)->where('title', 'RegioJet Brno → Vídeň')->count());
        $this->assertSame(1, DB::table('calendar_events')->where('trip_id', $this->tripId)->where('type', 'reservation')->count());
        $this->assertSame(4, DB::table('event_reminders')->where('event_id', $event->id)->where('automation_source', 'reservation_import')->count());
    }

    public function test_parser_does_not_treat_an_unpriced_date_as_a_price(): void
    {
        $trip = (object) ['currency' => 'CZK', 'timezone' => 'Europe/Prague'];
        $data = app(TripReservationImportService::class)->parse('Booking.com rezervace ABCD1234, check-in 27. 8. 2026 v 15:00', 'hotel.pdf', $trip);
        $this->assertSame('accommodation', $data['type']);
        $this->assertNull($data['amount']);
        $this->assertSame('CZK', $data['currency']);
    }

    public function test_non_member_cannot_read_trip_reservation_imports(): void
    {
        $this->postJson("/api/v1/trips/{$this->tripId}/reservation-imports", ['source_text' => 'Rezervace ABCD1234'])->assertCreated();
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->getJson("/api/v1/trips/{$this->tripId}/reservation-imports")->assertNotFound();
    }
}
