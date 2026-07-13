<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\Album;
use App\Models\MediaItem;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private GallerySpace $space;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->user = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $this->space = GallerySpace::create([
            'uuid'     => \Str::uuid(),
            'name'     => 'Test',
            'slug'     => 'test',
            'owner_id' => $this->user->id,
        ]);
        $this->space->members()->attach($this->user->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true]);
    }

    /** @test */
    public function test_can_initiate_upload_session(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/uploads', [
                'filename'     => 'test.jpg',
                'mime_type'    => 'image/jpeg',
                'total_size'   => 1024 * 1024,
                'total_chunks' => 4,
            ]);

        $response->assertCreated();
        $response->assertJsonStructure(['uuid', 'total_chunks', 'received_chunks', 'status']);

        $this->assertDatabaseHas('upload_sessions', [
            'original_filename' => 'test.jpg',
            'user_id'           => $this->user->id,
            'status'            => 'pending',
        ]);
    }

    /** @test */
    public function test_can_upload_chunk(): void
    {
        // Initiate
        $init = $this->actingAs($this->user)
            ->postJson('/api/v1/uploads', [
                'filename'     => 'photo.jpg',
                'mime_type'    => 'image/jpeg',
                'total_size'   => 2048,
                'total_chunks' => 2,
            ]);

        $uuid = $init->json('uuid');

        // Upload chunk 0
        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/uploads/{$uuid}/chunks/0", [
                'chunk' => UploadedFile::fake()->createWithContent('chunk_0', str_repeat('A', 1024)),
            ]);

        $response->assertOk();
        $this->assertEquals(1, $response->json('received_chunks'));
        $this->assertFalse($response->json('complete'));
    }

    /** @test */
    public function test_can_get_session_status(): void
    {
        $init = $this->actingAs($this->user)
            ->postJson('/api/v1/uploads', [
                'filename'     => 'test.jpg',
                'mime_type'    => 'image/jpeg',
                'total_size'   => 1024,
                'total_chunks' => 1,
            ]);

        $uuid = $init->json('uuid');

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/uploads/{$uuid}");

        $response->assertOk();
        $response->assertJsonFragment(['uuid' => $uuid, 'status' => 'pending']);
    }

    /** @test */
    public function test_cannot_complete_incomplete_session(): void
    {
        $init = $this->actingAs($this->user)
            ->postJson('/api/v1/uploads', [
                'filename'     => 'test.jpg',
                'mime_type'    => 'image/jpeg',
                'total_size'   => 2048,
                'total_chunks' => 2,
            ]);

        $uuid = $init->json('uuid');

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/uploads/{$uuid}/complete");

        $response->assertUnprocessable();
        $response->assertJsonStructure(['error', 'received_chunks', 'total_chunks']);
    }

    /** @test */
    public function test_can_cancel_upload(): void
    {
        $init = $this->actingAs($this->user)
            ->postJson('/api/v1/uploads', [
                'filename'     => 'test.jpg',
                'mime_type'    => 'image/jpeg',
                'total_size'   => 1024,
                'total_chunks' => 1,
            ]);

        $uuid = $init->json('uuid');

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/uploads/{$uuid}");

        $response->assertOk();
        $this->assertDatabaseMissing('upload_sessions', ['uuid' => $uuid]);
    }

    /** @test */
    public function test_unauthenticated_cannot_upload(): void
    {
        $response = $this->postJson('/api/v1/uploads', [
            'filename'     => 'test.jpg',
            'mime_type'    => 'image/jpeg',
            'total_size'   => 1024,
            'total_chunks' => 1,
        ]);

        $response->assertUnauthorized();
    }

    /** @test */
    public function test_completed_photo_is_saved_into_its_album_with_metadata_and_media_uuid(): void
    {
        $album = Album::create([
            'gallery_space_id' => $this->space->id,
            'title' => 'Víkend v Brně',
            'slug' => 'vikend-v-brne',
            'created_by' => $this->user->id,
        ]);
        $contents = 'not-a-real-jpeg-but-a-complete-upload';

        $init = $this->actingAs($this->user)->postJson('/api/v1/uploads', [
            'filename' => 'výlet do Brna.jpg',
            'mime_type' => 'image/jpeg',
            'total_size' => strlen($contents),
            'total_chunks' => 1,
            'target_album_id' => $album->id,
        ])->assertCreated();

        $uuid = $init->json('uuid');
        $this->actingAs($this->user)
            ->putJson("/api/v1/uploads/{$uuid}/chunks/0", [
                'chunk' => UploadedFile::fake()->createWithContent('chunk_0', $contents),
            ])
            ->assertOk();

        $completed = $this->actingAs($this->user)
            ->postJson("/api/v1/uploads/{$uuid}/complete")
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonStructure(['media_id', 'media_uuid']);

        $media = MediaItem::findOrFail($completed->json('media_id'));
        $this->assertSame($album->id, $media->primary_album_id);
        $this->assertSame('ready', $media->status);
        $this->assertSame('výlet do Brna.jpg', $media->original_filename);
        $this->assertNotNull($media->uploaded_at);
        $this->assertDatabaseHas('album_media', [
            'album_id' => $album->id,
            'media_item_id' => $media->id,
            'added_by' => $this->user->id,
        ]);
        $this->assertSame(1, $album->fresh()->media_count);
        $this->assertDatabaseHas('upload_sessions', [
            'uuid' => $uuid,
            'resulting_media_id' => $media->id,
            'status' => 'completed',
        ]);
        Storage::disk('public')->assertExists("media/{$media->uuid}/original.jpg");
    }

    /** @test */
    public function test_user_cannot_start_upload_into_an_album_from_another_gallery_space(): void
    {
        $otherOwner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $otherSpace = GallerySpace::create([
            'uuid' => \Str::uuid(), 'name' => 'Cizí prostor', 'slug' => 'cizi-prostor', 'owner_id' => $otherOwner->id,
        ]);
        $otherAlbum = Album::create([
            'gallery_space_id' => $otherSpace->id,
            'title' => 'Cizí album',
            'slug' => 'cizi-album',
            'created_by' => $otherOwner->id,
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/uploads', [
            'filename' => 'test.jpg', 'mime_type' => 'image/jpeg', 'total_size' => 10, 'total_chunks' => 1,
            'target_album_id' => $otherAlbum->id,
        ])->assertUnprocessable();
    }

    /** @test */
    public function test_duplicate_is_added_to_the_requested_album_instead_of_disappearing_from_upload_queue(): void
    {
        $album = Album::create([
            'gallery_space_id' => $this->space->id, 'title' => 'Nové album', 'slug' => 'nove-album', 'created_by' => $this->user->id,
        ]);
        $media = MediaItem::create([
            'gallery_space_id' => $this->space->id, 'owner_user_id' => $this->user->id, 'uploaded_by' => $this->user->id,
            'original_filename' => 'stejna-fotka.jpg', 'safe_filename' => 'stejna-fotka.jpg', 'extension' => 'jpg',
            'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 42,
            'sha256' => str_repeat('a', 64), 'status' => 'ready', 'storage_status' => 'local_only', 'uploaded_at' => now(),
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/uploads/check-duplicate', [
            'sha256' => str_repeat('a', 64), 'target_album_id' => $album->id,
        ])->assertOk()
            ->assertJsonPath('exists', true)
            ->assertJsonPath('media_uuid', $media->uuid)
            ->assertJsonPath('added_to_album', true);

        $this->assertDatabaseHas('album_media', ['album_id' => $album->id, 'media_item_id' => $media->id]);
        $this->assertSame(1, $album->fresh()->media_count);
    }

    /** @test */
    public function test_video_upload_gets_an_immediate_fallback_thumbnail_when_ffmpeg_is_not_available(): void
    {
        $contents = 'minimal-video-payload';
        $init = $this->actingAs($this->user)->postJson('/api/v1/uploads', [
            'filename' => '20260627.mp4', 'mime_type' => 'video/mp4',
            'total_size' => strlen($contents), 'total_chunks' => 1,
        ])->assertCreated();
        $uuid = $init->json('uuid');
        $this->actingAs($this->user)->putJson("/api/v1/uploads/{$uuid}/chunks/0", [
            'chunk' => UploadedFile::fake()->createWithContent('chunk_0', $contents),
        ])->assertOk();

        $mediaId = $this->actingAs($this->user)->postJson("/api/v1/uploads/{$uuid}/complete")
            ->assertOk()->json('media_id');
        $media = MediaItem::findOrFail($mediaId);

        $this->assertSame('2026-06-27', $media->taken_at?->toDateString());
        $this->assertSame('Video z 27. 6. 2026', $media->display_title);
        $this->assertDatabaseHas('media_variants', ['media_item_id' => $media->id, 'type' => 'thumbnail', 'mime_type' => 'image/svg+xml']);
    }

    /** @test */
    public function test_duplicate_is_skipped_only_when_it_is_already_visible_in_the_target_album(): void
    {
        $album = Album::create(['gallery_space_id' => $this->space->id, 'title' => 'Viditelné', 'slug' => 'viditelne', 'created_by' => $this->user->id]);
        $media = MediaItem::create([
            'gallery_space_id' => $this->space->id, 'owner_user_id' => $this->user->id, 'uploaded_by' => $this->user->id,
            'primary_album_id' => $album->id, 'original_filename' => 'uz-v-albu.jpg', 'safe_filename' => 'uz-v-albu.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 1,
            'sha256' => str_repeat('b', 64), 'status' => 'ready', 'storage_status' => 'local_only', 'uploaded_at' => now(),
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/uploads/check-duplicate', [
            'sha256' => str_repeat('b', 64), 'target_album_id' => $album->id,
        ])->assertOk()->assertJsonPath('exists', true)->assertJsonPath('added_to_album', false);

        $media->update(['status' => 'failed']);
        $this->actingAs($this->user)->postJson('/api/v1/uploads/check-duplicate', [
            'sha256' => str_repeat('b', 64), 'target_album_id' => $album->id,
        ])->assertOk()->assertJsonPath('exists', false)->assertJsonPath('replacement_required', true);
    }
}
