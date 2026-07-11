<?php

namespace Tests\Feature;

use App\Models\GallerySpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhotoBookPrintQualityTest extends TestCase
{
    use RefreshDatabase;

    public function test_photo_book_reports_a_print_quality_warning_before_export(): void
    {
        $user = User::factory()->create();
        $space = GallerySpace::create(['uuid' => (string) Str::uuid(), 'name' => 'Galerie', 'slug' => 'galerie', 'owner_id' => $user->id]);
        $space->members()->attach($user->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);
        $mediaId = DB::table('media_items')->insertGetId(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $space->id, 'owner_user_id' => $user->id, 'uploaded_by' => $user->id, 'original_filename' => 'mala.jpg', 'safe_filename' => 'mala.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'media_type' => 'photo', 'size_bytes' => 100, 'width' => 1200, 'height' => 900, 'status' => 'ready', 'storage_status' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
        $bookId = DB::table('photo_books')->insertGetId(['uuid' => (string) Str::uuid(), 'gallery_space_id' => $space->id, 'created_by' => $user->id, 'name' => 'Tisk', 'purpose' => 'print', 'item_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('photo_book_items')->insert(['photo_book_id' => $bookId, 'media_item_id' => $mediaId, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $uuid = DB::table('photo_books')->where('id', $bookId)->value('uuid');
        $this->actingAs($user)->getJson("/api/v1/books/{$uuid}")->assertOk()->assertJsonPath('items.0.print_quality', 'low')->assertJsonPath('items.0.recommended_max_cm', 7.6);
    }
}
