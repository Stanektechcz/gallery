<?php

namespace Tests\Feature\StareApi;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nález 5: `date_to` bez času nesmí uříznout poslední den.
 */
class DateToTest extends TestCase
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

        MediaItem::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => 'IMG_1.jpg',
            'safe_filename' => 'img.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1024,
            'uploaded_at' => now(),
            'taken_at' => '2024-05-01 14:00:00',
            'status' => 'ready',
            'storage_status' => 'local',
        ]);
    }

    public function test_hledani_nezahodi_posledni_den(): void
    {
        $this->getJson('/api/v1/search?date_to=2024-05-01')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_casova_osa_nezahodi_posledni_den(): void
    {
        $odpoved = $this->getJson('/api/v1/timeline?date_to=2024-05-01')->assertOk();
        $this->assertCount(1, $odpoved->json('data'));
    }
}
