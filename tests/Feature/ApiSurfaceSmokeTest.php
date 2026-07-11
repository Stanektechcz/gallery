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
}
