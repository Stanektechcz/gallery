<?php

namespace Tests\Feature\StareApi;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nález 2: štítky nesmí počítat fotky z trezoru ani z koše.
 */
class TagVaultTest extends TestCase
{
    use RefreshDatabase;

    private User $adri;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adri = User::factory()->create();
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->adri->gallerySpaces()->syncWithoutDetaching([$this->prostor->id => ['role' => 'owner']]);

        Sanctum::actingAs($this->adri);
    }

    public function test_media_count_pocita_jen_viditelne_fotky(): void
    {
        $tag = Tag::create(['gallery_space_id' => $this->prostor->id, 'name' => 'Léto', 'slug' => 'leto', 'depth' => 0, 'materialized_path' => '', 'created_by' => $this->adri->id]);

        $viditelna = $this->fotka();
        $vTrezoru = $this->fotka(['is_hidden' => true]);
        $vKosi = $this->fotka(['trashed_at' => now()]);
        foreach ([$viditelna, $vTrezoru, $vKosi] as $m) {
            DB::table('media_tag')->insert(['media_item_id' => $m->id, 'tag_id' => $tag->id, 'created_at' => now()]);
        }

        $odpoved = $this->getJson('/api/v1/tags')->assertOk();
        $data = collect($odpoved->json())->firstWhere('id', $tag->id);
        $this->assertSame(1, $data['media_count']);
    }

    private function fotka(array $navic = []): MediaItem
    {
        return MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'IMG_'.Str::random(4).'.jpg',
            'safe_filename' => 'img.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1024,
            'uploaded_at' => now(),
            'taken_at' => now(),
            'status' => 'ready',
            'storage_status' => 'local',
        ], $navic));
    }
}
