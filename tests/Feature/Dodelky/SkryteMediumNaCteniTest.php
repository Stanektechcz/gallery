<?php

namespace Tests\Feature\Dodelky;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\Place;
use App\Models\PlaceReview;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fotka přesunutá do trezoru nebo koše po jejím připojení k receptu nebo
 * hodnocení podniku se v čtecí odpovědi znovu neobjeví.
 */
class SkryteMediumNaCteniTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        $partner = User::factory()->create(['role' => 'partner']);
        $this->space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Galerie', 'slug' => 'galerie', 'owner_id' => $this->owner->id]);
        $this->space->members()->attach($this->owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->space->members()->attach($partner->id, ['role' => 'editor', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $this->actingAs($this->owner);
    }

    public function test_recipe_read_hides_cover_and_media_moved_to_vault_or_trash(): void
    {
        $viditelna = $this->media('viditelna.jpg');
        $trezorova = $this->media('trezor.jpg', ['is_hidden' => true]);
        $kosova = $this->media('kos.jpg', ['trashed_at' => now()]);

        $recipe = Recipe::create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id, 'created_by' => $this->owner->id, 'updated_by' => $this->owner->id,
            'cover_media_id' => $trezorova->id, 'title' => 'Guláš', 'category' => 'main_course', 'difficulty' => 'easy',
            'status' => 'published', 'base_servings' => 4, 'currency' => 'CZK',
        ]);
        DB::table('recipe_media')->insert([
            ['recipe_id' => $recipe->id, 'media_item_id' => $viditelna->id, 'role' => 'gallery', 'sort_order' => 0, 'created_at' => now()],
            ['recipe_id' => $recipe->id, 'media_item_id' => $kosova->id, 'role' => 'gallery', 'sort_order' => 1, 'created_at' => now()],
        ]);

        $response = $this->getJson('/api/v1/recipes/'.$recipe->uuid)->assertOk();
        $response->assertJsonPath('cover', null);
        $mediaUuids = collect($response->json('media'))->pluck('uuid');
        $this->assertTrue($mediaUuids->contains($viditelna->uuid));
        $this->assertFalse($mediaUuids->contains($kosova->uuid));
    }

    public function test_place_review_read_hides_media_moved_to_vault_or_trash(): void
    {
        $place = Place::create(['gallery_space_id' => $this->space->id, 'name' => 'Bistro', 'type' => 'restaurant', 'city' => 'Brno', 'created_by' => $this->owner->id]);
        $viditelna = $this->media('viditelna.jpg');
        $trezorova = $this->media('trezor.jpg', ['is_hidden' => true]);
        $review = PlaceReview::create([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id, 'place_id' => $place->id, 'author_user_id' => $this->owner->id,
            'status' => 'published', 'visited_at' => now(), 'currency' => 'CZK', 'overall_rating' => 5,
        ]);
        DB::table('place_review_media')->insert([
            ['place_review_id' => $review->id, 'media_item_id' => $viditelna->id, 'subject' => 'overall', 'sort_order' => 0, 'created_at' => now()],
            ['place_review_id' => $review->id, 'media_item_id' => $trezorova->id, 'subject' => 'overall', 'sort_order' => 1, 'created_at' => now()],
        ]);

        $response = $this->getJson("/api/v1/places/{$place->id}/reviews")->assertOk();
        $mediaUuids = collect($response->json('reviews.0.media'))->pluck('uuid');
        $this->assertTrue($mediaUuids->contains($viditelna->uuid));
        $this->assertFalse($mediaUuids->contains($trezorova->uuid));
    }

    private function media(string $filename, array $extra = []): MediaItem
    {
        return MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(), 'gallery_space_id' => $this->space->id, 'owner_user_id' => $this->owner->id, 'uploaded_by' => $this->owner->id,
            'original_filename' => $filename, 'safe_filename' => $filename, 'extension' => 'jpg', 'mime_type' => 'image/jpeg',
            'media_type' => 'photo', 'size_bytes' => 4096, 'status' => 'ready', 'storage_status' => 'local_only', 'is_hidden' => false,
            'taken_at' => now(), 'uploaded_at' => now(),
        ], $extra));
    }
}
