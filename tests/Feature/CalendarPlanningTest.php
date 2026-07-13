<?php

namespace Tests\Feature;

use App\Console\Commands\DeliverPlanningRemindersCommand;
use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CalendarPlanningTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $partner;
    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->partner = User::factory()->create(['role' => 'partner', 'is_active' => true]);
        $this->space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Společně', 'slug' => 'spolecne', 'owner_id' => $this->owner->id, 'is_default' => true]);
        $this->space->members()->attach($this->owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->space->members()->attach($this->partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->actingAs($this->owner);
    }

    public function test_shared_event_workflow_keeps_data_inside_the_gallery_space(): void
    {
        $event = $this->postJson('/api/v1/calendar/events', [
            'gallery_space_id' => $this->space->id, 'title' => 'Víkend v Brně', 'type' => 'trip',
            'starts_at' => now()->addDays(2)->setTime(10, 0)->toDateTimeString(), 'place_name' => 'Brno',
            'participant_ids' => [$this->partner->id],
            'reminders' => [['minutes_before' => 60, 'channel' => 'database', 'user_id' => $this->partner->id]],
        ])->assertCreated()->json();

        $this->assertDatabaseHas('event_participants', ['event_id' => $event['id'], 'user_id' => $this->partner->id]);
        $this->postJson("/api/v1/calendar/events/{$event['uuid']}/tasks", ['title' => 'Zabalit nabíječku', 'assigned_to' => $this->partner->id])->assertCreated();
        $this->actingAs($this->partner)->getJson("/api/v1/calendar/events/{$event['uuid']}")->assertOk()->assertJsonPath('title', 'Víkend v Brně');
        $this->postJson("/api/v1/calendar/events/{$event['uuid']}/response", ['response' => 'tentative'])->assertOk()->assertJsonPath('my_response', 'tentative');
        $this->assertDatabaseHas('event_participants', ['event_id' => $event['id'], 'user_id' => $this->partner->id, 'response' => 'tentative']);

        $outsider = User::factory()->create(['is_active' => true]);
        $otherSpace = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Jiné', 'slug' => 'jine', 'owner_id' => $outsider->id]);
        $otherSpace->members()->attach($outsider->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->actingAs($outsider)->getJson("/api/v1/calendar/events/{$event['uuid']}")->assertNotFound();
    }

    public function test_trip_budget_and_route_variant_are_persisted(): void
    {
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Jižní Morava', 'start_date' => now()->addMonth()->toDateString(), 'end_date' => now()->addMonth()->addDays(2)->toDateString(), 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson("/api/v1/trips/{$tripId}/expenses", ['title' => 'Hotel', 'category' => 'accommodation', 'amount' => 2400, 'state' => 'planned'])->assertCreated();
        $variant = $this->postJson("/api/v1/trips/{$tripId}/route-variants", ['title' => 'Vlakem přes Prahu', 'strategy' => 'fastest', 'estimated_minutes' => 180])->assertCreated()->json();
        $this->postJson("/api/v1/trips/{$tripId}/route-variants/{$variant['id']}/select")->assertOk();
        $this->getJson("/api/v1/trips/{$tripId}/planning")->assertOk()->assertJsonPath('totals.planned', 2400)->assertJsonPath('route_variants.0.is_selected', 1);
    }

    public function test_due_reminder_and_time_capsule_are_delivered_once(): void
    {
        $event = $this->postJson('/api/v1/calendar/events', ['gallery_space_id' => $this->space->id, 'title' => 'Večeře', 'starts_at' => now()->addMinute()->toDateTimeString(), 'reminders' => [['minutes_before' => 1, 'channel' => 'database']]])->assertCreated()->json();
        $this->postJson('/api/v1/calendar/time-capsules', ['gallery_space_id' => $this->space->id, 'recipient_user_id' => $this->partner->id, 'title' => 'Naše vzpomínka', 'deliver_at' => now()->addMinute()->toDateTimeString()])->assertCreated();
        $this->artisan(DeliverPlanningRemindersCommand::class)->assertSuccessful();
        $this->assertDatabaseHas('event_reminders', ['event_id' => $event['id'], 'status' => 'delivered']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->owner->id]);
        DB::table('time_capsules')->update(['deliver_at' => now()->subMinute()]);
        $this->artisan(DeliverPlanningRemindersCommand::class)->assertSuccessful();
        $this->assertDatabaseHas('time_capsules', ['title' => 'Naše vzpomínka', 'status' => 'delivered']);
    }

    public function test_travel_inbox_item_can_be_safely_assigned_to_a_trip_day_and_activity(): void
    {
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Olomouc', 'start_date' => now()->addWeek()->toDateString(), 'end_date' => now()->addWeek()->addDay()->toDateString(), 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $days = $this->getJson("/api/v1/trips/{$tripId}/plan")->assertOk()->json('days');
        $activity = $this->postJson("/api/v1/trips/{$tripId}/plan/days/{$days[0]['id']}/activities", ['title' => 'Oběd', 'type' => 'activity'])->assertCreated()->json();
        $item = $this->postJson('/api/v1/calendar/inbox', ['gallery_space_id' => $this->space->id, 'title' => 'Rezervace restaurace', 'trip_day_id' => $days[0]['id'], 'trip_activity_id' => $activity['id']])->assertCreated()->json();
        $this->assertDatabaseHas('travel_inbox_items', ['uuid' => $item['uuid'], 'trip_id' => $tripId, 'trip_day_id' => $days[0]['id'], 'trip_activity_id' => $activity['id']]);
        $this->getJson("/api/v1/trips/{$tripId}/plan")->assertOk()->assertJsonPath('days.0.activities.0.inbox_items.0.uuid', $item['uuid']);
    }

    public function test_travel_inbox_cannot_link_a_day_from_another_trip(): void
    {
        $firstTrip = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'První cesta', 'start_date' => now()->addWeek()->toDateString(), 'end_date' => now()->addWeek()->toDateString(), 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $secondTrip = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Druhá cesta', 'start_date' => now()->addWeeks(2)->toDateString(), 'end_date' => now()->addWeeks(2)->toDateString(), 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $secondDay = $this->getJson("/api/v1/trips/{$secondTrip}/plan")->assertOk()->json('days.0.id');

        $this->postJson('/api/v1/calendar/inbox', [
            'gallery_space_id' => $this->space->id,
            'title' => 'Cizí den v itineráři',
            'trip_id' => $firstTrip,
            'trip_day_id' => $secondDay,
        ])->assertUnprocessable();
    }

    public function test_ics_import_creates_safe_events_and_skips_same_uid_on_repeat(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:vecere-2026@example.test\r\nSUMMARY:Společná večeře\r\nDESCRIPTION:Stůl pro dva\\nPřipomenout květiny\r\nLOCATION:Brno\r\nDTSTART;TZID=Europe/Prague:20260815T190000\r\nDTEND;TZID=Europe/Prague:20260815T210000\r\nRRULE:FREQ=WEEKLY;INTERVAL=1;UNTIL=20260901T000000\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:rodinna-oslava@example.test\r\nSUMMARY:Rodinná oslava\r\nDTSTART;VALUE=DATE:20260820\r\nRRULE:FREQ=YEARLY;COUNT=3\r\nEND:VEVENT\r\nEND:VCALENDAR";
        $response = $this->postJson('/api/v1/calendar/ics-import', ['gallery_space_id' => $this->space->id, 'ics' => $ics])
            ->assertOk()->assertJsonPath('created', 2)->assertJsonPath('recurrence_warnings', 1);
        $this->assertDatabaseHas('calendar_events', ['gallery_space_id' => $this->space->id, 'title' => 'Společná večeře', 'place_name' => 'Brno']);
        $this->assertSame(2, DB::table('calendar_events')->count());
        $this->postJson('/api/v1/calendar/ics-import', ['gallery_space_id' => $this->space->id, 'ics' => $ics])
            ->assertOk()->assertJsonPath('created', 0)->assertJsonPath('skipped_duplicates', 2);
    }

    public function test_shared_milestone_is_included_in_the_normal_calendar_period(): void
    {
        $date = now()->addMonth()->toDateString();
        $this->postJson('/api/v1/relationship-milestones', ['gallery_space_id' => $this->space->id, 'title' => 'Naše seznámení', 'occurred_on' => now()->subYears(3)->setMonth((int) substr($date, 5, 2))->setDay((int) substr($date, 8, 2))->toDateString(), 'visibility' => 'shared'])->assertCreated();
        $this->getJson('/api/v1/calendar/events?from=' . now()->addMonth()->startOfMonth()->toDateString() . '&to=' . now()->addMonth()->endOfMonth()->toDateString())
            ->assertOk()->assertJsonPath('milestones.0.title', 'Naše seznámení');
    }

    public function test_calendar_suggests_a_shared_outing_from_saved_place_preferences(): void
    {
        $this->postJson('/api/v1/places', ['name' => 'Galerie na deštivé rande', 'type' => 'museum', 'is_rain_friendly' => true, 'is_photogenic' => true, 'personal_rating' => 5])->assertCreated();
        $this->getJson('/api/v1/calendar/date-ideas?gallery_space_id=' . $this->space->id . '&theme=rain')
            ->assertOk()->assertJsonPath('ideas.0.title', 'Galerie na deštivé rande')->assertJsonPath('ideas.0.reason', 'hodnocení 5/5 · vhodné na déšť · fotogenické');
    }

    public function test_calendar_suggests_private_safe_common_time_slots_for_partners(): void
    {
        $date = now()->addDay()->startOfDay();
        $availability = [['weekday' => $date->dayOfWeek, 'from' => '18:00', 'to' => '21:00']];
        $this->owner->update(['preferences' => ['planning_availability' => $availability]]);
        $this->partner->update(['preferences' => ['planning_availability' => $availability]]);

        $response = $this->getJson('/api/v1/calendar/shared-slots?gallery_space_id=' . $this->space->id . '&from=' . $date->toDateString() . '&days=1&duration_minutes=120')
            ->assertOk()->assertJsonPath('member_count', 2)->assertJsonCount(2, 'member_ids');
        $this->assertNotEmpty($response->json('slots'));
        $this->assertArrayNotHasKey('events', $response->json());
    }

    public function test_completed_calendar_event_can_be_saved_as_a_shared_memory_with_selected_media(): void
    {
        $event = $this->postJson('/api/v1/calendar/events', ['gallery_space_id' => $this->space->id, 'title' => 'Nedělní výlet', 'starts_at' => now()->subDay()->toDateTimeString()])->assertCreated()->json();
        $mediaId = DB::table('media_items')->insertGetId(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id, 'owner_user_id' => $this->owner->id, 'uploaded_by' => $this->owner->id, 'original_filename' => 'vylet.jpg', 'safe_filename' => 'vylet.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 1, 'status' => 'ready', 'storage_status' => 'ready', 'taken_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson("/api/v1/calendar/events/{$event['uuid']}/shared-memory", ['media_ids' => [$mediaId]])->assertCreated()->assertJsonPath('title', 'Nedělní výlet');
        $this->assertDatabaseHas('calendar_events', ['id' => $event['id'], 'status' => 'completed']);
        $this->assertDatabaseHas('shared_memory_moments', ['calendar_event_id' => $event['id'], 'title' => 'Nedělní výlet']);
    }

    public function test_calendar_and_trip_memory_actions_share_one_memory_moment(): void
    {
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Společná Pálava', 'start_date' => now()->subDays(2)->toDateString(), 'end_date' => now()->subDay()->toDateString(), 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $event = $this->postJson('/api/v1/calendar/events', ['gallery_space_id' => $this->space->id, 'trip_id' => $tripId, 'title' => 'Pálava', 'starts_at' => now()->subDay()->toDateTimeString()])->assertCreated()->json();
        $mediaId = DB::table('media_items')->insertGetId(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id, 'owner_user_id' => $this->owner->id, 'uploaded_by' => $this->owner->id, 'original_filename' => 'palava.jpg', 'safe_filename' => 'palava.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 1, 'status' => 'ready', 'storage_status' => 'ready', 'taken_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('trip_media')->insert(['trip_id' => $tripId, 'media_item_id' => $mediaId, 'added_at' => now()]);

        $this->postJson("/api/v1/trips/{$tripId}/shared-memory", ['media_item_ids' => [$mediaId]])->assertCreated();
        $this->postJson("/api/v1/calendar/events/{$event['uuid']}/shared-memory", ['media_ids' => [$mediaId]])->assertCreated();
        $this->assertDatabaseCount('shared_memory_moments', 1);
        $this->assertDatabaseHas('shared_memory_moments', ['trip_id' => $tripId, 'calendar_event_id' => $event['id']]);
    }

    public function test_shared_memories_can_be_scheduled_as_a_reminded_evening_for_both_partners(): void
    {
        $moment = $this->postJson('/api/v1/shared-memory-moments', ['gallery_space_id' => $this->space->id, 'title' => 'Naše Jeseníky', 'happened_on' => now()->subMonth()->toDateString()])->assertCreated()->json();
        $event = $this->postJson('/api/v1/calendar/memory-evening', ['gallery_space_id' => $this->space->id, 'scheduled_at' => now()->addWeek()->setTime(19, 0)->toDateTimeString(), 'moment_uuids' => [$moment['uuid']]])
            ->assertCreated()->assertJsonPath('title', 'Večer se vzpomínkami')->json();

        $this->assertDatabaseHas('calendar_events', ['id' => $event['id'], 'type' => 'event']);
        $this->assertDatabaseHas('event_participants', ['event_id' => $event['id'], 'user_id' => $this->owner->id, 'response' => 'accepted']);
        $this->assertDatabaseHas('event_participants', ['event_id' => $event['id'], 'user_id' => $this->partner->id, 'response' => 'pending']);
        $this->assertDatabaseCount('event_reminders', 2);
    }

    public function test_weekly_overview_surfaces_shared_gallery_media_from_the_same_day_in_previous_years(): void
    {
        $mediaId = DB::table('media_items')->insertGetId(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id, 'owner_user_id' => $this->owner->id, 'uploaded_by' => $this->owner->id, 'original_filename' => 'vyroci.jpg', 'safe_filename' => 'vyroci.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 1, 'status' => 'ready', 'storage_status' => 'ready', 'taken_at' => now()->subYears(2), 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/api/v1/calendar/weekly-overview')->assertOk()->assertJsonPath('on_this_day.0.id', $mediaId);
    }

    public function test_completed_event_memory_creates_and_populates_the_linked_gallery_album(): void
    {
        $event = $this->postJson('/api/v1/calendar/events', [
            'gallery_space_id' => $this->space->id,
            'title' => 'Shared experience',
            'starts_at' => now()->subDay()->toDateTimeString(),
            'place_name' => 'Brno',
        ])->assertCreated()->json();
        $mediaId = DB::table('media_items')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id,
            'owner_user_id' => $this->owner->id, 'uploaded_by' => $this->owner->id,
            'original_filename' => 'experience.jpg', 'safe_filename' => 'experience.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'media_type' => 'photo',
            'size_bytes' => 42, 'status' => 'ready', 'storage_status' => 'ready',
            'taken_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $memory = $this->postJson("/api/v1/calendar/events/{$event['uuid']}/shared-memory", ['media_ids' => [$mediaId]])
            ->assertCreated()->assertJsonPath('album.title', 'Shared experience')->json();

        $albumId = DB::table('albums')->where('uuid', $memory['album']['uuid'])->value('id');
        $this->assertDatabaseHas('calendar_events', ['id' => $event['id'], 'album_id' => $albumId, 'status' => 'completed']);
        $this->assertDatabaseHas('albums', ['id' => $albumId, 'event_mode' => true, 'media_count' => 1, 'location_name' => 'Brno']);
        $this->assertDatabaseHas('album_media', ['album_id' => $albumId, 'media_item_id' => $mediaId]);
        $this->getJson("/api/v1/calendar/events/{$event['uuid']}")
            ->assertOk()
            ->assertJsonPath('album.uuid', $memory['album']['uuid'])
            ->assertJsonPath('album.title', 'Shared experience');
        $this->getJson('/api/v1/shared-memory-moments')
            ->assertOk()
            ->assertJsonPath('0.calendar_event.uuid', $event['uuid'])
            ->assertJsonPath('0.album.uuid', $memory['album']['uuid']);
    }

    public function test_calendar_event_can_be_promoted_to_single_trip_workspace(): void
    {
        $event = $this->postJson('/api/v1/calendar/events', ['gallery_space_id' => $this->space->id, 'title' => 'Víkend v Liberci', 'starts_at' => now()->addMonth()->toDateTimeString(), 'ends_at' => now()->addMonth()->addDays(2)->toDateTimeString(), 'place_name' => 'Liberec'])->assertCreated()->json();
        $trip = $this->postJson("/api/v1/calendar/events/{$event['uuid']}/trip")->assertCreated()->json();
        $this->assertDatabaseHas('calendar_events', ['id' => $event['id'], 'trip_id' => $trip['id']]);
        $this->assertDatabaseHas('trip_waypoints', ['trip_id' => $trip['id'], 'place_name' => 'Liberec']);
        $this->assertDatabaseHas('event_tasks', ['event_id' => $event['id'], 'title' => 'Dokončit balení na cestu']);
        $this->postJson("/api/v1/calendar/events/{$event['uuid']}/trip")->assertOk()->assertJsonPath('id', $trip['id']);
    }

    public function test_linked_calendar_event_and_trip_keep_their_dates_in_sync_both_ways(): void
    {
        $eventStart = now()->addMonths(2)->startOfSecond();
        $updatedStart = now()->addMonths(3)->startOfSecond();
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Synchronizace', 'start_date' => now()->addMonth()->toDateString(), 'end_date' => now()->addMonth()->addDay()->toDateString(), 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $event = $this->postJson('/api/v1/calendar/events', ['gallery_space_id' => $this->space->id, 'trip_id' => $tripId, 'title' => 'Synchronizace', 'starts_at' => $eventStart->toDateTimeString(), 'ends_at' => $eventStart->copy()->addDays(3)->toDateTimeString()])->assertCreated()->json();
        $this->assertDatabaseHas('trips', ['id' => $tripId, 'start_date' => $eventStart->toDateString(), 'end_date' => $eventStart->copy()->addDays(3)->toDateString()]);
        $this->patchJson("/api/v1/trips/{$tripId}", ['start_date' => $updatedStart->toDateString(), 'end_date' => $updatedStart->copy()->addDays(2)->toDateString()])->assertOk();
        $this->assertDatabaseHas('calendar_events', ['id' => $event['id'], 'starts_at' => $updatedStart->format('Y-m-d H:i:s'), 'ends_at' => $updatedStart->copy()->addDays(2)->format('Y-m-d H:i:s')]);
    }

    public function test_calendar_reservation_is_automatically_available_in_linked_trip_documents(): void
    {
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Rezervace', 'start_date' => now()->addMonth()->toDateString(), 'end_date' => now()->addMonth()->addDay()->toDateString(), 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $event = $this->postJson('/api/v1/calendar/events', ['gallery_space_id' => $this->space->id, 'trip_id' => $tripId, 'title' => 'Hotel', 'starts_at' => now()->addMonth()->toDateTimeString()])->assertCreated()->json();
        $this->postJson("/api/v1/calendar/events/{$event['uuid']}/attachments", ['kind' => 'reservation', 'label' => 'Hotel Ještěd', 'reference_code' => 'ABC123'])->assertCreated();
        $this->assertDatabaseHas('trip_document_checks', ['trip_id' => $tripId, 'title' => 'Hotel Ještěd', 'reference' => 'ABC123', 'type' => 'booking', 'status' => 'ready']);
    }
}
