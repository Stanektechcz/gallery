<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaFileControllerTest extends TestCase
{
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
}
