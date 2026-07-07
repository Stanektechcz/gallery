<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TripRoutesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private int $tripId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $space = GallerySpace::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Testovací galerie',
            'slug' => 'testovaci-galerie',
            'owner_id' => $this->user->id,
            'is_default' => true,
        ]);
        $space->members()->attach($this->user->id, [
            'role' => 'owner',
            'can_delete' => true,
            'can_share' => true,
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/trips', [
            'name' => 'Alpská cesta',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-04',
        ]);

        $response->assertCreated()->assertJsonPath('duration_days', 4)->assertJsonPath('media_count', 0);
        $this->tripId = $response->json('id');
    }

    public function test_multiple_waypoints_are_saved_in_one_request_in_order(): void
    {
        $response = $this->postJson("/api/v1/trips/{$this->tripId}/waypoints", [
            'waypoints' => [
                ['place_name' => 'Praha', 'latitude' => 50.0755, 'longitude' => 14.4378],
                ['place_name' => 'Vídeň', 'latitude' => 48.2082, 'longitude' => 16.3738],
                ['place_name' => 'Benátky', 'latitude' => 45.4408, 'longitude' => 12.3155],
            ],
        ]);

        $response->assertCreated()->assertJsonCount(3)->assertJsonPath('2.place_name', 'Benátky');
        $this->assertDatabaseHas('trip_waypoints', ['trip_id' => $this->tripId, 'place_name' => 'Praha', 'sort_order' => 0]);
        $this->assertDatabaseHas('trip_waypoints', ['trip_id' => $this->tripId, 'place_name' => 'Benátky', 'sort_order' => 2]);
    }

    public function test_invalid_bulk_request_does_not_save_a_partial_route(): void
    {
        $this->postJson("/api/v1/trips/{$this->tripId}/waypoints", [
            'waypoints' => [
                ['place_name' => 'Praha', 'latitude' => 50.0755],
                ['place_name' => '', 'latitude' => 999],
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('trip_waypoints', 0);
    }

    public function test_reorder_requires_every_waypoint_exactly_once(): void
    {
        $waypoints = $this->postJson("/api/v1/trips/{$this->tripId}/waypoints", [
            'waypoints' => [
                ['place_name' => 'Praha'],
                ['place_name' => 'Vídeň'],
            ],
        ])->json();

        $this->putJson("/api/v1/trips/{$this->tripId}/waypoints/reorder", [
            'order' => [$waypoints[0]['id']],
        ])->assertUnprocessable();
    }
}
