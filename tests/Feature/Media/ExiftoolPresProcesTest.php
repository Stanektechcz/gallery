<?php

namespace Tests\Feature\Media;

use App\Console\Commands\GenerateMissingThumbnailsCommand;
use App\Services\ExifExtractorService;
use App\Services\Media\ExifExtractionService;
use App\Services\Media\MediaFormatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeout;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

/**
 * exiftool přes `proc_open`, polem argumentů.
 *
 * `shell_exec` je na serveru vypnutý: EXIF z nahrávek (datum, GPS,
 * fotoaparát) tak tiše chyběl. S polem argumentů navíc odpadá
 * `escapeshellarg` — hodnota zapsaná jako `'-Subject+='.escapeshellarg($tag)`
 * by v poli skončila v XMP i s uvozovkami.
 */
class ExiftoolPresProcesTest extends TestCase
{
    use RefreshDatabase;
    use VytvariMedia;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gallery.exiftool_path' => PHP_BINARY]);
        Process::preventStrayProcesses();
    }

    private function vystup(array $data): string
    {
        return json_encode([array_merge(['SourceFile' => '/data/foto.jpg'], $data)]);
    }

    public function test_exif_se_cte_z_json_vystupu(): void
    {
        Process::fake(fn () => Process::result($this->vystup([
            'DateTimeOriginal' => '2026:07:02 00:30:00',
            'GPSLatitude' => 50.0755, 'GPSLongitude' => 14.4378,
            'Make' => 'Apple', 'Model' => 'iPhone 15', 'ISO' => 64,
        ])));

        $exif = (new ExifExtractionService)->extract('/data/fotka s mezerou.jpg');

        $this->assertSame('2026-07-02 00:30:00', $exif['taken_at']->format('Y-m-d H:i:s'));
        $this->assertSame([50.0755, 14.4378], [$exif['latitude'], $exif['longitude']]);
        $this->assertSame('iPhone 15', $exif['camera_model']);
        Process::assertRan(fn (PendingProcess $p) => $p->command === [PHP_BINARY, '-json', '-n', '/data/fotka s mezerou.jpg']
            && $p->timeout === 30);
    }

    public function test_selhani_exiftoolu_vrati_prazdne_pole(): void
    {
        Process::fake(fn () => Process::result(errorOutput: 'Error: File not found', exitCode: 1));

        $this->assertSame([], (new ExifExtractionService)->extract('/data/chybi.jpg'));
    }

    public function test_vyprseni_exiftoolu_vrati_prazdne_pole(): void
    {
        Process::fake(function () {
            $proces = new SymfonyProcess(['exiftool']);

            throw new ProcessTimedOutException(new SymfonyTimeout($proces, SymfonyTimeout::TYPE_GENERAL), new ProcessResult($proces));
        });

        $this->assertSame([], (new ExifExtractionService)->extract('/data/zaseknuty.mov'));
    }

    /** Štítek se do XMP zapíše tak, jak je — bez uvozovek z `escapeshellarg`. */
    public function test_sidecar_zapise_stitek_bez_uvozovek(): void
    {
        Process::fake(fn () => Process::result());

        $ok = (new ExifExtractionService)->writeXmpSidecar('/data/foto.jpg', [
            'description' => "Adri's výlet",
            'rating' => 4,
            'tags' => ['Morské oko', 'léto 2026'],
        ]);

        $this->assertTrue($ok);
        Process::assertRan(fn (PendingProcess $p) => $p->command === [
            PHP_BINARY,
            "-Description=Adri's výlet",
            '-Rating=4',
            '-Subject+=Morské oko',
            '-Subject+=léto 2026',
            '-o', '/data/foto.jpg.xmp',
            '/data/foto.jpg',
        ] && $p->timeout === 30);
    }

    public function test_sidecar_ktery_selze_vrati_false(): void
    {
        Process::fake(fn () => Process::result(errorOutput: 'Error: /data/foto.jpg.xmp already exists', exitCode: 1));

        $this->assertFalse((new ExifExtractionService)->writeXmpSidecar('/data/foto.jpg', ['rating' => 3]));
    }

    /** Náhled z RAW používá nastavenou cestu, ne `exiftool` z PATH. */
    public function test_nahled_z_raw_pouziva_nastaveny_exiftool(): void
    {
        $jpeg = "\xFF\xD8".str_repeat('x', 4000);
        Process::fake(fn (PendingProcess $p) => in_array('-JpgFromRaw', $p->command, true)
            ? Process::result(exitCode: 1)
            : Process::result($jpeg));

        $nahled = (new MediaFormatService)->extractRawPreview('/data/IMG_0001.CR3');

        $this->assertNotNull($nahled);
        // Podvržený výsledek přidá na konec výstupu nový řádek; skutečný exiftool ne.
        $this->assertSame($jpeg, rtrim((string) file_get_contents($nahled), "\n"));
        @unlink($nahled);
        Process::assertRan(fn (PendingProcess $p) => $p->command === [PHP_BINARY, '-PreviewImage', '-b', '/data/IMG_0001.CR3']
            && $p->timeout === 30);
    }

    public function test_bez_nahledu_v_raw_vrati_null(): void
    {
        Process::fake(fn () => Process::result(''));

        $this->assertNull((new MediaFormatService)->extractRawPreview('/data/IMG_0001.CR3'));
    }

    /** `ExifExtractorService::getRawExif` měl rozbité popisovače pro `proc_open` a vracel vždy `[]`. */
    public function test_surovy_exif_se_opravdu_precte(): void
    {
        $soubor = tempnam(sys_get_temp_dir(), 'exif_');
        Process::fake(fn () => Process::result($this->vystup(['ProjectionType' => 'equirectangular'])));

        $raw = (new ExifExtractorService)->getRawExif($soubor);
        @unlink($soubor);

        $this->assertSame('equirectangular', $raw['ProjectionType'] ?? null);
        Process::assertRan(fn (PendingProcess $p) => $p->command === [PHP_BINARY, '-json', '-n', '-charset', 'UTF8', '-struct', $soubor]
            && $p->timeout === 30);
    }

    public function test_extractor_cte_exif_pres_proces(): void
    {
        $soubor = tempnam(sys_get_temp_dir(), 'exif_');
        Process::fake(fn () => Process::result($this->vystup(['GPSLatitude' => 49.19, 'GPSLongitude' => 16.61])));

        $exif = (new ExifExtractorService)->extract($soubor);
        @unlink($soubor);

        $this->assertEqualsWithDelta(49.19, $exif['latitude'], 0.0001);
        $this->assertEqualsWithDelta(16.61, $exif['longitude'], 0.0001);
    }

    /** `gallery:thumbnails` čte EXIF z exiftoolu přes proces. */
    public function test_doplnovani_nahledu_cte_exif_pres_proces(): void
    {
        if (extension_loaded('imagick')) {
            $this->markTestSkipped('S Imagickem se příkaz na exiftool vůbec nedostane.');
        }
        $this->zalozProstor();
        $fotka = $this->media();
        $soubor = tempnam(sys_get_temp_dir(), 'exif_');
        file_put_contents($soubor, 'neni to obrazek');
        Process::fake(fn () => Process::result($this->vystup(['Make' => 'Canon', 'GPSLatitude' => 48.2, 'GPSLongitude' => 16.37])));

        (new \ReflectionMethod(GenerateMissingThumbnailsCommand::class, 'extractExif'))
            ->invoke(app(GenerateMissingThumbnailsCommand::class), $fotka, $soubor);
        @unlink($soubor);

        $fotka->refresh();
        $this->assertSame('Canon', $fotka->camera_make);
        $this->assertEqualsWithDelta(48.2, (float) $fotka->latitude, 0.0001);
        Process::assertRan(fn (PendingProcess $p) => $p->command === [PHP_BINARY, '-json', '-n', $soubor] && $p->timeout === 30);
    }
}
