<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiSurfaceSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'API galerie', 'slug' => 'api-galerie', 'owner_id' => $user->id, 'is_default' => true]);
        $space->members()->attach($user->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->actingAs($user);
    }

    public function test_read_only_api_surface_returns_expected_success_responses(): void
    {
        $spaceId = (int) $this->app['auth']->user()->gallerySpaces()->value('gallery_spaces.id');
        DB::table('media_items')->insert([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $spaceId, 'owner_user_id' => $this->app['auth']->id(), 'uploaded_by' => $this->app['auth']->id(),
            'original_filename' => 'praha.jpg', 'safe_filename' => 'praha.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg',
            'media_type' => 'photo', 'size_bytes' => 1000, 'status' => 'ready', 'storage_status' => 'ready', 'taken_at' => now()->subYear(),
            'latitude' => 50.0755, 'longitude' => 14.4378, 'is_archived' => false, 'is_hidden' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([
            '/api/v1/timeline', '/api/v1/timeline/buckets', '/api/v1/timeline/map', '/api/v1/timeline/memories', '/api/v1/timeline/calendar',
            '/api/v1/search', '/api/v1/search/suggestions?q=a', '/api/v1/albums', '/api/v1/albums/tree',
            '/api/v1/people', '/api/v1/tags', '/api/v1/places', '/api/v1/notifications', '/api/v1/memories',
            '/api/v1/memories/preferences', '/api/v1/recovery/duplicates', '/api/v1/recovery/cleanup',
            '/api/v1/saved-searches', '/api/v1/journey', '/api/v1/itinerary', '/api/v1/books', '/api/v1/trips',
            '/api/v1/shares', '/api/v1/guest-uploads', '/api/v1/calendar/events', '/api/v1/calendar/weekly-overview',
            '/api/v1/calendar/inbox', '/api/v1/calendar/time-capsules',
        ] as $path) {
            $this->getJson($path)->assertOk();
        }
    }

    public function test_ticket_api_degrades_to_provider_links_without_external_calls(): void
    {
        Cache::put('rj_cities_v2', [], 60);
        Cache::put('fb_city:' . md5('praha'), [], 60);
        Cache::put('fb_city:' . md5('brno'), [], 60);
        $response = $this->getJson('/api/v1/tickets/search?from=Praha&to=Brno&date=2026-08-01&adults=1')->assertOk();
        $this->assertGreaterThanOrEqual(5, count($response->json()));
    }

    public function test_place_preferences_are_persisted_for_practical_trip_filtering(): void
    {
        $place = $this->postJson('/api/v1/places', [
            'name' => 'Kavárna na deštivé odpoledne',
            'type' => 'restaurant',
            'is_rain_friendly' => true,
            'is_photogenic' => true,
            'opens_early' => true,
            'price_level' => 2,
            'estimated_visit_minutes' => 90,
            'personal_rating' => 5,
            'next_time_note' => 'Vzít si knihu.',
        ])->assertCreated()->json();

        $this->getJson("/api/v1/places/{$place['id']}")
            ->assertOk()
            ->assertJsonPath('is_rain_friendly', true)
            ->assertJsonPath('personal_rating', 5)
            ->assertJsonPath('next_time_note', 'Vzít si knihu.');
    }

    public function test_saved_place_can_be_added_directly_to_a_selected_trip_day(): void
    {
        $spaceId = (int) $this->app['auth']->user()->gallerySpaces()->value('gallery_spaces.id');
        $place = $this->postJson('/api/v1/places', ['name' => 'Rozhledna', 'type' => 'custom', 'latitude' => 49.2, 'longitude' => 16.6, 'next_time_note' => 'Vzít dalekohled.'])->assertCreated()->json();
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $spaceId, 'created_by' => $this->app['auth']->id(), 'name' => 'Moravský výlet', 'start_date' => now()->addWeek()->toDateString(), 'end_date' => now()->addWeek()->addDay()->toDateString(), 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $dayId = $this->getJson("/api/v1/trips/{$tripId}/plan")->assertOk()->json('days.0.id');

        $this->postJson("/api/v1/places/{$place['id']}/trip-activities", ['trip_id' => $tripId, 'trip_day_id' => $dayId, 'starts_at' => '10:30'])
            ->assertCreated()
            ->assertJsonPath('title', 'Rozhledna')
            ->assertJsonPath('place_name', 'Rozhledna');
        $activity = DB::table('trip_activities')->where('trip_day_id', $dayId)->where('title', 'Rozhledna')->first();
        $this->assertSame('Rozhledna', $activity->place_name);
        $this->assertSame('10:30', substr((string) $activity->starts_at, 0, 5));
    }

    public function test_saved_place_carries_its_planning_context_into_a_shared_wishlist_without_duplicates(): void
    {
        $spaceId = (int) $this->app['auth']->user()->gallerySpaces()->value('gallery_spaces.id');
        $place = $this->postJson('/api/v1/places', ['name' => 'Ranní vyhlídka', 'type' => 'custom', 'latitude' => 49.2, 'longitude' => 16.6, 'estimated_visit_minutes' => 75, 'personal_rating' => 5, 'next_time_note' => 'Přijet před východem slunce.'])->assertCreated()->json();
        $wishlist = $this->postJson('/api/v1/calendar/wishlists', ['gallery_space_id' => $spaceId, 'title' => 'Kam příště'])->assertCreated()->json();
        $item = $this->postJson("/api/v1/places/{$place['id']}/wishlist-items", ['wishlist_uuid' => $wishlist['uuid']])->assertCreated()->assertJsonPath('place_id', $place['id'])->assertJsonPath('estimated_minutes', 75)->json();
        $this->postJson("/api/v1/places/{$place['id']}/wishlist-items", ['wishlist_uuid' => $wishlist['uuid']])->assertOk()->assertJsonPath('id', $item['id']);
        $this->getJson("/api/v1/calendar/wishlists/{$wishlist['uuid']}/suggestions")->assertOk()->assertJsonPath('items.0.place_id', $place['id']);
    }

    public function test_trip_photos_can_be_saved_as_one_shared_memory(): void
    {
        $spaceId = (int) $this->app['auth']->user()->gallerySpaces()->value('gallery_spaces.id');
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $spaceId, 'created_by' => $this->app['auth']->id(), 'name' => 'Výlet na Pálavu', 'start_date' => now()->subDays(2)->toDateString(), 'end_date' => now()->subDay()->toDateString(), 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $mediaId = DB::table('media_items')->insertGetId(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $spaceId, 'owner_user_id' => $this->app['auth']->id(), 'uploaded_by' => $this->app['auth']->id(), 'original_filename' => 'palava.jpg', 'safe_filename' => 'palava.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 1, 'status' => 'ready', 'storage_status' => 'ready', 'taken_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('trip_media')->insert(['trip_id' => $tripId, 'media_item_id' => $mediaId, 'added_at' => now()]);

        $this->postJson("/api/v1/trips/{$tripId}/shared-memory", ['title' => 'Naše Pálava', 'note' => 'Západ slunce.', 'media_item_ids' => [$mediaId]])
            ->assertCreated()->assertJsonPath('trip_id', $tripId)->assertJsonPath('title', 'Naše Pálava');
        $this->postJson("/api/v1/trips/{$tripId}/shared-memory", ['title' => 'Naše Pálava podruhé', 'media_item_ids' => [$mediaId]])
            ->assertOk()->assertJsonPath('title', 'Naše Pálava podruhé');
        $this->assertDatabaseCount('shared_memory_moments', 1);
    }

    public function test_gallery_photos_from_trip_dates_can_be_added_to_the_trip_before_creating_a_memory(): void
    {
        $spaceId = (int) $this->app['auth']->user()->gallerySpaces()->value('gallery_spaces.id');
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $spaceId, 'created_by' => $this->app['auth']->id(), 'name' => 'Galerie a cesta', 'start_date' => today()->toDateString(), 'end_date' => today()->toDateString(), 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $mediaId = DB::table('media_items')->insertGetId(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $spaceId, 'owner_user_id' => $this->app['auth']->id(), 'uploaded_by' => $this->app['auth']->id(), 'original_filename' => 'dnes.jpg', 'safe_filename' => 'dnes.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 1, 'status' => 'ready', 'storage_status' => 'ready', 'taken_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->getJson("/api/v1/trips/{$tripId}/suggest-media")->assertOk()->assertJsonPath('all_ids.0', $mediaId);
        $this->postJson("/api/v1/trips/{$tripId}/media", ['media_ids' => [$mediaId]])->assertOk()->assertJsonPath('added', 1);
        $this->getJson("/api/v1/trips/{$tripId}/media")->assertOk()->assertJsonPath('0.id', $mediaId);
    }

    public function test_saved_place_visit_creates_a_calendar_event_and_can_be_marked_visited(): void
    {
        $place = $this->postJson('/api/v1/places', ['name' => 'Hrad Pernštejn', 'type' => 'custom'])->assertCreated()->json();
        $plan = $this->postJson("/api/v1/places/{$place['id']}/plans", ['planned_for' => now()->addWeek()->toDateString(), 'reservation_reference' => 'PER-42', 'reservation_url' => 'https://example.test/pernstejn'])->assertCreated()->json();
        $this->assertDatabaseHas('calendar_events', ['id' => $plan['calendar_event_id'], 'title' => 'Hrad Pernštejn', 'type' => 'outing']);
        $this->patchJson("/api/v1/places/{$place['id']}/plans/{$plan['uuid']}", ['state' => 'visited'])->assertOk()->assertJsonPath('state', 'visited');
        $this->assertDatabaseHas('calendar_events', ['id' => $plan['calendar_event_id'], 'status' => 'completed']);
    }

    public function test_visited_place_creates_one_shared_memory_from_its_linked_gallery_photos(): void
    {
        $spaceId = (int) $this->app['auth']->user()->gallerySpaces()->value('gallery_spaces.id');
        $place = $this->postJson('/api/v1/places', ['name' => 'Lednický park', 'type' => 'custom'])->assertCreated()->json();
        $plan = $this->postJson("/api/v1/places/{$place['id']}/plans", ['planned_for' => now()->subDay()->toDateString()])->assertCreated()->json();
        $this->patchJson("/api/v1/places/{$place['id']}/plans/{$plan['uuid']}", ['state' => 'visited'])->assertOk();
        $mediaId = DB::table('media_items')->insertGetId(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $spaceId, 'owner_user_id' => $this->app['auth']->id(), 'uploaded_by' => $this->app['auth']->id(), 'original_filename' => 'lednice.jpg', 'safe_filename' => 'lednice.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 1, 'status' => 'ready', 'storage_status' => 'ready', 'taken_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('media_place')->insert(['media_item_id' => $mediaId, 'place_id' => $place['id'], 'is_primary' => true, 'created_at' => now()]);

        $this->postJson("/api/v1/places/{$place['id']}/plans/{$plan['uuid']}/shared-memory", [])->assertCreated()->assertJsonPath('place_plan_id', $plan['id']);
        $this->postJson("/api/v1/places/{$place['id']}/plans/{$plan['uuid']}/shared-memory", [])->assertOk();
        $this->assertDatabaseCount('shared_memory_moments', 1);
    }

    public function test_completed_trip_has_one_shared_reflection_linking_its_plan_gallery_and_cost_context(): void
    {
        $spaceId = (int) $this->app['auth']->user()->gallerySpaces()->value('gallery_spaces.id');
        $tripId = DB::table('trips')->insertGetId([
            'gallery_space_id' => $spaceId,
            'created_by' => $this->app['auth']->id(),
            'name' => 'Víkend v Jeseníkách',
            'start_date' => now()->subDays(4)->toDateString(),
            'end_date' => now()->subDays(2)->toDateString(),
            'status' => 'completed',
            'currency' => 'CZK',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $dayId = $this->getJson("/api/v1/trips/{$tripId}/plan")->assertOk()->json('days.0.id');
        DB::table('trip_activities')->insert(['trip_day_id' => $dayId, 'type' => 'activity', 'title' => 'Výstup na Praděd', 'status' => 'done', 'sort_order' => 1, 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('trip_expenses')->insert(['trip_id' => $tripId, 'created_by' => $this->app['auth']->id(), 'title' => 'Horská chata', 'amount' => 850, 'currency' => 'CZK', 'state' => 'actual', 'created_at' => now(), 'updated_at' => now()]);

        $this->getJson("/api/v1/trips/{$tripId}/reflection")
            ->assertOk()
            ->assertJsonPath('reflection', null)
            ->assertJsonPath('completion.activities_total', 1)
            ->assertJsonPath('completion.activities_done', 1)
            ->assertJsonPath('completion.actual_expenses', 850);
        $this->putJson("/api/v1/trips/{$tripId}/reflection", [
            'rating' => 5,
            'highlight' => 'Východ slunce na vrcholu.',
            'gratitude' => 'Za společný klid mimo město.',
            'next_time' => 'Vzít teplejší rukavice.',
        ])->assertCreated()->assertJsonPath('rating', 5)->assertJsonPath('next_time', 'Vzít teplejší rukavice.');
        $this->putJson("/api/v1/trips/{$tripId}/reflection", ['rating' => 4, 'highlight' => 'Výhled stál za to.'])
            ->assertOk()->assertJsonPath('rating', 4)->assertJsonPath('highlight', 'Výhled stál za to.')->assertJsonPath('next_time', 'Vzít teplejší rukavice.');
        $this->assertDatabaseCount('trip_reflections', 1);

        $revisitAt = now()->addMonths(2)->setTime(10, 0);
        $revisit = $this->postJson("/api/v1/trips/{$tripId}/revisit", ['starts_at' => $revisitAt->toDateTimeString(), 'reminder_minutes' => 10080])
            ->assertCreated()->assertJsonPath('source_trip_id', $tripId)->assertJsonPath('type', 'outing')->json();
        $this->assertDatabaseHas('calendar_events', ['id' => $revisit['id'], 'source_trip_id' => $tripId, 'title' => 'Návrat: Víkend v Jeseníkách']);
        $this->assertDatabaseHas('event_reminders', ['event_id' => $revisit['id'], 'user_id' => $this->app['auth']->id(), 'channel' => 'database', 'status' => 'pending']);
        $this->postJson("/api/v1/trips/{$tripId}/revisit", ['starts_at' => $revisitAt->toDateTimeString()])
            ->assertOk()->assertJsonPath('id', $revisit['id']);
    }

    public function test_selected_transport_and_accommodation_are_projected_into_one_trip_workspace(): void
    {
        $spaceId = (int) $this->app['auth']->user()->gallerySpaces()->value('gallery_spaces.id');
        $tripId = DB::table('trips')->insertGetId(['gallery_space_id' => $spaceId, 'created_by' => $this->app['auth']->id(), 'name' => 'Vídeň pro dva', 'start_date' => now()->addMonth()->toDateString(), 'end_date' => now()->addMonth()->addDay()->toDateString(), 'currency' => 'CZK', 'created_at' => now(), 'updated_at' => now()]);
        $dayId = $this->getJson("/api/v1/trips/{$tripId}/plan")->assertOk()->json('days.0.id');
        $this->postJson("/api/v1/trips/{$tripId}/booking-search", ['destination' => 'Vídeň', 'checkin' => now()->addMonth()->toDateString(), 'checkout' => now()->addMonth()->addDay()->toDateString(), 'adults' => 2])->assertOk()->assertJsonPath('provider', 'Booking.com')->assertJsonPath('kind', 'search_link');
        $transport = $this->postJson("/api/v1/trips/{$tripId}/travel-choices/transport", ['title' => 'RegioJet Praha – Vídeň', 'provider' => 'RegioJet', 'source_url' => 'https://regiojet.cz/', 'amount' => 1200, 'estimated_minutes' => 240, 'transport_modes' => ['train']])->assertCreated()->assertJsonPath('kind', 'transport')->json();
        $stay = $this->postJson("/api/v1/trips/{$tripId}/travel-choices/accommodation", ['trip_day_id' => $dayId, 'title' => 'Hotel u centra', 'provider' => 'Booking.com', 'source_url' => 'https://www.booking.com/searchresults.html', 'amount' => 2400, 'reference' => 'BOOK-42', 'checkin' => now()->addMonth()->toDateString(), 'checkout' => now()->addMonth()->addDay()->toDateString()])->assertCreated()->assertJsonPath('kind', 'accommodation')->json();
        $this->assertDatabaseHas('trip_route_variants', ['id' => $transport['trip_route_variant_id'], 'trip_id' => $tripId, 'is_selected' => true]);
        $this->assertDatabaseHas('trip_activities', ['id' => $stay['trip_activity_id'], 'type' => 'stay', 'title' => 'Hotel u centra']);
        $this->assertDatabaseHas('trip_expenses', ['id' => $stay['trip_expense_id'], 'category' => 'accommodation', 'amount' => 2400]);
        $this->assertDatabaseHas('trip_document_checks', ['trip_id' => $tripId, 'reference' => 'BOOK-42', 'type' => 'booking']);
    }
}
