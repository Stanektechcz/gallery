<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\IntegrationSetting;
use App\Models\User;
use App\Services\Entertainment\CinemaCityProgramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EntertainmentPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_couple_can_discover_vote_propose_and_schedule_a_movie(): void
    {
        [$owner, $partner, $space] = $this->couple();
        $this->tmdb();
        Http::fake([
            'api.themoviedb.org/3/search/*' => Http::response(['results' => [['id' => 603, 'media_type' => 'movie', 'title' => 'Matrix', 'original_title' => 'The Matrix', 'overview' => 'Sci-fi klasika.', 'release_date' => '1999-03-31', 'poster_path' => '/matrix.jpg', 'vote_average' => 8.2]]]),
            'api.themoviedb.org/3/movie/603*' => Http::response(['id' => 603, 'title' => 'Matrix', 'original_title' => 'The Matrix', 'overview' => 'Sci-fi klasika.', 'release_date' => '1999-03-31', 'runtime' => 136, 'poster_path' => '/matrix.jpg', 'genres' => [['name' => 'Sci-fi']], 'videos' => ['results' => []]]),
        ]);
        $result = $this->actingAs($owner)->getJson('/api/v1/entertainment/search?query=matrix')->assertOk()->assertJsonPath('results.0.external_id', '603')->json('results.0');
        $title = $this->postJson('/api/v1/entertainment', ['gallery_space_id' => $space->id] + $result)->assertCreated()->assertJsonPath('runtime_minutes', 136)->json();
        $this->putJson('/api/v1/entertainment/'.$title['uuid'].'/vote', ['interest' => 5, 'cinema_preferred' => true])->assertOk();
        $this->actingAs($partner)->putJson('/api/v1/entertainment/'.$title['uuid'].'/vote', ['interest' => 4])->assertOk();
        $suggestion = $this->getJson('/api/v1/entertainment/'.$title['uuid'].'/date-suggestions')->assertOk()->json('home.0');
        $proposal = $this->postJson('/api/v1/entertainment/'.$title['uuid'].'/date-proposals', ['starts_at' => $suggestion['starts_at'], 'venue' => 'home'])->assertCreated()->json();
        $this->putJson('/api/v1/entertainment/date-proposals/'.$proposal['uuid'].'/vote', ['response' => 'yes'])->assertOk();
        $event = $this->postJson('/api/v1/entertainment/date-proposals/'.$proposal['uuid'].'/select')->assertCreated()->assertJsonPath('title', 'Filmový večer · Matrix')->json();
        $this->assertDatabaseHas('calendar_events', ['uuid' => $event['uuid'], 'type' => 'movie_night']);
        $this->assertDatabaseCount('event_participants', 2);
        $this->assertGreaterThanOrEqual(2, DB::table('event_reminders')->count());
        $this->postJson('/api/v1/entertainment/'.$title['uuid'].'/sessions', [
            'rating' => 4.5, 'story_rating' => 5, 'acting_rating' => 4.5, 'visual_rating' => 4,
            'sound_rating' => 4, 'emotion_rating' => 5, 'pace_rating' => 4, 'recommendation' => 'yes',
            'review' => 'Výborný společný film.', 'favorite_moment' => 'Finále', 'watch_again' => true, 'venue' => 'home',
        ])->assertCreated();
        $this->assertDatabaseHas('entertainment_titles', ['uuid' => $title['uuid'], 'status' => 'watched']);
        $this->assertDatabaseHas('entertainment_reviews', ['rating' => 4.5, 'story_rating' => 5, 'recommendation' => 'yes', 'user_id' => $partner->id]);
        $this->getJson('/api/v1/entertainment?gallery_space_id='.$space->id)->assertOk()
            ->assertJsonPath('titles.0.joint_score', 4.5)
            ->assertJsonPath('titles.0.proposals.0.event_uuid', $event['uuid'])
            ->assertJsonPath('titles.0.reviews.0.story_rating', 5)
            ->assertJsonPath('titles.0.review_summary.rating', 4.5);
    }

    public function test_official_cinema_program_is_cached_and_can_be_proposed(): void
    {
        [$owner, $partner, $space] = $this->couple();
        $start = now('Europe/Prague')->addDays(2)->setTime(19, 30);
        Http::fake(['www.cinemacity.cz/cz/data-api-service/*' => Http::response(['body' => [
            'films' => [['id' => 'film-1', 'name' => 'Testovací film', 'length' => 118, 'releaseYear' => 2026, 'posterLink' => 'https://example.com/poster.jpg']],
            'events' => [['id' => 'event-1', 'filmId' => 'film-1', 'eventDateTime' => $start->format('Y-m-d\TH:i:s'), 'auditorium' => 'Sál 2', 'attributeIds' => ['2d', 'adventure', 'animation', 'dolby-atmos', 'first-subbed-lang-cs', 'laser-barco', 'original-lang-en', 'subbed', 'suitable-for-all'], 'languages' => ['original' => 'en', 'subtitles' => 'cs'], 'bookingLink' => 'https://tickets.cinemacity.cz/api/order/event-1?lang=cs', 'bookingRouterLaunchLink' => 'https://www.cinemacity.cz/cz/booking-router/launch/event-1?lang=cs', 'compositeBookingLink' => ['blockOnlineSales' => false], 'soldOut' => false, 'availabilityRatio' => .65]],
        ]])]);
        $this->actingAs($owner)->get('/api/v1/entertainment/cinema/sync')->assertRedirect('/watchlist');
        $this->actingAs($owner)->postJson('/api/v1/entertainment/cinema/sync', ['days' => 2])->assertOk()->assertJsonPath('count', 2);
        $showing = DB::table('cinema_showings')->where('external_event_id', 'event-1')->first();
        $this->assertNotNull($showing);
        $bookingUrl = CinemaCityProgramService::programUrl($start, 'film-1');
        $this->assertSame('2D · Dolby Atmos · Laser', $showing->format);
        $this->assertStringContainsString('suitable-for-all', $showing->attributes);
        $this->assertSame($start->copy()->utc()->format('Y-m-d H:i:s'), $showing->starts_at);
        $this->assertSame('19:30', Carbon::parse($showing->starts_at, 'UTC')->timezone('Europe/Prague')->format('H:i'));
        $this->assertSame($bookingUrl, $showing->booking_url);
        $this->assertStringContainsString('for-movie=film-1', $showing->booking_url);
        $this->assertStringNotContainsString('booking-router', $showing->booking_url);
        $this->assertStringNotContainsString('tickets.', $showing->booking_url);
        $this->postJson('/api/v1/entertainment/cinema/showings/'.$showing->uuid, ['gallery_space_id' => $space->id, 'propose' => true])->assertCreated()->assertJsonPath('title', 'Testovací film');
        $this->assertDatabaseHas('viewing_date_proposals', ['cinema_showing_id' => $showing->id, 'venue' => 'cinema']);
        $this->getJson('/api/v1/entertainment?gallery_space_id='.$space->id)->assertOk()
            ->assertJsonPath('cinema.showings.0.title', 'Testovací film')
            ->assertJsonPath('cinema.showings.0.external_film_id', 'film-1')
            ->assertJsonPath('cinema.showings.0.booking_url', $bookingUrl)
            ->assertJsonPath('titles.0.proposals.0.booking_url', $bookingUrl);
    }

    public function test_cinema_sync_keeps_a_successful_day_when_another_day_is_temporarily_unavailable(): void
    {
        [$owner] = $this->couple();
        $start = now('Europe/Prague')->addDay()->setTime(20, 0);
        Http::fakeSequence()
            ->push(['body' => [
                'films' => [['id' => 'film-partial', 'name' => 'Částečně načtený film', 'length' => 101, 'releaseYear' => 2026]],
                'events' => [['id' => 'event-partial', 'filmId' => 'film-partial', 'eventDateTime' => $start->toIso8601String(), 'attributeIds' => ['2d'], 'languages' => []]],
            ]], 200)
            ->pushStatus(503)
            ->pushStatus(503)
            ->pushStatus(503);

        $this->actingAs($owner)->postJson('/api/v1/entertainment/cinema/sync', ['days' => 2])
            ->assertOk()
            ->assertJsonPath('status', 'partial')
            ->assertJsonPath('count', 1)
            ->assertJsonCount(1, 'warnings');
        $this->assertDatabaseHas('cinema_showings', ['external_event_id' => 'event-partial', 'title' => 'Částečně načtený film']);
        $this->assertDatabaseHas('cinema_sync_runs', ['status' => 'partial', 'showings_count' => 1]);
    }

    public function test_title_can_be_edited_and_removed_from_the_watchlist(): void
    {
        [$owner, , $space] = $this->couple();
        $this->actingAs($owner);
        $title = $this->postJson('/api/v1/entertainment', [
            'gallery_space_id' => $space->id, 'media_type' => 'movie', 'title' => 'Nepresny nazev',
        ])->assertCreated()->json();

        // Descriptive fields must be correctable, not just status and priority.
        $this->patchJson('/api/v1/entertainment/'.$title['uuid'], [
            'title' => 'Přesný název', 'release_year' => 2011, 'runtime_minutes' => 112,
            'genres' => ['drama', 'komedie'], 'notes' => 'Doporučila máma.', 'watch_provider' => 'Netflix',
        ])->assertOk()
            ->assertJsonPath('title', 'Přesný název')
            ->assertJsonPath('release_year', 2011)
            ->assertJsonPath('genres.1', 'komedie');

        // A vote must not block deletion — the schema cascades it away.
        $this->putJson('/api/v1/entertainment/'.$title['uuid'].'/vote', ['interest' => 4])->assertOk();
        $this->deleteJson('/api/v1/entertainment/'.$title['uuid'])->assertOk()->assertJsonPath('deleted', true);
        $this->assertDatabaseMissing('entertainment_titles', ['uuid' => $title['uuid']]);
        $this->assertDatabaseCount('entertainment_votes', 0);
    }

    public function test_a_title_from_another_space_cannot_be_removed(): void
    {
        [$owner, , $space] = $this->couple();
        $intruder = User::factory()->create(['role' => 'partner', 'is_active' => true]);
        $title = $this->actingAs($owner)->postJson('/api/v1/entertainment', [
            'gallery_space_id' => $space->id, 'media_type' => 'movie', 'title' => 'Náš film',
        ])->assertCreated()->json();

        $this->actingAs($intruder)->deleteJson('/api/v1/entertainment/'.$title['uuid'])->assertNotFound();
        $this->assertDatabaseHas('entertainment_titles', ['uuid' => $title['uuid']]);
    }

    public function test_manually_added_title_can_be_filled_in_from_the_movie_database(): void
    {
        [$owner, , $space] = $this->couple();
        $this->tmdb();
        Http::fake([
            'api.themoviedb.org/3/movie/603*' => Http::response(['id' => 603, 'title' => 'Matrix', 'overview' => 'Sci-fi klasika.', 'release_date' => '1999-03-31', 'runtime' => 136, 'poster_path' => '/matrix.jpg', 'genres' => [['name' => 'Sci-fi']], 'videos' => ['results' => []]]),
        ]);
        $title = $this->actingAs($owner)->postJson('/api/v1/entertainment', [
            'gallery_space_id' => $space->id, 'media_type' => 'movie', 'title' => 'Matrix', 'notes' => 'Klasika na pátek.',
        ])->assertCreated()->json();
        $this->assertNull($title['runtime_minutes']);

        $this->postJson('/api/v1/entertainment/'.$title['uuid'].'/refresh-metadata', ['external_id' => '603'])
            ->assertOk()
            ->assertJsonPath('runtime_minutes', 136)
            ->assertJsonPath('release_year', 1999)
            // Our own planning fields survive the refresh.
            ->assertJsonPath('notes', 'Klasika na pátek.');
    }

    public function test_chat_fills_a_film_from_the_database_and_honours_an_explicit_choice(): void
    {
        [$owner, , $space] = $this->couple();
        $this->tmdb();
        Http::fake([
            'api.themoviedb.org/3/search/*' => Http::response(['results' => [
                ['id' => 603, 'media_type' => 'movie', 'title' => 'Matrix', 'release_date' => '1999-03-31', 'poster_path' => '/m.jpg'],
                ['id' => 604, 'media_type' => 'movie', 'title' => 'Matrix Reloaded', 'release_date' => '2003-05-15', 'poster_path' => '/m2.jpg'],
            ]]),
            'api.themoviedb.org/3/movie/604*' => Http::response(['id' => 604, 'title' => 'Matrix Reloaded', 'release_date' => '2003-05-15', 'runtime' => 138, 'poster_path' => '/m2.jpg', 'genres' => [['name' => 'Sci-fi']], 'videos' => ['results' => []]]),
            'api.themoviedb.org/3/movie/603*' => Http::response(['id' => 603, 'title' => 'Matrix', 'release_date' => '1999-03-31', 'runtime' => 136, 'poster_path' => '/m.jpg', 'genres' => [['name' => 'Sci-fi']], 'videos' => ['results' => []]]),
        ]);
        $this->actingAs($owner);

        // The preview offers candidates to pick from.
        $preview = $this->postJson('/api/v1/assistant/preview', ['message' => '/film Matrix'])->assertOk()->json();
        $this->assertSame('Matrix', $preview['titles'][0]['title']);
        $this->assertSame('603', $preview['titles'][0]['candidates'][0]['external_id']);
        $this->assertCount(2, $preview['titles'][0]['candidates']);

        // An explicit choice wins over the automatic top hit.
        $this->postJson('/api/v1/assistant/apply', [
            'message' => '/film Matrix', 'selected_actions' => ['titles'],
            'title_choices' => [['title' => 'Matrix', 'external_id' => '604', 'media_type' => 'movie']],
        ])->assertCreated();

        $this->assertDatabaseHas('entertainment_titles', [
            'gallery_space_id' => $space->id, 'external_source' => 'tmdb', 'external_id' => '604', 'runtime_minutes' => 138,
        ]);
    }

    public function test_chat_still_saves_a_film_when_the_movie_database_is_not_configured(): void
    {
        [$owner, , $space] = $this->couple();
        $this->actingAs($owner);

        $preview = $this->postJson('/api/v1/assistant/preview', ['message' => '/film Neznámý snímek'])->assertOk()->json();
        $this->assertSame([], $preview['titles'][0]['candidates']);

        $this->postJson('/api/v1/assistant/apply', ['message' => '/film Neznámý snímek', 'selected_actions' => ['titles']])->assertCreated();
        $this->assertDatabaseHas('entertainment_titles', [
            'gallery_space_id' => $space->id, 'title' => 'Neznámý snímek', 'external_source' => 'manual',
        ]);
    }

    private function tmdb(): void
    {
        $setting = new IntegrationSetting(['provider' => 'tmdb', 'is_enabled' => true]);
        $setting->replaceConfig(['api_key' => 'test-key']);
        $setting->save();
    }

    private function couple(): array
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $partner = User::factory()->create(['role' => 'partner', 'is_active' => true]);
        $space = GallerySpace::create(['name' => 'Filmový pár', 'slug' => 'movie-couple', 'owner_id' => $owner->id, 'is_default' => true]);
        $space->members()->attach($owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $space->members()->attach($partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);

        return [$owner, $partner, $space];
    }
}
