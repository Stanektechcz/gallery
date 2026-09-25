<?php

namespace Tests\Feature\Media;

use App\Services\Media\ImageVariantService;
use App\Services\Media\PerceptualHashService;
use App\Services\Media\UpravaFotky;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pixelová bomba se nedekóduje.
 *
 * PNG o pár bajtech s hlavičkou 30 000 × 30 000 px si při dekódování řekne
 * o ~3,6 GB. PHP skončí fatální chybou paměti, kterou `catch` nechytí —
 * worker spadne a fronta ho spustí znovu, na každém pokusu. Rozměry z
 * hlavičky (`getimagesize`) se proto kontrolují před dekódováním.
 */
class PixelovaBombaTest extends TestCase
{
    use RefreshDatabase;
    use VytvariMedia;

    private string $bomba;

    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('Test dekodéru potřebuje GD.');
        }
        Storage::fake('public');
        $this->zalozProstor();
        $this->bomba = tempnam(sys_get_temp_dir(), 'bmb');
        file_put_contents($this->bomba, $this->pixelovaBomba());
    }

    protected function tearDown(): void
    {
        @unlink($this->bomba);
        parent::tearDown();
    }

    private function ocekavejVarovani(): void
    {
        Log::shouldReceive('warning')->atLeast()->once()
            ->withArgs(fn ($zprava) => str_contains((string) $zprava, 'příliš velký'));
        Log::shouldReceive('error', 'info', 'debug', 'notice')->zeroOrMoreTimes();
    }

    public function test_hlavicka_bomby_rika_30000_px(): void
    {
        $this->assertSame([30000, 30000], array_slice(getimagesize($this->bomba), 0, 2));
    }

    public function test_varianty_bombu_nedekoduji(): void
    {
        $this->ocekavejVarovani();
        $fotka = $this->media(['extension' => 'png']);

        app(ImageVariantService::class)->generateAll($fotka, $this->bomba);

        $this->assertFalse($fotka->variants()->exists());
    }

    public function test_otisk_bombu_nedekoduje(): void
    {
        $this->ocekavejVarovani();

        $this->assertNull(app(PerceptualHashService::class)->calculateDHash($this->bomba));
        $this->assertNull(app(PerceptualHashService::class)->calculateAHash($this->bomba));
    }

    public function test_uprava_bombu_preskoci_a_vezme_velky_nahled(): void
    {
        $this->ocekavejVarovani();
        $fotka = $this->media(['extension' => 'png']);
        $original = "media/{$fotka->uuid}/original.png";
        Storage::disk('public')->put($original, (string) file_get_contents($this->bomba));
        $this->varianta($fotka, 'original', $original);
        $velky = "media/{$fotka->uuid}/large.jpg";
        Storage::disk('public')->put($velky, $this->jpeg(400, 300));
        $this->varianta($fotka, 'large', $velky);

        $this->assertTrue(app(UpravaFotky::class)->uloz($fotka, 90, false, $this->adri));
        $this->assertSame(300, (int) $fotka->variants()->where('type', 'edited_preview')->value('width'));
    }
}
