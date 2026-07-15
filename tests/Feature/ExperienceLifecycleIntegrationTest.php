<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\Place;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExperienceLifecycleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_place_outing_stays_linked_from_calendar_through_gallery_memory_and_reviews(): void
    {
        $owner = User::factory()->create(['name' => 'Adrian', 'role' => 'owner']);
        $partner = User::factory()->create(['name' => 'Markétka', 'role' => 'partner']);
        $space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Naše galerie', 'slug' => 'nase-galerie', 'owner_id' => $owner->id]);
        $space->members()->attach($owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $space->members()->attach($partner->id, ['role' => 'editor', 'can_delete' => false, 'can_share' => true, 'joined_at' => now()]);
        $place = Place::create(['gallery_space_id' => $space->id, 'name' => 'Bistro Ve dvou', 'type' => 'restaurant', 'city' => 'Brno', 'created_by' => $owner->id]);
        $event = CalendarEvent::create([
            'gallery_space_id' => $space->id, 'created_by' => $owner->id, 'title' => 'Rande · Bistro Ve dvou',
            'type' => 'outing', 'status' => 'planned', 'starts_at' => now()->subDay()->setTime(18, 0),
            'ends_at' => now()->subDay()->setTime(20, 0), 'timezone' => 'Europe/Prague', 'place_name' => $place->name,
            'metadata' => ['kind' => 'place_recommendation_outing', 'place_id' => $place->id, 'recommendation_reason' => 'shodli jste se na něm oba'],
        ]);
        $event->participants()->sync([
            $owner->id => ['role' => 'owner', 'response' => 'accepted'],
            $partner->id => ['role' => 'editor', 'response' => 'accepted'],
        ]);
        $planId = DB::table('place_plans')->insertGetId([
            'uuid' => $planUuid = (string) Str::uuid(), 'place_id' => $place->id, 'gallery_space_id' => $space->id,
            'created_by' => $owner->id, 'calendar_event_id' => $event->id, 'state' => 'planned',
            'planned_for' => $event->starts_at->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $media = MediaItem::create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $space->id, 'owner_user_id' => $owner->id, 'uploaded_by' => $owner->id,
            'original_filename' => 'vecere.jpg', 'safe_filename' => 'vecere.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg',
            'media_type' => 'photo', 'size_bytes' => 2048, 'status' => 'ready', 'storage_status' => 'local_only', 'is_hidden' => false,
            'taken_at' => $event->starts_at->copy()->addHour(), 'uploaded_at' => now(),
        ]);

        $this->actingAs($owner)->getJson("/api/v1/calendar/events/{$event->uuid}")
            ->assertOk()
            ->assertJsonPath('experience.next_action', 'add_media')
            ->assertJsonPath('experience.place.id', $place->id)
            ->assertJsonPath('experience.place_plan.state', 'planned')
            ->assertJsonPath('origin.kind', 'place_recommendation_outing');

        $this->putJson("/api/v1/calendar/events/{$event->uuid}/reflection", [
            'rating' => 5, 'mood' => 'cozy', 'highlight' => 'Skvělý společný večer.', 'next_time' => 'Objednat znovu dezert.',
        ])->assertCreated();

        $memory = $this->postJson("/api/v1/calendar/events/{$event->uuid}/shared-memory", ['media_ids' => [$media->id]])
            ->assertCreated()
            ->assertJsonPath('experience.next_action', 'review_place')
            ->assertJsonPath('experience.progress_percent', 80)
            ->json();

        $this->assertDatabaseHas('calendar_events', ['id' => $event->id, 'status' => 'completed']);
        $this->assertDatabaseHas('place_plans', ['id' => $planId, 'state' => 'visited', 'visited_on' => $event->starts_at->toDateString()]);
        $this->assertDatabaseHas('shared_memory_moments', ['uuid' => $memory['uuid'], 'calendar_event_id' => $event->id, 'place_plan_id' => $planId]);
        $this->assertDatabaseHas('media_place', ['media_item_id' => $media->id, 'place_id' => $place->id]);
        $this->assertDatabaseHas('album_place', ['album_id' => $event->fresh()->album_id, 'place_id' => $place->id]);
        $this->assertDatabaseHas('albums', ['id' => $event->fresh()->album_id, 'default_place_id' => $place->id]);
        $this->assertDatabaseHas('places', ['id' => $place->id, 'next_time_note' => 'Objednat znovu dezert.']);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.partner_hub.experience_follow_up.uuid', $event->uuid)
            ->where('data.partner_hub.experience_follow_up.next_action', 'review_place')
            ->where('data.partner_hub.experience_follow_up.progress_percent', 80));

        $this->postJson("/api/v1/places/{$place->id}/reviews", [
            'status' => 'published', 'place_plan_uuid' => $planUuid, 'overall_rating' => 5,
            'visited_at' => $event->starts_at->toIso8601String(), 'would_return' => true, 'currency' => 'CZK',
        ])->assertCreated();

        $this->getJson("/api/v1/calendar/events/{$event->uuid}")
            ->assertOk()->assertJsonPath('experience.progress_percent', 100)->assertJsonPath('experience.next_action', 'complete');

        $this->actingAs($partner)->getJson("/api/v1/calendar/events/{$event->uuid}")
            ->assertOk()->assertJsonPath('experience.progress_percent', 80)->assertJsonPath('experience.next_action', 'review_place');
    }
}
