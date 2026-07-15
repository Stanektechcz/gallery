<?php

namespace Tests\Feature;

use App\Console\Commands\DeliverPlanningRemindersCommand;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

    public function test_travel_inbox_reservation_is_promoted_inside_the_trip_without_duplicates_or_overwriting_manual_changes(): void
    {
        $tripId = DB::table('trips')->insertGetId([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'name' => 'Víkend v Praze', 'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->addDay()->toDateString(), 'currency' => 'CZK',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $dayId = $this->getJson("/api/v1/trips/{$tripId}/plan")->assertOk()->json('days.0.id');
        $item = $this->postJson('/api/v1/calendar/inbox', [
            'gallery_space_id' => $this->space->id, 'title' => 'Rezervace Národního divadla',
            'notes' => 'Vyzvednout vstupenky nejpozději 30 minut před začátkem.',
            'source_url' => 'https://example.test/rezervace-divadlo', 'kind' => 'reservation',
        ])->assertCreated()->assertJsonPath('state', 'inbox')->json();

        $promoted = $this->postJson("/api/v1/trips/{$tripId}/plan/days/{$dayId}/inbox/{$item['uuid']}/promote", [
            'starts_at' => '18:30', 'ends_at' => '21:30', 'place_name' => 'Národní divadlo', 'cost' => 1600,
        ])->assertCreated()->assertJsonPath('created', true)
            ->assertJsonPath('activity.type', 'reservation')
            ->assertJsonPath('activity.metadata.source', 'travel_inbox')
            ->assertJsonPath('activity.inbox_items.0.uuid', $item['uuid']);
        $activityId = (int) $promoted->json('activity.id');

        $this->assertDatabaseHas('travel_inbox_items', [
            'uuid' => $item['uuid'], 'trip_id' => $tripId, 'trip_day_id' => $dayId,
            'trip_activity_id' => $activityId, 'state' => 'assigned',
        ]);
        $this->getJson("/api/v1/trips/{$tripId}/plan")->assertOk()
            ->assertJsonPath('days.0.activities.0.inbox_items.0.source_url', 'https://example.test/rezervace-divadlo');

        $this->patchJson("/api/v1/trips/{$tripId}/plan/activities/{$activityId}", ['title' => 'Naše večerní představení'])
            ->assertOk();
        $this->postJson("/api/v1/trips/{$tripId}/plan/days/{$dayId}/inbox/{$item['uuid']}/promote")
            ->assertOk()->assertJsonPath('created', false)->assertJsonPath('activity.title', 'Naše večerní představení');
        $this->assertSame(1, DB::table('trip_activities')->where('trip_day_id', $dayId)->count());

        $otherTripId = DB::table('trips')->insertGetId([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'name' => 'Jiná cesta', 'start_date' => now()->addMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(), 'currency' => 'CZK',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignItem = $this->postJson('/api/v1/calendar/inbox', [
            'gallery_space_id' => $this->space->id, 'trip_id' => $otherTripId,
            'title' => 'Hotel pro jinou cestu', 'kind' => 'reservation',
        ])->assertCreated()->assertJsonPath('state', 'assigned')->json();
        $this->postJson("/api/v1/trips/{$tripId}/plan/days/{$dayId}/inbox/{$foreignItem['uuid']}/promote")
            ->assertUnprocessable();
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

    public function test_ics_import_can_share_remind_and_promote_multiday_event_to_one_trip(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:letni-cesta@example.test\r\nSUMMARY:Letní cesta do Vídně\r\nDESCRIPTION:Společný prodloužený víkend\r\nCATEGORIES:TRAVEL,DOVOLENÁ\r\nLOCATION:Vídeň\r\nURL:https://example.test/rezervace\r\nDTSTART;VALUE=DATE:20260801\r\nDTEND;VALUE=DATE:20260804\r\nEND:VEVENT\r\nEND:VCALENDAR";

        $response = $this->postJson('/api/v1/calendar/ics-import', [
            'gallery_space_id' => $this->space->id,
            'ics' => $ics,
            'share_with_space' => true,
            'reminder_minutes' => 1440,
            'create_trips' => true,
        ])->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('trips_created', 1)
            ->assertJsonPath('reminders_created', 2)
            ->assertJsonPath('shared_member_count', 1);

        $event = DB::table('calendar_events')->where('gallery_space_id', $this->space->id)->where('title', 'Letní cesta do Vídně')->first();
        $this->assertNotNull($event->trip_id);
        $this->assertSame('trip', $event->type);
        $this->assertSame('2026-08-03 23:59:59', $event->ends_at);
        $this->assertDatabaseCount('event_participants', 2);
        $this->assertDatabaseHas('event_participants', ['event_id' => $event->id, 'user_id' => $this->partner->id, 'response' => 'pending']);
        $this->assertDatabaseCount('event_reminders', 2);
        $this->assertDatabaseHas('trips', ['id' => $event->trip_id, 'name' => 'Letní cesta do Vídně', 'start_date' => '2026-08-01', 'end_date' => '2026-08-03']);
        $this->assertDatabaseCount('trip_days', 3);
        $this->assertDatabaseHas('trip_waypoints', ['trip_id' => $event->trip_id, 'place_name' => 'Vídeň', 'departed_at' => '2026-08-03']);
        $this->assertDatabaseHas('event_attachments', ['event_id' => $event->id, 'external_url' => 'https://example.test/rezervace']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->partner->id]);

        $this->actingAs($this->partner)->getJson("/api/v1/calendar/events/{$event->uuid}")
            ->assertOk()->assertJsonPath('trip_id', $event->trip_id);
        $this->assertSame(1, $response->json('shared_member_count'));
    }

    public function test_czech_holidays_are_in_calendar_and_create_one_shared_trip_workspace(): void
    {
        $calendar = $this->getJson('/api/v1/calendar/events?from=2026-05-01&to=2026-05-31')
            ->assertOk()
            ->assertJsonFragment(['date' => '2026-05-01', 'title' => 'Svátek práce'])
            ->assertJsonFragment(['date' => '2026-05-08', 'title' => 'Den vítězství']);
        $opportunity = collect($calendar->json('holiday_opportunities'))
            ->firstWhere('start_date', '2026-05-01');

        $this->assertNotNull($opportunity);
        $this->assertSame('2026-05-03', $opportunity['end_date']);
        $this->assertSame(3, $opportunity['duration_days']);
        $this->assertSame(0, $opportunity['leave_days_count']);

        $payload = [
            'gallery_space_id' => $this->space->id,
            'start_date' => $opportunity['start_date'],
            'end_date' => $opportunity['end_date'],
        ];
        $event = $this->postJson('/api/v1/calendar/holiday-plan', $payload)
            ->assertCreated()
            ->assertJsonPath('type', 'trip')
            ->assertJsonPath('all_day', true)
            ->json();

        $this->assertNotNull($event['trip_id']);
        $this->assertDatabaseHas('calendar_events', ['id' => $event['id'], 'gallery_space_id' => $this->space->id, 'trip_id' => $event['trip_id']]);
        $this->assertDatabaseHas('event_participants', ['event_id' => $event['id'], 'user_id' => $this->partner->id, 'response' => 'pending']);
        $this->assertDatabaseCount('event_reminders', 2);
        $this->assertDatabaseHas('trips', ['id' => $event['trip_id'], 'start_date' => '2026-05-01', 'end_date' => '2026-05-03']);
        $this->assertDatabaseCount('trip_days', 3);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->partner->id]);

        $this->postJson('/api/v1/calendar/holiday-plan', $payload)->assertOk()->assertJsonPath('id', $event['id']);
        $this->assertSame(1, DB::table('calendar_events')->count());
        $this->assertSame(1, DB::table('trips')->count());
        $this->postJson('/api/v1/calendar/holiday-plan', [
            'gallery_space_id' => $this->space->id,
            'start_date' => '2026-05-02',
            'end_date' => '2026-05-04',
        ])->assertUnprocessable();

        $this->getJson('/api/v1/calendar/events?from=2026-04-01&to=2026-04-30')
            ->assertOk()
            ->assertJsonFragment(['date' => '2026-04-03', 'title' => 'Velký pátek'])
            ->assertJsonFragment(['date' => '2026-04-06', 'title' => 'Velikonoční pondělí']);
    }

    public function test_highlighted_name_days_and_family_birthdays_are_shared_with_calendar(): void
    {
        $calendar = $this->getJson('/api/v1/calendar/events?from=2026-05-01&to=2026-09-30')
            ->assertOk()
            ->assertJsonFragment(['date' => '2026-05-24', 'name' => 'Jana', 'is_highlighted' => true])
            ->assertJsonFragment(['date' => '2026-06-26', 'name' => 'Adrian'])
            ->assertJsonFragment(['date' => '2026-06-30', 'name' => 'Šárka'])
            ->assertJsonFragment(['date' => '2026-07-13', 'name' => 'Markétka', 'official_name' => 'Markéta'])
            ->assertJsonFragment(['date' => '2026-09-26', 'name' => 'Andrea'])
            ->assertJsonFragment(['date' => '2026-09-28', 'name' => 'Vašek', 'official_name' => 'Václav']);

        $this->assertCount(6, $calendar->json('name_days'));

        $birthday = $this->postJson('/api/v1/relationship-milestones', [
            'gallery_space_id' => $this->space->id,
            'kind' => 'birthday',
            'person_name' => 'Maminka',
            'relationship' => 'parent',
            'occurred_on' => '1970-08-20',
            'visibility' => 'shared',
            'remind_annually' => true,
        ])->assertCreated()
            ->assertJsonPath('title', 'Narozeniny: Maminka')
            ->assertJsonPath('kind', 'birthday')
            ->assertJsonPath('relationship', 'parent')
            ->assertJsonPath('is_highlighted', 1)
            ->json();

        $this->getJson('/api/v1/calendar/events?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertJsonFragment([
                'uuid' => $birthday['uuid'],
                'occurrence_date' => '2026-08-20',
                'kind' => 'birthday',
                'person_name' => 'Maminka',
                'relationship' => 'parent',
            ]);

        $celebration = $this->postJson("/api/v1/relationship-milestones/{$birthday['uuid']}/celebration", [
            'starts_at' => now()->addMonth()->setTime(16, 0)->toDateTimeString(),
            'reminder_minutes' => 1440,
        ])->assertCreated()->json();

        $this->assertDatabaseHas('calendar_events', ['id' => $celebration['id'], 'type' => 'birthday', 'color' => '#f59e0b']);
        $this->assertDatabaseHas('event_participants', ['event_id' => $celebration['id'], 'user_id' => $this->partner->id]);
        $this->assertDatabaseHas('event_reminders', ['event_id' => $celebration['id'], 'user_id' => $this->partner->id]);
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
            ->assertCreated()->assertJsonPath('title', 'Večer se vzpomínkami')->assertJsonPath('origin.kind', 'memory_evening')->assertJsonPath('origin.moments.0.uuid', $moment['uuid'])->json();

        $this->assertDatabaseHas('calendar_events', ['id' => $event['id'], 'type' => 'event']);
        $this->assertDatabaseHas('event_participants', ['event_id' => $event['id'], 'user_id' => $this->owner->id, 'response' => 'accepted']);
        $this->assertDatabaseHas('event_participants', ['event_id' => $event['id'], 'user_id' => $this->partner->id, 'response' => 'pending']);
        $this->assertDatabaseCount('event_reminders', 2);
        $this->postJson('/api/v1/calendar/memory-evening', ['gallery_space_id' => $this->space->id, 'scheduled_at' => now()->addWeek()->setTime(19, 0)->toDateTimeString(), 'moment_uuids' => [$moment['uuid']]])
            ->assertOk()->assertJsonPath('id', $event['id']);
        $this->assertDatabaseCount('calendar_events', 1);
        $this->assertDatabaseCount('event_reminders', 2);
    }

    public function test_each_partner_can_add_their_own_perspective_to_one_shared_memory_and_story(): void
    {
        $event = $this->actingAs($this->owner)->postJson('/api/v1/calendar/events', [
            'gallery_space_id' => $this->space->id,
            'title' => 'Večer na vyhlídce',
            'starts_at' => now()->subDay()->toDateTimeString(),
        ])->assertCreated()->json();
        $mediaId = DB::table('media_items')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id,
            'owner_user_id' => $this->owner->id, 'uploaded_by' => $this->owner->id,
            'original_filename' => 'vyhlidka.jpg', 'safe_filename' => 'vyhlidka.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'media_type' => 'photo',
            'size_bytes' => 42, 'status' => 'ready', 'storage_status' => 'ready',
            'taken_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $memory = $this->postJson("/api/v1/calendar/events/{$event['uuid']}/shared-memory", ['media_ids' => [$mediaId]])
            ->assertCreated()->json();

        $this->putJson("/api/v1/shared-memory-moments/{$memory['uuid']}/reflection", [
            'mood' => 'grateful', 'note' => 'Pamatuji si hlavně ten společný klid.',
        ])->assertCreated()->assertJsonPath('reflections.0.is_mine', true);
        $this->actingAs($this->partner)->putJson("/api/v1/shared-memory-moments/{$memory['uuid']}/reflection", [
            'mood' => 'funny', 'note' => 'Já zase náš smích cestou dolů.',
        ])->assertCreated()->assertJsonCount(2, 'reflections')->assertJsonPath('reflections.1.is_mine', true);

        $this->actingAs($this->owner)->getJson('/api/v1/shared-memory-moments')
            ->assertOk()->assertJsonPath('0.reflections.0.is_mine', true)->assertJsonPath('0.reflections.1.is_mine', false);
        $this->postJson("/api/v1/calendar/events/{$event['uuid']}/story")->assertOk();
        $albumId = DB::table('calendar_events')->where('id', $event['id'])->value('album_id');
        $text = json_decode(DB::table('album_story_blocks')->where('album_id', $albumId)->where('type', 'text')->value('content'), true)['text'];
        $this->assertStringContainsString('Pamatuji si hlavně ten společný klid.', $text);
        $this->assertStringContainsString('Já zase náš smích cestou dolů.', $text);

        $this->actingAs($this->partner)->deleteJson("/api/v1/shared-memory-moments/{$memory['uuid']}/reflection")
            ->assertOk()->assertJsonCount(1, 'reflections');
        $this->assertDatabaseCount('shared_memory_reflections', 1);
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

    public function test_event_story_reuses_its_album_and_keeps_manual_story_blocks_untouched(): void
    {
        $event = $this->postJson('/api/v1/calendar/events', [
            'gallery_space_id' => $this->space->id,
            'title' => 'Letní večer',
            'starts_at' => now()->subDay()->toDateTimeString(),
            'place_name' => 'Brno',
            'latitude' => 49.1951,
            'longitude' => 16.6068,
        ])->assertCreated()->json();
        $mediaId = DB::table('media_items')->insertGetId([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id,
            'owner_user_id' => $this->owner->id, 'uploaded_by' => $this->owner->id,
            'original_filename' => 'leto.jpg', 'safe_filename' => 'leto.jpg', 'extension' => 'jpg',
            'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 42,
            'status' => 'ready', 'storage_status' => 'ready', 'taken_at' => now()->subDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $memory = $this->postJson("/api/v1/calendar/events/{$event['uuid']}/shared-memory", ['media_ids' => [$mediaId]])->assertCreated()->json();
        $albumId = DB::table('albums')->where('uuid', $memory['album']['uuid'])->value('id');
        DB::table('album_story_blocks')->insert([
            'album_id' => $albumId, 'created_by' => $this->owner->id, 'type' => 'quote',
            'content' => json_encode(['quote' => 'Naše ruční poznámka']), 'sort_order' => 20,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $story = $this->postJson("/api/v1/calendar/events/{$event['uuid']}/story")
            ->assertOk()
            ->assertJsonPath('album.uuid', $memory['album']['uuid'])
            ->assertJsonPath('story_blocks', 4)
            ->json();

        $this->assertSame(4, DB::table('album_story_blocks')->where('album_id', $albumId)->where('type', '!=', 'quote')->count());
        $this->assertDatabaseHas('album_story_blocks', ['album_id' => $albumId, 'type' => 'quote']);
        $this->postJson("/api/v1/calendar/events/{$event['uuid']}/story")->assertOk()->assertJsonPath('story_blocks', $story['story_blocks']);
        $this->assertSame(5, DB::table('album_story_blocks')->where('album_id', $albumId)->count());
    }

    public function test_completed_calendar_event_can_keep_a_shared_reflection(): void
    {
        $event = $this->postJson('/api/v1/calendar/events', ['gallery_space_id' => $this->space->id, 'title' => 'Večer v kině', 'starts_at' => now()->subDay()->toDateTimeString()])->assertCreated()->json();
        $this->putJson("/api/v1/calendar/events/{$event['uuid']}/reflection", ['rating' => 5, 'mood' => 'cozy', 'highlight' => 'Nejhezčí byl společný smích.', 'next_time' => 'Vybrat zase film.'])
            ->assertCreated()->assertJsonPath('rating', 5)->assertJsonPath('mood', 'cozy');
        $this->getJson("/api/v1/calendar/events/{$event['uuid']}/reflection")
            ->assertOk()->assertJsonPath('reflection.highlight', 'Nejhezčí byl společný smích.');
        $this->assertDatabaseHas('calendar_event_reflections', ['calendar_event_id' => $event['id'], 'rating' => 5, 'mood' => 'cozy']);
    }

    public function test_event_reflection_can_schedule_a_shared_revisit_with_reminders(): void
    {
        $event = $this->postJson('/api/v1/calendar/events', ['gallery_space_id' => $this->space->id, 'title' => 'Oblíbená kavárna', 'starts_at' => now()->subDay()->toDateTimeString(), 'place_name' => 'Brno'])->assertCreated()->json();
        $startsAt = now()->addMonth()->setTime(18, 0);
        $revisit = $this->postJson("/api/v1/calendar/events/{$event['uuid']}/revisit", ['starts_at' => $startsAt->toDateTimeString(), 'reminder_minutes' => 1440])
            ->assertCreated()->assertJsonPath('title', 'Znovu spolu: Oblíbená kavárna')->json();
        $this->assertDatabaseHas('calendar_events', ['id' => $revisit['id'], 'place_name' => 'Brno']);
        $this->assertDatabaseHas('event_reminders', ['event_id' => $revisit['id'], 'user_id' => $this->owner->id, 'channel' => 'database']);
        $this->postJson("/api/v1/calendar/events/{$event['uuid']}/revisit", ['starts_at' => $startsAt->toDateTimeString()])
            ->assertOk()->assertJsonPath('id', $revisit['id']);
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

    public function test_calendar_event_can_create_its_trip_workspace_during_setup(): void
    {
        $event = $this->postJson('/api/v1/calendar/events', [
            'gallery_space_id' => $this->space->id,
            'title' => 'Wellness u přehrady',
            'type' => 'trip',
            'starts_at' => now()->addMonth()->setTime(16, 0)->toDateTimeString(),
            'ends_at' => now()->addMonth()->addDay()->setTime(11, 0)->toDateTimeString(),
            'place_name' => 'Infinit Maximus, Brno',
            'latitude' => 49.2301,
            'longitude' => 16.5205,
            'color' => '#0891b2',
            'create_trip' => true,
        ])->assertCreated()->assertJsonPath('color', '#0891b2')->json();

        $this->assertNotEmpty($event['trip_id']);
        $this->assertDatabaseHas('trips', ['id' => $event['trip_id'], 'name' => 'Wellness u přehrady']);
        $this->assertDatabaseHas('trip_waypoints', ['trip_id' => $event['trip_id'], 'place_name' => 'Infinit Maximus, Brno']);
        $this->assertDatabaseHas('event_tasks', ['event_id' => $event['id'], 'title' => 'Zkontrolovat doklady a rezervace']);
    }

    public function test_shared_task_sends_one_due_soon_reminder_to_its_assignee(): void
    {
        $event = $this->postJson('/api/v1/calendar/events', [
            'gallery_space_id' => $this->space->id,
            'title' => 'Příprava na výlet',
            'starts_at' => now()->addDays(2)->toDateTimeString(),
            'participant_ids' => [$this->partner->id],
        ])->assertCreated()->json();
        $task = $this->postJson("/api/v1/calendar/events/{$event['uuid']}/tasks", [
            'title' => 'Zabalit plavky',
            'assigned_to' => $this->partner->id,
            'due_at' => now()->addHour()->toDateTimeString(),
        ])->assertCreated()->json();

        Artisan::call('gallery:planning-followups');

        $this->assertDatabaseHas('event_tasks', ['id' => $task['id']]);
        $this->assertNotNull(DB::table('event_tasks')->where('id', $task['id'])->value('last_reminded_at'));
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->partner->id]);
    }

    public function test_event_detail_keeps_linked_planning_notes_with_the_event_and_trip(): void
    {
        $event = $this->postJson('/api/v1/calendar/events', [
            'gallery_space_id' => $this->space->id,
            'title' => 'Víkendový wellness',
            'starts_at' => now()->addWeeks(2)->toDateTimeString(),
            'create_trip' => true,
        ])->assertCreated()->json();

        $item = $this->postJson('/api/v1/calendar/inbox', [
            'gallery_space_id' => $this->space->id,
            'event_id' => $event['id'],
            'trip_id' => $event['trip_id'],
            'title' => 'Porovnat vstupné do saunového světa',
            'source_url' => 'https://example.test/wellness',
            'kind' => 'link',
        ])->assertCreated()->json();

        $this->getJson("/api/v1/calendar/events/{$event['uuid']}")
            ->assertOk()
            ->assertJsonPath('planning_items.0.uuid', $item['uuid'])
            ->assertJsonPath('planning_items.0.title', 'Porovnat vstupné do saunového světa');
        $this->patchJson("/api/v1/calendar/inbox/{$item['uuid']}", ['state' => 'assigned'])
            ->assertOk()->assertJsonPath('state', 'assigned');
    }

    public function test_event_media_suggestions_are_visual_candidates_and_never_repeat_attached_media(): void
    {
        $event = $this->postJson('/api/v1/calendar/events', [
            'gallery_space_id' => $this->space->id,
            'title' => 'Procházka kolem přehrady',
            'starts_at' => now()->subHour()->toDateTimeString(),
            'ends_at' => now()->addHour()->toDateTimeString(),
        ])->assertCreated()->json();
        $media = MediaItem::create([
            'gallery_space_id' => $this->space->id, 'owner_user_id' => $this->owner->id, 'uploaded_by' => $this->owner->id,
            'original_filename' => 'prehraza.jpg', 'safe_filename' => 'prehraza.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg',
            'media_type' => 'photo', 'size_bytes' => 100, 'status' => 'ready', 'storage_status' => 'local_only', 'taken_at' => now(),
        ]);

        $this->getJson("/api/v1/calendar/events/{$event['uuid']}/media-suggestions")
            ->assertOk()->assertJsonPath('candidates.0.id', $media->id)->assertJsonPath('candidates.0.media_type', 'photo');
        $this->postJson("/api/v1/calendar/events/{$event['uuid']}/media-suggestions", ['media_ids' => [$media->id]])->assertOk();
        $this->getJson("/api/v1/calendar/events/{$event['uuid']}/media-suggestions")
            ->assertOk()->assertJsonCount(0, 'candidates');
    }

    public function test_event_decision_poll_stays_in_the_event_and_never_creates_a_duplicate_calendar_entry(): void
    {
        $event = $this->postJson('/api/v1/calendar/events', [
            'gallery_space_id' => $this->space->id, 'title' => 'Večer ve městě',
            'starts_at' => now()->addWeek()->toDateTimeString(), 'participant_ids' => [$this->partner->id],
        ])->assertCreated()->json();
        $poll = $this->postJson('/api/v1/calendar/polls', [
            'gallery_space_id' => $this->space->id, 'calendar_event_uuid' => $event['uuid'],
            'question' => 'Kam půjdeme na večeři?', 'options' => [['title' => 'Bistro'], ['title' => 'Pizzerie']],
        ])->assertCreated()->json();

        $options = $this->getJson('/api/v1/calendar/polls?event_uuid=' . $event['uuid'])
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.uuid', $poll['uuid'])->json('0.options');
        $this->actingAs($this->partner)->postJson("/api/v1/calendar/polls/{$poll['uuid']}/vote", ['option_id' => $options[0]['id']])->assertOk();
        $this->actingAs($this->owner)->postJson("/api/v1/calendar/polls/{$poll['uuid']}/options/{$options[0]['id']}/plan")
            ->assertOk()->assertJsonPath('uuid', $event['uuid']);

        $this->assertDatabaseHas('decision_polls', ['id' => $poll['id'], 'calendar_event_id' => $event['id'], 'status' => 'decided']);
        $this->assertDatabaseHas('decision_poll_options', ['id' => $options[0]['id'], 'calendar_event_id' => $event['id']]);
        $this->assertSame(1, DB::table('calendar_events')->where('gallery_space_id', $this->space->id)->count());
    }

    public function test_event_time_capsule_keeps_its_message_private_until_it_is_delivered(): void
    {
        $event = $this->postJson('/api/v1/calendar/events', [
            'gallery_space_id' => $this->space->id, 'title' => 'Naše první lázně',
            'starts_at' => now()->addWeek()->toDateTimeString(), 'participant_ids' => [$this->partner->id],
        ])->assertCreated()->json();
        $capsule = $this->postJson('/api/v1/calendar/time-capsules', [
            'gallery_space_id' => $this->space->id, 'event_id' => $event['id'],
            'recipient_user_id' => $this->partner->id, 'title' => 'Otevři za rok',
            'message' => 'Byl to krásný den.', 'deliver_at' => now()->addMonth()->toDateTimeString(),
        ])->assertCreated()->json();

        $this->actingAs($this->partner)->getJson('/api/v1/calendar/time-capsules?event_uuid=' . $event['uuid'])
            ->assertOk()->assertJsonCount(0);
        DB::table('time_capsules')->where('id', $capsule['id'])->update(['status' => 'delivered', 'delivered_at' => now()]);
        $this->actingAs($this->partner)->getJson('/api/v1/calendar/time-capsules?event_uuid=' . $event['uuid'])
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.title', 'Otevři za rok')->assertJsonPath('0.message', 'Byl to krásný den.');
        $this->actingAs($this->owner)->getJson('/api/v1/calendar/time-capsules?event_uuid=' . $event['uuid'])
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.message', 'Byl to krásný den.');
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

    public function test_experience_upload_album_connects_calendar_trip_and_media_without_requiring_gps(): void
    {
        $tripId = DB::table('trips')->insertGetId([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'name' => 'Výlet k přehradě', 'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(), 'currency' => 'CZK',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $event = $this->postJson('/api/v1/calendar/events', [
            'gallery_space_id' => $this->space->id, 'trip_id' => $tripId,
            'title' => 'Odpoledne u přehrady', 'type' => 'outing',
            'starts_at' => now()->subHour()->toDateTimeString(),
            'ends_at' => now()->addHour()->toDateTimeString(),
            'place_name' => 'Brněnská přehrada', 'latitude' => 49.229, 'longitude' => 16.518,
            'participant_ids' => [$this->partner->id],
        ])->assertCreated()->json();
        $media = MediaItem::create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id,
            'owner_user_id' => $this->owner->id, 'uploaded_by' => $this->owner->id,
            'original_filename' => 'prehrada.heic', 'safe_filename' => 'prehrada.heic',
            'extension' => 'heic', 'mime_type' => 'image/heic', 'media_type' => 'photo',
            'size_bytes' => 2048, 'status' => 'ready', 'storage_status' => 'local_only',
            'is_hidden' => false, 'taken_at' => now(), 'uploaded_at' => now(),
        ]);

        $this->getJson("/api/v1/calendar/events/{$event['uuid']}/media-suggestions")
            ->assertOk()
            ->assertJsonPath('candidates.0.uuid', $media->uuid)
            ->assertJsonPath('candidates.0.confidence', 'medium')
            ->assertJsonPath('candidates.0.match_reasons.1', 'bez GPS, spárováno podle času');

        $capture = $this->postJson("/api/v1/calendar/events/{$event['uuid']}/experience-album")
            ->assertCreated()
            ->assertJsonPath('album.title', 'Odpoledne u přehrady')
            ->json();
        $this->postJson("/api/v1/calendar/events/{$event['uuid']}/experience-album")->assertCreated();
        $this->assertSame(1, DB::table('albums')->where('trip_id', $tripId)->count());
        $this->assertDatabaseHas('album_user_permissions', ['album_id' => $capture['album']['id'], 'user_id' => $this->owner->id, 'role' => 'editor']);
        $this->assertDatabaseHas('album_user_permissions', ['album_id' => $capture['album']['id'], 'user_id' => $this->partner->id, 'role' => 'editor']);

        $this->postJson("/api/v1/calendar/events/{$event['uuid']}/media-suggestions", ['media_uuids' => [$media->uuid]])
            ->assertOk()
            ->assertJsonPath('capture.album.id', $capture['album']['id'])
            ->assertJsonPath('capture.attached_media_count', 1);
        $this->assertDatabaseHas('event_attachments', ['event_id' => $event['id'], 'media_item_id' => $media->id, 'kind' => 'memory']);
        $this->assertDatabaseHas('album_media', ['album_id' => $capture['album']['id'], 'media_item_id' => $media->id]);
        $this->assertDatabaseHas('trip_media', ['trip_id' => $tripId, 'media_item_id' => $media->id]);
        $this->assertDatabaseHas('media_items', ['id' => $media->id, 'primary_album_id' => $capture['album']['id']]);
        $this->getJson("/api/v1/calendar/events/{$event['uuid']}")
            ->assertOk()
            ->assertJsonPath('album.id', $capture['album']['id'])
            ->assertJsonPath('experience.attached_media_count', 1);
    }
}
