<?php

namespace Tests\Feature\Media;

use App\Models\MediaItem;
use App\Services\Media\UpravaFotky;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Otočení/výřez si vezme první soubor, který jde přečíst.
 *
 * `zdroj()` vracel první existující soubor — u RAW fotky originál `.cr2`,
 * u HEIC bez Imagicku originál, který GD neumí — a `decodePath()` pak
 * vyhodil výjimku, kterou nikdo nechytal: 500 na každé otočení.
 */
class UpravaZeZdrojeTest extends TestCase
{
    use RefreshDatabase;
    use VytvariMedia;

    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('gd') && ! extension_loaded('imagick')) {
            $this->markTestSkipped('Bez GD i Imagick se fotka upravit nedá.');
        }
        Storage::fake('public');
        $this->zalozProstor();
    }

    private function sOriginalem(string $pripona, string $obsah, array $atributy = []): MediaItem
    {
        $fotka = $this->media(array_merge(['extension' => $pripona, 'original_filename' => "IMG_1.{$pripona}"], $atributy));
        $original = "media/{$fotka->uuid}/original.{$pripona}";
        Storage::disk('public')->put($original, $obsah);
        $this->varianta($fotka, 'original', $original);

        $velky = "media/{$fotka->uuid}/large.jpg";
        Storage::disk('public')->put($velky, $this->jpeg(400, 300));
        $this->varianta($fotka, 'large', $velky);

        return $fotka;
    }

    public function test_raw_original_se_preskoci_a_pouzije_se_velky_nahled(): void
    {
        $fotka = $this->sOriginalem('cr2', 'tohle není obrázek, ale RAW', ['is_raw' => true]);

        $this->assertTrue(app(UpravaFotky::class)->uloz($fotka, 90, false, $this->adri));

        $upravena = $fotka->variants()->where('type', 'edited_preview')->first();
        $this->assertNotNull($upravena);
        // 400×300 otočené o 90° je 300×400.
        $this->assertSame([300, 400], [(int) $upravena->width, (int) $upravena->height]);
    }

    public function test_necitelny_original_nezpusobi_chybu(): void
    {
        $fotka = $this->sOriginalem('heic', 'HEIC, které GD nepřečte');

        $this->assertTrue(app(UpravaFotky::class)->uloz($fotka, 90, false, $this->adri));
        $this->assertTrue($fotka->variants()->where('type', 'edited_preview')->exists());
    }

    public function test_bez_citelneho_zdroje_vrati_false_misto_vyjimky(): void
    {
        $fotka = $this->media(['extension' => 'heic']);
        $original = "media/{$fotka->uuid}/original.heic";
        Storage::disk('public')->put($original, 'nečitelné');
        $this->varianta($fotka, 'original', $original);

        $this->assertFalse(app(UpravaFotky::class)->uloz($fotka, 90, false, $this->adri));
    }
}
