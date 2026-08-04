<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A customer must never reach another customer's data, even holding a valid UUID.
 *
 * Scoping was the caller's job on every one of the 123 tenant tables, and
 * CommentController forgot it: MediaItem::where('uuid', ...) loaded any photo in the
 * system. The global scope on the model is what closes this.
 */
class SpaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_photo_from_another_space_is_invisible_everywhere(): void
    {
        [$owner, $ownerSpace] = $this->customer('prvni');
        [$stranger] = $this->customer('druhy');

        $media = $this->photo($ownerSpace, $owner, 'nase-foto.jpg');

        // The owner sees their own photo and its comments.
        $this->actingAs($owner)->getJson("/api/v1/media/{$media->uuid}/comments")->assertOk();

        // The stranger holds a valid UUID and must still get nothing.
        $this->actingAs($stranger)
            ->getJson("/api/v1/media/{$media->uuid}/comments")
            ->assertNotFound();

        $this->actingAs($stranger)
            ->postJson("/api/v1/media/{$media->uuid}/comments", ['body' => 'Cizí komentář'])
            ->assertNotFound();

        $this->assertDatabaseMissing('media_comments', ['body' => 'Cizí komentář']);
    }

    public function test_a_query_for_someone_elses_photo_returns_nothing_at_the_model_level(): void
    {
        [$owner, $ownerSpace] = $this->customer('prvni');
        [$stranger] = $this->customer('druhy');
        $media = $this->photo($ownerSpace, $owner, 'soukrome.jpg');

        $this->actingAs($stranger);
        $this->assertNull(MediaItem::where('uuid', $media->uuid)->first(), 'The global scope must hide foreign media.');
        $this->assertSame(0, MediaItem::count(), 'A customer must not even be able to count foreign media.');

        $this->actingAs($owner);
        $this->assertNotNull(MediaItem::where('uuid', $media->uuid)->first());
    }

    /** Console commands, queued jobs and seeders run without a user and must still see everything. */
    public function test_the_scope_stands_aside_when_nobody_is_signed_in(): void
    {
        [$owner, $ownerSpace] = $this->customer('prvni');
        $this->photo($ownerSpace, $owner, 'job.jpg');

        auth()->logout();
        $this->assertSame(1, MediaItem::count());
    }

    private function photo(GallerySpace $space, User $owner, string $filename): MediaItem
    {
        return MediaItem::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $space->id,
            'owner_user_id' => $owner->id,
            'uploaded_by' => $owner->id,
            'original_filename' => $filename,
            'safe_filename' => $filename,
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1024,
            'status' => 'ready',
            'storage_status' => 'ready',
            'taken_at' => now(),
        ]);
    }

    /** @return array{0:User,1:GallerySpace} */
    private function customer(string $slug): array
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $space = GallerySpace::create(['name' => "Prostor {$slug}", 'slug' => $slug, 'owner_id' => $owner->id, 'is_default' => true]);
        $space->members()->attach($owner->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);

        return [$owner, $space];
    }
}
