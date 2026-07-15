<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EntertainmentPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_couple_can_discover_vote_propose_and_schedule_a_movie(): void
    {
        [$owner, $partner, $space] = $this->couple(); $this->tmdb();
        Http::fake([
            'api.themoviedb.org/3/search/*' => Http::response(['results' => [['id' => 603, 'media_type' => 'movie', 'title' => 'Matrix', 'original_title' => 'The Matrix', 'overview' => 'Sci-fi klasika.', 'release_date' => '1999-03-31', 'poster_path' => '/matrix.jpg', 'vote_average' => 8.2]]]),
            'api.themoviedb.org/3/movie/603*' => Http::response(['id' => 603, 'title' => 'Matrix', 'original_title' => 'The Matrix', 'overview' => 'Sci-fi klasika.', 'release_date' => '1999-03-31', 'runtime' => 136, 'poster_path' => '/matrix.jpg', 'genres' => [['name' => 'Sci-fi']], 'videos' => ['results' => []]]),
        ]);
        $result = $this->actingAs($owner)->getJson('/api/v1/entertainment/search?query=matrix')->assertOk()->assertJsonPath('results.0.external_id', '603')->json('results.0');
        $title = $this->postJson('/api/v1/entertainment', ['gallery_space_id' => $space->id] + $result)->assertCreated()->assertJsonPath('runtime_minutes', 136)->json();
        $this->putJson('/api/v1/entertainment/' . $title['uuid'] . '/vote', ['interest' => 5, 'cinema_preferred' => true])->assertOk();
        $this->actingAs($partner)->putJson('/api/v1/entertainment/' . $title['uuid'] . '/vote', ['interest' => 4])->assertOk();
        $suggestion = $this->getJson('/api/v1/entertainment/' . $title['uuid'] . '/date-suggestions')->assertOk()->json('home.0');
        $proposal = $this->postJson('/api/v1/entertainment/' . $title['uuid'] . '/date-proposals', ['starts_at' => $suggestion['starts_at'], 'venue' => 'home'])->assertCreated()->json();
        $this->putJson('/api/v1/entertainment/date-proposals/' . $proposal['uuid'] . '/vote', ['response' => 'yes'])->assertOk();
        $event = $this->postJson('/api/v1/entertainment/date-proposals/' . $proposal['uuid'] . '/select')->assertCreated()->assertJsonPath('title', 'Filmový večer · Matrix')->json();
        $this->assertDatabaseHas('calendar_events', ['uuid' => $event['uuid'], 'type' => 'movie_night']);
        $this->assertDatabaseCount('event_participants', 2); $this->assertGreaterThanOrEqual(2, DB::table('event_reminders')->count());
        $this->postJson('/api/v1/entertainment/' . $title['uuid'] . '/sessions', ['rating' => 4.5, 'venue' => 'home'])->assertCreated();
        $this->assertDatabaseHas('entertainment_titles', ['uuid' => $title['uuid'], 'status' => 'watched']);
        $this->assertDatabaseHas('entertainment_reviews', ['rating' => 4.5, 'user_id' => $partner->id]);
        $this->getJson('/api/v1/entertainment?gallery_space_id=' . $space->id)->assertOk()->assertJsonPath('titles.0.joint_score', 4.5)->assertJsonPath('titles.0.proposals.0.event_uuid', $event['uuid']);
    }

    public function test_official_cinema_program_is_cached_and_can_be_proposed(): void
    {
        [$owner, $partner, $space] = $this->couple(); $start = now('Europe/Prague')->addDays(2)->setTime(19, 30);
        Http::fake(['www.cinemacity.cz/cz/data-api-service/*' => Http::response(['body' => [
            'films' => [['id' => 'film-1', 'name' => 'Testovací film', 'length' => 118, 'releaseYear' => 2026, 'posterLink' => 'https://example.com/poster.jpg']],
            'events' => [['id' => 'event-1', 'filmId' => 'film-1', 'eventDateTime' => $start->toIso8601String(), 'auditorium' => 'Sál 2', 'attributeIds' => ['2D'], 'languages' => ['original' => 'en', 'subtitles' => 'cs'], 'bookingLink' => 'https://www.cinemacity.cz/booking/event-1', 'soldOut' => false, 'availabilityRatio' => .65]],
        ]])]);
        $this->actingAs($owner)->postJson('/api/v1/entertainment/cinema/sync', ['days' => 2])->assertOk()->assertJsonPath('count', 2);
        $showing = DB::table('cinema_showings')->where('external_event_id', 'event-1')->first(); $this->assertNotNull($showing);
        $this->postJson('/api/v1/entertainment/cinema/showings/' . $showing->uuid, ['gallery_space_id' => $space->id, 'propose' => true])->assertCreated()->assertJsonPath('title', 'Testovací film');
        $this->assertDatabaseHas('viewing_date_proposals', ['cinema_showing_id' => $showing->id, 'venue' => 'cinema']);
        $this->getJson('/api/v1/entertainment?gallery_space_id=' . $space->id)->assertOk()->assertJsonPath('cinema.showings.0.title', 'Testovací film');
    }

    private function tmdb(): void
    {
        $setting = new IntegrationSetting(['provider' => 'tmdb', 'is_enabled' => true]); $setting->replaceConfig(['api_key' => 'test-key']); $setting->save();
    }

    private function couple(): array
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]); $partner = User::factory()->create(['role' => 'partner', 'is_active' => true]);
        $space = GallerySpace::create(['name' => 'Filmový pár', 'slug' => 'movie-couple', 'owner_id' => $owner->id, 'is_default' => true]);
        $space->members()->attach($owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $space->members()->attach($partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        return [$owner, $partner, $space];
    }
}
