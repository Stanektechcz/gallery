<?php

namespace Tests\Feature\StareApi;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nález 3: „tento den" a výchozí měsíc kalendáře musí počítat s pražským
 * dnem, ne s UTC — v okně mezi pražskou půlnocí a druhou hodinou ranní je
 * to jiné datum.
 */
class TimelineMemoriesTest extends TestCase
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

    public function test_vzpominky_pocitaji_prazsky_den_ne_utc(): void
    {
        // 2026-07-15 22:30 UTC = 2026-07-16 00:30 v Praze (léto, +2)
        $this->travelTo(CarbonImmutable::parse('2026-07-15 22:30:00', 'UTC'));

        $vzpominka = MediaItem::create([
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
            'taken_at' => '2024-07-16 10:00:00',
            'status' => 'ready',
            'storage_status' => 'local',
        ]);

        $odpoved = $this->getJson('/api/v1/timeline/memories')->assertOk();
        $roky = array_keys($odpoved->json('memories'));
        $this->assertContains(2024, $roky, 'Vzpomínka na pražský den 16. 7. chybí, i když je podle UTC ještě 15. 7.');
    }
}
