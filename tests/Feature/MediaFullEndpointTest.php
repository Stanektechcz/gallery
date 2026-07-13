<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\MediaVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaFullEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_heic_viewer_prefers_a_compatible_large_variant(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/heic-test/large.webp', 'webp-preview');

        $user = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $space = GallerySpace::create(['name' => 'HEIC test', 'slug' => 'heic-test', 'owner_id' => $user->id]);
        $space->members()->attach($user->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true]);

        $media = MediaItem::create([
            'uuid' => 'b5c5ce33-6432-4ee7-b0e2-0ddc4eeffa55',
            'gallery_space_id' => $space->id,
            'owner_user_id' => $user->id,
            'uploaded_by' => $user->id,
            'original_filename' => 'IMG_0001.HEIC',
            'safe_filename' => 'IMG_0001.HEIC',
            'extension' => 'heic',
            'mime_type' => 'image/heic',
            'media_type' => 'photo',
            'size_bytes' => 100,
        ]);
        MediaVariant::create([
            'media_item_id' => $media->id,
            'type' => 'large',
            'disk' => 'public',
            'path' => 'media/heic-test/large.webp',
            'format' => 'webp',
            'mime_type' => 'image/webp',
        ]);

        $this->actingAs($user)
            ->get("/media/{$media->uuid}/full")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');
    }
}
