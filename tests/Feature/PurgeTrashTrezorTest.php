<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Úklid koše nepíše do protokolu jména souborů z trezoru.
 *
 * `MazaniFotek` jméno souboru trezorové položky do protokolu vědomě nedává
 * (přehled „Dnes" jména z protokolu vypisuje). `gallery:purge-trash` ho tam
 * po třiceti dnech stejně zapsal — i u fotky, která byla v trezoru.
 */
class PurgeTrashTrezorTest extends TestCase
{
    use RefreshDatabase;

    private GallerySpace $prostor;

    private User $adri;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->adri = User::factory()->create();
        $this->prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $this->adri->id]);
        $this->adri->gallerySpaces()->syncWithoutDetaching([$this->prostor->id => ['role' => 'owner']]);
    }

    public function test_trezorova_polozka_nenecha_jmeno_v_protokolu(): void
    {
        $this->media('tajne-foto.jpg', true);

        $this->artisan('gallery:purge-trash')->expectsOutputToContain('Smazáno: 1')->assertSuccessful();

        $zaznam = AuditLog::where('action', 'media.purge')->sole();
        $this->assertArrayNotHasKey('filename', (array) $zaznam->payload);
        $this->assertStringNotContainsString('tajne-foto', json_encode($zaznam->payload, JSON_UNESCAPED_UNICODE));
    }

    public function test_bezna_polozka_jmeno_v_protokolu_ma(): void
    {
        $this->media('vylet.jpg', false);

        $this->artisan('gallery:purge-trash')->assertSuccessful();

        $this->assertSame('vylet.jpg', AuditLog::where('action', 'media.purge')->sole()->payload['filename'] ?? null);
    }

    public function test_nasucho_jmeno_z_trezoru_nevypise(): void
    {
        $this->media('tajne-foto.jpg', true);

        $this->artisan('gallery:purge-trash --nasucho')
            ->doesntExpectOutputToContain('tajne-foto')
            ->expectsOutputToContain('Ke smazání: 1')
            ->assertSuccessful();
    }

    private function media(string $jmeno, bool $vTrezoru): MediaItem
    {
        return MediaItem::create([
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'uploaded_by' => $this->adri->id,
            'original_filename' => $jmeno,
            'safe_filename' => $jmeno,
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1024,
            'status' => 'ready',
            'is_hidden' => $vTrezoru,
            'trashed_at' => now()->subDays(31),
            'purge_after' => now()->subDay(),
        ]);
    }
}
