<?php

namespace Tests\Feature;

use App\Jobs\Drive\CreateDriveFolderJob;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\Place;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlaceReviewSystemTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $partner;
    private GallerySpace $space;
    private Place $place;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['name' => 'Adrian', 'role' => 'owner']);
        $this->partner = User::factory()->create(['name' => 'Markétka', 'role' => 'partner']);
        $this->space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Naše galerie', 'slug' => 'nase-galerie', 'owner_id' => $this->owner->id]);
        $this->space->members()->attach($this->owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->space->members()->attach($this->partner->id, ['role' => 'editor', 'can_delete' => false, 'can_share' => true, 'joined_at' => now()]);
        $this->place = Place::create(['gallery_space_id' => $this->space->id, 'name' => 'Bistro U nás', 'type' => 'restaurant', 'city' => 'Brno', 'created_by' => $this->owner->id]);
        $this->actingAs($this->owner);
    }

    public function test_partners_can_rate_one_visit_independently_with_menu_items_and_photos(): void
    {
        $plan = $this->postJson("/api/v1/places/{$this->place->id}/plans", ['planned_for' => now()->subDay()->toDateString()])->assertCreated()->json();
        $photo = $this->media('ramen.jpg');
        $ownerReview = $this->postJson("/api/v1/places/{$this->place->id}/reviews", [
            'status' => 'published',
            'place_plan_uuid' => $plan['uuid'],
            'visited_at' => now()->subDay()->setTime(18, 30)->toIso8601String(),
            'visit_context' => 'dinner',
            'party_size' => 2,
            'overall_rating' => 5,
            'service_rating' => 4,
            'staff_friendliness_rating' => 5,
            'food_rating' => 5,
            'food_quality_rating' => 5,
            'drink_rating' => 4,
            'speed_rating' => 3,
            'menu_rating' => 4,
            'atmosphere_rating' => 5,
            'cleanliness_rating' => 5,
            'value_rating' => 4,
            'wait_minutes' => 24,
            'total_amount' => 890,
            'currency' => 'CZK',
            'would_return' => true,
            'recommends' => true,
            'positives' => 'Výborný vývar a milá obsluha.',
            'items' => [[
                'category' => 'food', 'name' => 'Tonkotsu ramen', 'quantity' => 1,
                'overall_rating' => 5, 'quality_rating' => 5, 'price' => 329,
                'would_order_again' => true, 'note' => 'Silný vývar.',
            ]],
            'media_uuids' => [$photo->uuid],
        ])->assertCreated()->assertJsonPath('ratings.overall', 5)->assertJsonPath('items.0.name', 'Tonkotsu ramen')->assertJsonPath('media.0.uuid', $photo->uuid)->json();

        $this->assertDatabaseHas('place_plans', ['id' => $plan['id'], 'state' => 'visited']);
        $this->assertDatabaseHas('media_place', ['place_id' => $this->place->id, 'media_item_id' => $photo->id]);
        $this->assertDatabaseHas('places', ['id' => $this->place->id, 'personal_rating' => 5]);

        $this->actingAs($this->partner)->postJson("/api/v1/places/{$this->place->id}/reviews", [
            'status' => 'published',
            'place_plan_uuid' => $plan['uuid'],
            'visited_at' => now()->subDay()->setTime(18, 30)->toIso8601String(),
            'visit_context' => 'dinner',
            'overall_rating' => 3,
            'service_rating' => 3,
            'food_rating' => 4,
            'value_rating' => 2,
            'currency' => 'CZK',
            'would_return' => true,
            'recommends' => false,
            'improvements' => 'Dlouhé čekání.',
            'items' => [['category' => 'food', 'name' => 'Tonkotsu ramen', 'overall_rating' => 4, 'would_order_again' => true]],
        ])->assertCreated()->assertJsonPath('author.name', 'Markétka');

        $summary = $this->getJson("/api/v1/places/{$this->place->id}/reviews")
            ->assertOk()
            ->assertJsonPath('summary.review_count', 2)
            ->assertJsonPath('summary.reviewers_count', 2)
            ->assertJsonPath('summary.criteria.0.average', 4)
            ->assertJsonCount(2, 'partner_comparison')
            ->assertJsonPath('top_items.0.name', 'Tonkotsu ramen');
        $this->assertCount(2, $summary->json('reviews'));

        $this->putJson("/api/v1/places/{$this->place->id}/reviews/{$ownerReview['uuid']}", [
            'status' => 'published', 'overall_rating' => 1, 'currency' => 'CZK',
        ])->assertForbidden();
    }

    public function test_drafts_are_private_and_review_album_is_shared_and_idempotent(): void
    {
        Queue::fake();
        $draft = $this->postJson("/api/v1/places/{$this->place->id}/reviews", [
            'status' => 'draft', 'currency' => 'CZK', 'notes' => 'Doplnit cenu a fotografie.',
        ])->assertCreated()->assertJsonPath('status', 'draft')->json();
        $this->actingAs($this->partner)->getJson("/api/v1/places/{$this->place->id}/reviews")
            ->assertOk()->assertJsonCount(0, 'reviews')->assertJsonPath('summary.review_count', 0);

        $this->actingAs($this->owner);
        $first = $this->postJson("/api/v1/places/{$this->place->id}/review-album")
            ->assertCreated()->assertJsonPath('created', true)->json('album');
        $this->postJson("/api/v1/places/{$this->place->id}/review-album")
            ->assertOk()->assertJsonPath('created', false)->assertJsonPath('album.id', $first['id']);
        Queue::assertPushed(CreateDriveFolderJob::class, 1);
        $this->assertDatabaseHas('album_place', ['album_id' => $first['id'], 'place_id' => $this->place->id, 'is_primary' => true]);
        $this->assertDatabaseHas('album_user_permissions', ['album_id' => $first['id'], 'user_id' => $this->partner->id, 'role' => 'editor']);

        $this->deleteJson("/api/v1/places/{$this->place->id}/reviews/{$draft['uuid']}")->assertOk();
        $this->assertDatabaseMissing('place_reviews', ['uuid' => $draft['uuid']]);
    }

    private function media(string $filename): MediaItem
    {
        $media = MediaItem::create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id,
            'owner_user_id' => $this->owner->id, 'uploaded_by' => $this->owner->id,
            'original_filename' => $filename, 'safe_filename' => $filename, 'extension' => 'jpg',
            'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 1000,
            'status' => 'ready', 'storage_status' => 'local_only', 'is_hidden' => false,
            'taken_at' => now()->subDay(), 'uploaded_at' => now(),
        ]);
        DB::table('media_variants')->insert([
            'media_item_id' => $media->id, 'type' => 'thumbnail', 'disk' => 'public',
            'path' => "media/{$media->uuid}/thumbnail.jpg", 'format' => 'jpg',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $media->fresh();
    }
}
