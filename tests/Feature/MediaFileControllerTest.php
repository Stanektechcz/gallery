<?php

namespace Tests\Feature;

use App\Jobs\Media\GenerateImageVariantsJob;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaFileControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_video_files_support_byte_ranges_for_fast_browser_playback(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/video-uuid/video_compat.mp4', '0123456789');

        $response = $this->withHeader('Range', 'bytes=2-5')
            ->get('/files/media/video-uuid/video_compat.mp4');

        $response->assertStatus(206)
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Range', 'bytes 2-5/10')
            ->assertHeader('Content-Length', '4');
        $this->assertSame('2345', $response->streamedContent());
    }

    public function test_video_file_rejects_invalid_byte_ranges(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/video-uuid/video.mp4', '0123456789');

        $this->withHeader('Range', 'bytes=20-30')
            ->get('/files/media/video-uuid/video.mp4')
            ->assertStatus(416)
            ->assertHeader('Content-Range', 'bytes */10');
    }

    public function test_missing_photo_thumbnail_returns_lightweight_preview_and_queues_repair(): void
    {
        Storage::fake('public');
        Queue::fake();

        $user = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $space = GallerySpace::create(['name' => 'Test', 'slug' => 'test-previews', 'owner_id' => $user->id]);
        $media = MediaItem::create([
            'uuid' => 'e95ce0f5-d27a-45b5-a11d-9c187a110c44',
            'gallery_space_id' => $space->id,
            'owner_user_id' => $user->id,
            'uploaded_by' => $user->id,
            'original_filename' => 'photo.jpg',
            'safe_filename' => 'photo.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 100,
        ]);

        $this->get('/files/media/' . $media->uuid . '/thumbnail.jpg')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertHeader('X-Gallery-Preview-Repair', 'queued');

        Queue::assertPushed(GenerateImageVariantsJob::class);
    }
}
