<?php

namespace Tests\Feature\Memories;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Memories\MemoryGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Trezor a koš nejsou vzpomínka.
 *
 * `MemoryGeneratorService` četl fotky bez `is_hidden`/`trashed_at`, takže
 * schovaná nebo vyhozená fotka z minulého roku stejně vytvořila kartu
 * „Před rokem · 1 fotka" — s upozorněním i s uuid, které trezor nebo koš
 * má schovávat i před klientem.
 */
class MemoryGeneratorVaultTest extends TestCase
{
    use RefreshDatabase;

    private GallerySpace $prostor;

    protected function setUp(): void
    {
        parent::setUp();

        $adri = User::factory()->create();
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $adri->id]);
    }

    private function media(array $atributy = []): MediaItem
    {
        return MediaItem::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->prostor->owner_id,
            'uploaded_by' => $this->prostor->owner_id,
            'original_filename' => 'foto.jpg',
            'safe_filename' => 'foto.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1,
            'status' => 'ready',
            'storage_status' => 'ready',
            'is_hidden' => false,
            'taken_at' => now()->subYear(),
            'uploaded_at' => now(),
        ], $atributy));
    }

    public function test_fotky_v_trezoru_nevytvori_vzpominku(): void
    {
        $this->media(['is_hidden' => true]);
        $this->media(['is_hidden' => true]);

        $karty = (new MemoryGeneratorService)->generate($this->prostor, now());

        $this->assertSame([], $karty, 'Fotky v trezoru nesmí založit kartu vzpomínky.');
        $this->assertDatabaseCount('generated_memories', 0);
    }

    public function test_fotky_v_kosi_nevytvori_vzpominku(): void
    {
        $this->media(['trashed_at' => now()->subDay()]);
        $this->media(['trashed_at' => now()->subDay()]);

        $karty = (new MemoryGeneratorService)->generate($this->prostor, now());

        $this->assertSame([], $karty, 'Fotky v koši nesmí založit kartu vzpomínky.');
        $this->assertDatabaseCount('generated_memories', 0);
    }
}
