<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExperienceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $partner;
    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner', 'is_active' => true, 'password' => 'secret-pass']);
        $this->partner = User::factory()->create(['role' => 'partner', 'is_active' => true]);
        $this->space = GallerySpace::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Rodinná galerie', 'slug' => 'rodinna-galerie',
            'owner_id' => $this->owner->id, 'is_default' => true,
        ]);
        foreach ([[$this->owner, 'owner'], [$this->partner, 'editor']] as [$user, $role]) {
            $this->space->members()->attach($user->id, ['role' => $role, 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        }
    }

    public function test_czech_natural_search_is_interpreted_into_filters(): void
    {
        $this->media(['taken_at' => '2025-07-10 12:00:00', 'is_favorite' => true]);
        $this->media(['taken_at' => '2024-07-10 12:00:00', 'is_favorite' => true]);

        $this->actingAs($this->owner)->getJson('/api/v1/search?q=obl%C3%ADben%C3%A9%20fotografie%202025')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('interpreted.filters.favorites_only', true)
            ->assertJsonPath('interpreted.filters.media_type', 'photo');
    }

    public function test_dashboard_unifies_editable_event_tasks_packing_and_planning_items_into_one_action_list(): void
    {
        $event = $this->actingAs($this->owner)->postJson('/api/v1/calendar/events', [
            'gallery_space_id' => $this->space->id,
            'title' => 'Víkend v Olomouci',
            'starts_at' => now()->addWeek()->toDateTimeString(),
        ])->assertCreated()->json();
        $this->postJson("/api/v1/calendar/events/{$event['uuid']}/tasks", [
            'title' => 'Koupit vstupenky', 'due_at' => now()->addDays(2)->toDateTimeString(),
        ])->assertCreated();
        $tripId = DB::table('trips')->insertGetId([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'name' => 'Víkend v Olomouci', 'start_date' => now()->addWeeks(2)->toDateString(),
            'end_date' => now()->addWeeks(2)->addDays(2)->toDateString(), 'currency' => 'CZK',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('trip_packing_items')->insert([
            'uuid' => (string) Str::uuid(), 'trip_id' => $tripId, 'created_by' => $this->owner->id,
            'title' => 'Občanský průkaz', 'category' => 'documents', 'quantity' => 1,
            'is_essential' => true, 'is_packed' => false, 'sort_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson('/api/v1/calendar/inbox', [
            'gallery_space_id' => $this->space->id, 'title' => 'Porovnat ubytování', 'trip_id' => $tripId, 'kind' => 'idea',
        ])->assertCreated();

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->has('data.partner_hub.next_actions', 3)
            ->where('data.partner_hub.next_actions.0.type', 'event_task')
            ->where('data.partner_hub.next_actions.1.type', 'packing_item')
            ->where('data.partner_hub.next_actions.2.type', 'planning_item'));
    }

    public function test_private_saved_view_cannot_be_changed_by_another_member(): void
    {
        $view = SavedSearch::create([
            'user_id' => $this->owner->id, 'gallery_space_id' => $this->space->id,
            'name' => 'Soukromý výběr', 'filters_json' => ['favorites_only' => true],
        ]);

        $this->actingAs($this->partner)
            ->patchJson("/api/v1/saved-searches/{$view->id}", ['is_pinned' => true])
            ->assertNotFound();
    }

    public function test_trip_plan_creates_days_and_accepts_workspace_blocks(): void
    {
        $tripId = DB::table('trips')->insertGetId([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'name' => 'Vídeň', 'start_date' => '2026-08-01', 'end_date' => '2026-08-03',
            'status' => 'planned', 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $plan = $this->actingAs($this->owner)->getJson("/api/v1/trips/{$tripId}/plan")
            ->assertOk()->assertJsonCount(3, 'days');
        $dayId = $plan->json('days.0.id');

        $first = $this->postJson("/api/v1/trips/{$tripId}/plan/days/{$dayId}/activities", [
            'type' => 'reservation', 'title' => 'Schönbrunn', 'starts_at' => '10:00',
            'ends_at' => '12:00', 'cost' => 780, 'currency' => 'CZK',
        ])->assertCreated()->assertJsonPath('title', 'Schönbrunn');
        $second = $this->postJson("/api/v1/trips/{$tripId}/plan/days/{$dayId}/activities", [
            'type' => 'transport', 'title' => 'Přesun do centra', 'starts_at' => '13:00', 'currency' => 'CZK',
        ])->assertCreated();

        $this->putJson("/api/v1/trips/{$tripId}/plan/days/{$dayId}/activities/reorder", [
            'order' => [$second->json('id'), $first->json('id')],
        ])->assertOk()->assertJsonPath('reordered', 2);
        $this->patchJson("/api/v1/trips/{$tripId}/plan/activities/{$first->json('id')}", [
            'title' => 'Schönbrunn – nový čas', 'starts_at' => '09:30', 'ends_at' => '11:30',
        ])->assertOk()->assertJsonPath('title', 'Schönbrunn – nový čas');
        $this->assertDatabaseHas('trip_activities', ['id' => $second->json('id'), 'sort_order' => 0]);
    }

    public function test_memory_engine_returns_on_this_day_and_accepts_feedback(): void
    {
        $this->media(['taken_at' => now()->subYear()->setTime(12, 0), 'is_favorite' => true]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/memories')
            ->assertOk();
        $memory = collect($response->json())->firstWhere('type', 'on_this_day');
        $this->assertNotNull($memory);

        $this->postJson('/api/v1/memories/interactions', [
            'fingerprint' => $memory['fingerprint'], 'memory_type' => 'on_this_day', 'action' => 'dismissed',
        ])->assertOk();

        $this->getJson('/api/v1/memories')->assertOk()->assertJsonMissing(['fingerprint' => $memory['fingerprint']]);
    }

    public function test_private_vault_requires_password_before_listing_hidden_media(): void
    {
        $mediaId = $this->media([]);
        $uuid = DB::table('media_items')->where('id', $mediaId)->value('uuid');

        $this->actingAs($this->owner)->postJson("/vault/media/{$uuid}/toggle")
            ->assertOk()->assertJsonPath('is_hidden', true);
        $this->get("/media/{$uuid}")->assertRedirect('/vault');
        $this->get('/vault')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Vault/Gate'));

        $this->post('/vault/unlock', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post('/vault/unlock', ['password' => 'secret-pass'])->assertRedirect('/vault');
        $this->get('/vault')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Vault/Index')
            ->where('media.data.0.uuid', $uuid));
    }

    public function test_media_detail_links_back_to_its_shared_event_trip_and_memory(): void
    {
        $mediaId = $this->media();
        $mediaUuid = DB::table('media_items')->where('id', $mediaId)->value('uuid');
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'name' => 'Pálava spolu', 'start_date' => now()->subDays(2)->toDateString(), 'end_date' => now()->subDay()->toDateString(), 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $eventUuid = (string) Str::uuid();
        $eventId = DB::table('calendar_events')->insertGetId(['uuid' => $eventUuid, 'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'trip_id' => $tripId, 'title' => 'Západ slunce na Pálavě', 'type' => 'outing', 'status' => 'completed', 'starts_at' => now()->subDay(), 'timezone' => 'Europe/Prague', 'is_private' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('event_attachments')->insert(['event_id' => $eventId, 'media_item_id' => $mediaId, 'kind' => 'memory', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('trip_media')->insert(['trip_id' => $tripId, 'media_item_id' => $mediaId, 'added_at' => now()]);
        DB::table('shared_memory_moments')->insert(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'calendar_event_id' => $eventId, 'trip_id' => $tripId, 'title' => 'Náš západ slunce', 'happened_on' => now()->subDay()->toDateString(), 'media_item_ids' => json_encode([$mediaId]), 'is_favorite' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->owner)->get("/media/{$mediaUuid}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Media/Show')
            ->where('media.experience_links.events.0.uuid', $eventUuid)
            ->where('media.experience_links.trips.0.id', $tripId)
            ->where('media.experience_links.memories.0.title', 'Náš západ slunce'));
    }

    public function test_media_can_find_and_attach_an_editable_event_from_its_capture_time(): void
    {
        $capturedAt = now()->setTime(18, 30);
        $mediaId = $this->media(['taken_at' => $capturedAt]);
        $mediaUuid = DB::table('media_items')->where('id', $mediaId)->value('uuid');
        $eventUuid = (string) Str::uuid();
        $eventId = DB::table('calendar_events')->insertGetId([
            'uuid' => $eventUuid, 'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'title' => 'Večer u přehrady', 'type' => 'outing', 'status' => 'planned',
            'starts_at' => $capturedAt->copy()->subHour(), 'ends_at' => $capturedAt->copy()->addHour(),
            'timezone' => 'Europe/Prague', 'is_private' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->owner)->getJson("/api/v1/media/{$mediaUuid}/event-suggestions")
            ->assertOk()
            ->assertJsonPath('events.0.uuid', $eventUuid)
            ->assertJsonPath('events.0.already_linked', false);

        $this->postJson("/api/v1/calendar/events/{$eventUuid}/media-suggestions", ['media_ids' => [$mediaId]])
            ->assertOk();

        $this->assertDatabaseHas('event_attachments', ['event_id' => $eventId, 'media_item_id' => $mediaId]);
        $this->getJson("/api/v1/media/{$mediaUuid}/event-suggestions")
            ->assertOk()
            ->assertJsonPath('events.0.already_linked', true);
    }

    public function test_milestone_can_use_a_gallery_photo_as_its_primary_memory(): void
    {
        $mediaId = $this->media();
        $mediaUuid = DB::table('media_items')->where('id', $mediaId)->value('uuid');

        $milestone = $this->actingAs($this->owner)->postJson('/api/v1/relationship-milestones', [
            'gallery_space_id' => $this->space->id,
            'title' => 'Naše první dovolená',
            'occurred_on' => now()->subYear()->toDateString(),
            'media_item_id' => $mediaId,
            'visibility' => 'shared',
        ])->assertCreated()
            ->assertJsonPath('media.uuid', $mediaUuid)
            ->json();

        $this->actingAs($this->partner)->getJson('/api/v1/relationship-milestones')
            ->assertOk()
            ->assertJsonPath('0.media.uuid', $mediaUuid);

        $this->actingAs($this->owner)->patchJson("/api/v1/relationship-milestones/{$milestone['uuid']}", [
            'media_item_id' => null,
        ])->assertOk()
            ->assertJsonPath('media', null);
    }

    public function test_gps_memory_can_create_a_shared_revisit_event_with_both_reminders(): void
    {
        $mediaId = $this->media(['latitude' => 49.1951, 'longitude' => 16.6068, 'display_title' => 'Večer u přehrady']);
        $mediaUuid = DB::table('media_items')->where('id', $mediaId)->value('uuid');
        $startsAt = now()->addMonth()->setTime(18, 30);

        $this->actingAs($this->owner)->getJson("/api/v1/media/{$mediaUuid}/revisit-suggestions")
            ->assertOk()
            ->assertJsonPath('source', $mediaUuid);

        $event = $this->postJson("/api/v1/media/{$mediaUuid}/revisit-suggestions", [
            'title' => 'Znovu spolu u přehrady',
            'place_name' => 'Brněnská přehrada',
            'starts_at' => $startsAt->toDateTimeString(),
            'reminder_minutes' => 1440,
        ])->assertCreated()
            ->assertJsonPath('title', 'Znovu spolu u přehrady')
            ->assertJsonPath('source_media_uuid', $mediaUuid)
            ->json();

        $this->assertDatabaseHas('event_attachments', ['event_id' => $event['id'], 'media_item_id' => $mediaId, 'kind' => 'memory']);
        $this->assertDatabaseHas('event_reminders', ['event_id' => $event['id'], 'user_id' => $this->owner->id, 'channel' => 'database']);
        $this->assertDatabaseHas('event_reminders', ['event_id' => $event['id'], 'user_id' => $this->partner->id, 'channel' => 'database']);
        $this->actingAs($this->partner)->getJson("/api/v1/calendar/events/{$event['uuid']}")->assertOk()
            ->assertJsonPath('origin.kind', 'media_revisit')
            ->assertJsonPath('origin.media.uuid', $mediaUuid);

        $this->actingAs($this->owner)->postJson("/api/v1/media/{$mediaUuid}/revisit-suggestions", [
            'starts_at' => $startsAt->toDateTimeString(),
        ])->assertOk()->assertJsonPath('id', $event['id']);
    }

    public function test_shared_milestone_can_schedule_a_celebration_with_its_primary_memory(): void
    {
        $mediaId = $this->media();
        $milestone = $this->actingAs($this->owner)->postJson('/api/v1/relationship-milestones', [
            'gallery_space_id' => $this->space->id,
            'title' => 'Naše výročí',
            'occurred_on' => now()->subYears(2)->toDateString(),
            'media_item_id' => $mediaId,
            'visibility' => 'shared',
        ])->assertCreated()->json();
        $startsAt = now()->addMonths(2)->setTime(19, 0);

        $event = $this->postJson("/api/v1/relationship-milestones/{$milestone['uuid']}/celebration", [
            'starts_at' => $startsAt->toDateTimeString(),
            'reminder_minutes' => 10080,
        ])->assertCreated()
            ->assertJsonPath('title', 'Oslava: Naše výročí')
            ->assertJsonPath('source_milestone_uuid', $milestone['uuid'])
            ->json();

        $this->assertDatabaseHas('event_attachments', ['event_id' => $event['id'], 'media_item_id' => $mediaId, 'kind' => 'memory']);
        $this->assertDatabaseHas('event_reminders', ['event_id' => $event['id'], 'user_id' => $this->owner->id]);
        $this->assertDatabaseHas('event_reminders', ['event_id' => $event['id'], 'user_id' => $this->partner->id]);
        $this->actingAs($this->partner)->getJson("/api/v1/calendar/events/{$event['uuid']}")->assertOk()
            ->assertJsonPath('origin.kind', 'milestone_celebration')
            ->assertJsonPath('origin.milestone.uuid', $milestone['uuid']);

        $this->actingAs($this->owner)->postJson("/api/v1/relationship-milestones/{$milestone['uuid']}/celebration", [
            'starts_at' => $startsAt->toDateTimeString(),
        ])->assertOk()->assertJsonPath('id', $event['id']);
    }

    public function test_ordered_saved_places_become_one_shared_event_and_editable_trip_plan(): void
    {
        $first = $this->actingAs($this->owner)->postJson('/api/v1/places', [
            'name' => 'Infinit Maximus', 'type' => 'business', 'city' => 'Brno',
            'latitude' => 49.2318, 'longitude' => 16.5162, 'estimated_visit_minutes' => 120,
            'is_rain_friendly' => true,
        ])->assertCreated()->json();
        $second = $this->postJson('/api/v1/places', [
            'name' => 'Vyhlídka Holedná', 'type' => 'custom', 'city' => 'Brno',
            'latitude' => 49.2177, 'longitude' => 16.5312, 'estimated_visit_minutes' => 75,
            'is_photogenic' => true,
        ])->assertCreated()->json();
        $startsAt = now()->addMonth()->setTime(10, 0, 0);
        $payload = [
            'place_ids' => [$second['id'], $first['id']],
            'starts_at' => $startsAt->toDateTimeString(),
            'title' => 'Pohodový den u přehrady',
            'transfer_minutes' => 20,
            'reminder_minutes' => 1440,
        ];

        $event = $this->postJson('/api/v1/places/plan-selection', $payload)
            ->assertCreated()
            ->assertJsonPath('title', 'Pohodový den u přehrady')
            ->assertJsonPath('places.0.id', $second['id'])
            ->assertJsonPath('places.1.id', $first['id'])
            ->assertJsonPath('duration_minutes', 215)
            ->json();

        $this->assertDatabaseHas('calendar_events', ['id' => $event['id'], 'trip_id' => $event['trip_id'], 'type' => 'outing']);
        $this->assertDatabaseCount('event_participants', 2);
        $this->assertDatabaseHas('event_reminders', ['event_id' => $event['id'], 'user_id' => $this->owner->id, 'channel' => 'database']);
        $this->assertDatabaseHas('event_reminders', ['event_id' => $event['id'], 'user_id' => $this->partner->id, 'channel' => 'database']);
        $this->assertSame(
            ['Vyhlídka Holedná', 'Infinit Maximus'],
            DB::table('trip_waypoints')->where('trip_id', $event['trip_id'])->orderBy('sort_order')->pluck('place_name')->all()
        );
        $dayId = DB::table('trip_days')->where('trip_id', $event['trip_id'])->value('id');
        $this->assertSame(
            ['Vyhlídka Holedná', 'Infinit Maximus'],
            DB::table('trip_activities')->where('trip_day_id', $dayId)->orderBy('sort_order')->pluck('title')->all()
        );

        $this->actingAs($this->partner)->getJson("/api/v1/calendar/events/{$event['uuid']}")
            ->assertOk()
            ->assertJsonPath('origin.kind', 'place_selection_outing')
            ->assertJsonPath('origin.places.0.id', $second['id'])
            ->assertJsonPath('origin.places.1.id', $first['id']);

        $this->actingAs($this->owner)->postJson('/api/v1/places/plan-selection', $payload)
            ->assertOk()->assertJsonPath('id', $event['id']);
        $this->assertSame(1, DB::table('calendar_events')->where('id', $event['id'])->count());
        $this->assertSame(2, DB::table('trip_activities')->where('trip_day_id', $dayId)->count());
    }

    public function test_completed_trip_builds_one_shared_story_album_memory_and_calendar_context(): void
    {
        $start = now()->subDays(4)->startOfDay();
        $end = now()->subDays(3)->startOfDay();
        $tripId = DB::table('trips')->insertGetId([
            'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'name' => 'Víkend na Pálavě', 'description' => 'Náš společný víkend mezi vinicemi.',
            'status' => 'completed', 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString(),
            'timezone' => 'Europe/Prague', 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $firstDayId = DB::table('trip_days')->insertGetId([
            'trip_id' => $tripId, 'date' => $start->toDateString(), 'title' => 'Příjezd a vinice', 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $secondDayId = DB::table('trip_days')->insertGetId([
            'trip_id' => $tripId, 'date' => $end->toDateString(), 'title' => 'Východ slunce', 'sort_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('trip_activities')->insert([
            ['trip_day_id' => $firstDayId, 'created_by' => $this->owner->id, 'title' => 'Procházka vinicemi', 'type' => 'activity', 'starts_at' => '15:00', 'status' => 'done', 'currency' => 'CZK', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['trip_day_id' => $secondDayId, 'created_by' => $this->owner->id, 'title' => 'Snídaně pod Děvínem', 'type' => 'activity', 'starts_at' => '08:00', 'status' => 'done', 'currency' => 'CZK', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('trip_waypoints')->insert([
            'trip_id' => $tripId, 'place_name' => 'Pavlov', 'latitude' => 48.8747, 'longitude' => 16.6718,
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $coverId = $this->media(['taken_at' => $start->copy()->setTime(17, 30), 'display_title' => 'Vinice při západu', 'is_favorite' => true, 'rating' => 5, 'width' => 6000, 'height' => 4000]);
        $secondPhotoId = $this->media(['taken_at' => $end->copy()->setTime(6, 10), 'display_title' => 'Ráno na Pálavě', 'width' => 4000, 'height' => 3000]);
        $videoId = $this->media(['taken_at' => $end->copy()->setTime(9, 0), 'display_title' => 'Video z výletu', 'media_type' => 'video', 'extension' => 'mp4', 'mime_type' => 'video/mp4']);
        foreach ([$coverId, $secondPhotoId, $videoId] as $mediaId) {
            DB::table('trip_media')->insert(['trip_id' => $tripId, 'media_item_id' => $mediaId, 'added_at' => now()]);
        }
        $eventUuid = (string) Str::uuid();
        $eventId = DB::table('calendar_events')->insertGetId([
            'uuid' => $eventUuid, 'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id,
            'trip_id' => $tripId, 'title' => 'Víkend na Pálavě', 'type' => 'trip', 'status' => 'completed',
            'starts_at' => $start, 'ends_at' => $end->copy()->endOfDay(), 'timezone' => 'Europe/Prague', 'is_private' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->owner)->putJson("/api/v1/trips/{$tripId}/reflection", [
            'rating' => 5, 'highlight' => 'Západ slunce mezi vinicemi.', 'gratitude' => 'Měli jsme na sebe čas.', 'next_time' => 'Zůstat o den déle.',
        ])->assertCreated();
        $ownerJournal = $this->postJson("/api/v1/trips/{$tripId}/journal", [
            'type' => 'note', 'content' => 'Ve vinici jsme se úplně zastavili v čase.', 'visibility' => 'shared', 'mood' => 'grateful', 'is_story_worthy' => true,
        ])->assertCreated()->json();
        $privateJournal = $this->actingAs($this->partner)->postJson("/api/v1/trips/{$tripId}/journal", [
            'type' => 'note', 'content' => 'Tento zápis je jen můj.', 'visibility' => 'private', 'mood' => 'calm', 'is_story_worthy' => true,
        ])->assertCreated()->json();
        $this->postJson("/api/v1/trips/{$tripId}/journal", [
            'type' => 'voice', 'content' => 'Nejlepší ráno letošního léta.', 'visibility' => 'shared', 'mood' => 'joyful', 'is_story_worthy' => true,
        ])->assertCreated();
        $this->assertDatabaseHas('travel_journal_entries', ['id' => $privateJournal['id'], 'visibility' => 'private', 'is_story_worthy' => false]);
        $this->actingAs($this->owner)->getJson("/api/v1/trips/{$tripId}/now")
            ->assertOk()->assertJsonCount(2, 'journal')->assertJsonMissing(['content' => 'Tento zápis je jen můj.']);
        $this->patchJson("/api/v1/trips/{$tripId}/journal/{$privateJournal['id']}", ['visibility' => 'shared'])->assertNotFound();
        $this->actingAs($this->partner)->getJson("/api/v1/trips/{$tripId}/now")
            ->assertOk()->assertJsonCount(3, 'journal')->assertJsonFragment(['content' => 'Tento zápis je jen můj.', 'visibility' => 'private']);

        $album = $this->actingAs($this->owner)->postJson("/api/v1/trips/{$tripId}/recap-album", [
            'title' => 'Naše Pálava', 'note' => 'Výběr toho nejlepšího z víkendu.',
            'media_item_ids' => [$secondPhotoId, $videoId, $coverId],
        ])->assertCreated()
            ->assertJsonPath('title', 'Naše Pálava')
            ->assertJsonPath('media_count', 3)
            ->assertJsonPath('cover_media_id', $coverId)
            ->assertJsonPath('story_created', true)
            ->assertJsonPath('journal_blocks_added', 2)
            ->json();

        $this->assertDatabaseHas('albums', ['uuid' => $album['uuid'], 'trip_id' => $tripId, 'story_mode' => true, 'visibility' => 'shared']);
        $albumId = DB::table('albums')->where('uuid', $album['uuid'])->value('id');
        $this->assertSame(3, DB::table('album_media')->where('album_id', $albumId)->count());
        $this->assertDatabaseHas('album_story_blocks', ['album_id' => $albumId, 'type' => 'map']);
        $this->assertDatabaseHas('album_story_blocks', ['album_id' => $albumId, 'type' => 'video']);
        $storyContent = DB::table('album_story_blocks')->where('album_id', $albumId)->pluck('content')->implode(' ');
        $this->assertStringContainsString('Ve vinici jsme se úplně zastavili v čase.', $storyContent);
        $this->assertStringContainsString('Nejlepší ráno letošního léta.', $storyContent);
        $this->assertStringNotContainsString('Tento zápis je jen můj.', $storyContent);
        $this->actingAs($this->owner)->patchJson("/api/v1/trips/{$tripId}/journal/{$ownerJournal['id']}", ['visibility' => 'private'])
            ->assertOk()->assertJsonPath('is_story_worthy', 0);
        $this->assertStringNotContainsString('Ve vinici jsme se úplně zastavili v čase.', DB::table('album_story_blocks')->where('album_id', $albumId)->pluck('content')->implode(' '));
        $this->patchJson("/api/v1/trips/{$tripId}/journal/{$ownerJournal['id']}", ['visibility' => 'shared', 'is_story_worthy' => true])
            ->assertOk()->assertJsonPath('visibility', 'shared');
        $this->assertStringContainsString('Ve vinici jsme se úplně zastavili v čase.', DB::table('album_story_blocks')->where('album_id', $albumId)->pluck('content')->implode(' '));
        $this->assertDatabaseHas('album_user_permissions', ['album_id' => $albumId, 'user_id' => $this->owner->id, 'role' => 'editor']);
        $this->assertDatabaseHas('album_user_permissions', ['album_id' => $albumId, 'user_id' => $this->partner->id, 'role' => 'editor']);
        $this->assertDatabaseHas('calendar_events', ['id' => $eventId, 'album_id' => $albumId]);
        $this->assertDatabaseHas('shared_memory_moments', ['trip_id' => $tripId, 'calendar_event_id' => $eventId, 'title' => 'Naše Pálava']);

        $headingId = DB::table('album_story_blocks')->where('album_id', $albumId)->where('type', 'heading')->orderBy('sort_order')->value('id');
        DB::table('album_story_blocks')->where('id', $headingId)->update(['content' => json_encode(['text' => 'Ručně upravený příběh', 'level' => 1]), 'updated_at' => now()]);
        $this->actingAs($this->partner)->postJson("/api/v1/trips/{$tripId}/journal", [
            'type' => 'note', 'content' => 'Příště si přivezeme piknikovou deku.', 'visibility' => 'shared', 'mood' => 'cozy', 'is_story_worthy' => true,
        ])->assertCreated();
        $this->assertStringContainsString('Příště si přivezeme piknikovou deku.', DB::table('album_story_blocks')->where('album_id', $albumId)->pluck('content')->implode(' '));
        $this->actingAs($this->owner)->postJson("/api/v1/trips/{$tripId}/recap-album", [
            'title' => 'Naše Pálava – výběr', 'media_item_ids' => [$coverId, $videoId],
        ])->assertOk()->assertJsonPath('uuid', $album['uuid'])->assertJsonPath('story_created', false)->assertJsonPath('journal_blocks_added', 0)->assertJsonPath('media_count', 2);
        $this->assertSame('Ručně upravený příběh', json_decode(DB::table('album_story_blocks')->where('id', $headingId)->value('content'), true)['text']);
        $this->assertSame(1, DB::table('albums')->where('trip_id', $tripId)->count());
        $this->postJson("/api/v1/trips/{$tripId}/recap-album", [
            'title' => 'Naše Pálava – výběr', 'media_item_ids' => [$coverId, $videoId],
        ])->assertOk()->assertJsonPath('journal_blocks_added', 0);

        $this->actingAs($this->partner)->getJson("/api/v1/trips/{$tripId}/recap-album")
            ->assertOk()->assertJsonPath('album.uuid', $album['uuid']);
        $this->getJson("/api/v1/calendar/events/{$eventUuid}")->assertOk()->assertJsonPath('album.uuid', $album['uuid']);
        $this->getJson('/api/v1/shared-memory-moments')->assertOk()->assertJsonPath('0.album.uuid', $album['uuid']);
        $this->get("/albums/{$album['uuid']}")->assertOk();
    }

    private function media(array $overrides = []): int
    {
        return DB::table('media_items')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id,
            'owner_user_id' => $this->owner->id, 'uploaded_by' => $this->owner->id,
            'original_filename' => Str::random(10) . '.jpg', 'safe_filename' => Str::random(10) . '.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 1000,
            'status' => 'ready', 'storage_status' => 'ready', 'taken_at' => now()->subYear(),
            'is_favorite' => false, 'is_archived' => false, 'is_hidden' => false,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }
}
