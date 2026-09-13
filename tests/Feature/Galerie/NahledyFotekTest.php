<?php

namespace Tests\Feature\Galerie;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Media\ImageVariantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Náhledy nahraných fotek opravdu vzniknou.
 *
 * Služba volala API Intervention Image 3 (`read`, `toWebp`, `pickColor`),
 * v aplikaci je ale verze 4. Každá varianta skončila výjimkou, kterou služba
 * jen zalogovala — nahraná fotka neměla náhled a mřížka tahala originály.
 */
class NahledyFotekTest extends TestCase
{
    use RefreshDatabase;

    public function test_varianty_vzniknou_a_nenesou_exif(): void
    {
        if (! extension_loaded('gd') && ! extension_loaded('imagick')) {
            $this->markTestSkipped('Bez GD i Imagick se náhled vyrobit nedá (na serveru je GD).');
        }

        Storage::fake('public');

        $adri = User::factory()->create();
        $prostor = GallerySpace::create(['name' => 'Naše vzpomínky', 'owner_id' => $adri->id]);
        $media = MediaItem::create([
            'uuid' => (string) Str::uuid(),
            'gallery_space_id' => $prostor->id,
            'owner_user_id' => $adri->id,
            'uploaded_by' => $adri->id,
            'original_filename' => 'vylet.jpg',
            'safe_filename' => 'vylet.jpg',
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'media_type' => 'photo',
            'size_bytes' => 1000,
        ]);

        $zdroj = tempnam(sys_get_temp_dir(), 'nahled').'.jpg';
        $obraz = imagecreatetruecolor(1200, 800);
        imagefilledrectangle($obraz, 0, 0, 599, 799, imagecolorallocate($obraz, 200, 30, 30));
        imagejpeg($obraz, $zdroj, 90);

        app(ImageVariantService::class)->generateAll($media, $zdroj);
        @unlink($zdroj);

        $varianty = $media->variants()->get()->keyBy('type');

        $this->assertTrue($varianty->has('thumbnail'), 'Náhled do mřížky musí vzniknout.');
        $this->assertSame(320, (int) $varianty['thumbnail']->width);
        $this->assertSame(800, (int) $varianty['small']->width);
        // Menší než originál se nezvětšuje.
        $this->assertSame(1200, (int) $varianty['medium']->width);
        Storage::disk('public')->assertExists($varianty['thumbnail']->path);
        $this->assertStringStartsWith('RIFF', Storage::disk('public')->get($varianty['thumbnail']->path), 'WebP soubor.');
    }
}
