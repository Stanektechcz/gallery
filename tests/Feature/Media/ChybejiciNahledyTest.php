<?php

namespace Tests\Feature\Media;

use App\Models\StorageConnection;
use App\Services\Storage\GoogleDriveStorageProvider;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `gallery:thumbnails` — nepřijít o funkční náhled a neplnit disk.
 */
class ChybejiciNahledyTest extends TestCase
{
    use RefreshDatabase;
    use VytvariMedia;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->zalozProstor();
    }

    /**
     * `--force` mazal řádky náhledů dřív, než zjistil, jestli má z čeho
     * udělat nové. Fotka, jejíž originál je už jen na Drive, tak přišla
     * o náhled, který fungoval.
     */
    public function test_force_bez_zdroje_nesmaze_funkcni_nahled(): void
    {
        $fotka = $this->media(['drive_file_id' => 'drive-123']);
        $nahled = "media/{$fotka->uuid}/thumbnail.webp";
        Storage::disk('public')->put($nahled, 'nahled');
        $this->varianta($fotka, 'thumbnail', $nahled);

        $this->artisan('gallery:thumbnails', ['--force' => true]);

        $this->assertTrue($fotka->variants()->where('type', 'thumbnail')->exists(), 'Funkční náhled musí zůstat.');
        Storage::disk('public')->assertExists($nahled);
    }

    /** Nefunkční řádek (soubor chybí) bez zdroje pryč jde — ten dělal jen 404. */
    public function test_bez_zdroje_zmizi_jen_rozbity_nahled(): void
    {
        $fotka = $this->media();
        $this->varianta($fotka, 'thumbnail', "media/{$fotka->uuid}/chybi.webp");

        $this->artisan('gallery:thumbnails');

        $this->assertFalse($fotka->variants()->where('type', 'thumbnail')->exists());
    }

    /**
     * Stažení z Drive nechávalo na každý originál dva dočasné soubory:
     * prázdný z `tempnam()` a ten s příponou.
     */
    public function test_obnova_z_drive_uklidi_docasne_soubory(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('Náhled z JPEGu potřebuje GD.');
        }

        StorageConnection::create([
            'provider' => 'google_drive',
            'gallery_space_id' => $this->prostor->id,
            'owner_user_id' => $this->adri->id,
            'connection_status' => 'healthy',
        ]);
        $fotka = $this->media(['drive_file_id' => 'drive-123']);

        $jpeg = $this->jpeg(40, 30);
        $drive = \Mockery::mock(GoogleDriveStorageProvider::class);
        $drive->shouldReceive('download')->with('drive-123')->andReturn(Utils::streamFor($jpeg));
        $this->app->bind(GoogleDriveStorageProvider::class, fn () => $drive);

        $pred = $this->docasneSoubory();
        $this->artisan('gallery:thumbnails', ['--recover' => true])->assertExitCode(0);

        $this->assertSame([], array_values(array_diff($this->docasneSoubory(), $pred)), 'Dočasné soubory po obnově musí zmizet.');
        Storage::disk('public')->assertExists("media/{$fotka->uuid}/original.jpg");
        $this->assertTrue($fotka->variants()->where('type', 'thumbnail')->exists());
    }

    private function docasneSoubory(): array
    {
        $tmp = rtrim(sys_get_temp_dir(), '\\/');

        return array_merge(
            glob($tmp.'/gallery_recover_*') ?: [],
            glob($tmp.'/gal*.tmp*') ?: [],
            glob(storage_path('app/temp').'/recover_*') ?: [],
        );
    }
}
