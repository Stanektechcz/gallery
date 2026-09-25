<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `gallery:clean-temp` hledá dočasné soubory tam, kam se opravdu zapisují.
 *
 * Části nahrávek a soubory ze sdílení se ukládají na disk `local`, jehož kořen
 * je `storage/app/private` — úklid ale procházel `storage/app/upload_chunks`
 * a `storage/app/share_target`, kde nikdy nic nebylo. Nedokončená nahrávání
 * z prototypu (`upload_chunks/galerie/…`) tak na disku ležela napořád.
 *
 * Kořen úložiště se pro test přesměruje do dočasné složky, aby se úklid
 * nedotkl skutečného `storage/`.
 */
class UklidDocasnychSouboruTest extends TestCase
{
    use RefreshDatabase;

    private string $koren;

    protected function setUp(): void
    {
        parent::setUp();

        $this->koren = sys_get_temp_dir().DIRECTORY_SEPARATOR.'uklid-'.Str::random(10);
        File::ensureDirectoryExists($this->koren);
        $this->app->useStoragePath($this->koren);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->koren);

        parent::tearDown();
    }

    public function test_stare_casti_nahravek_se_smazou_a_cerstve_zustanou(): void
    {
        $staraZPrototypu = 'upload_chunks/galerie/7-up-stara/000000';
        $cerstvaZPrototypu = 'upload_chunks/galerie/7-up-cerstva/000000';
        $staraRelace = 'upload_chunks/'.Str::uuid().'/chunk_0';
        $cerstvaRelace = 'upload_chunks/'.Str::uuid().'/chunk_0';

        $this->ulozNaDisk($staraZPrototypu, now()->subDays(10));
        $this->ulozNaDisk($cerstvaZPrototypu, now());
        $this->ulozNaDisk($staraRelace, now()->subDays(10));
        $this->ulozNaDisk($cerstvaRelace, now());

        $this->artisan('gallery:clean-temp')->assertExitCode(0);

        $disk = Storage::disk('local');
        $this->assertFalse($disk->directoryExists(dirname($staraZPrototypu)), 'Stará nahrávka z prototypu má zmizet i se složkou.');
        $disk->assertExists($cerstvaZPrototypu);
        $this->assertFalse($disk->directoryExists(dirname($staraRelace)));
        $disk->assertExists($cerstvaRelace);
    }

    public function test_stare_soubory_ze_sdileni_se_smazou_a_cerstve_zustanou(): void
    {
        $stary = 'share_target/'.Str::random(40).'.jpg';
        $cerstvy = 'share_target/'.Str::random(40).'.jpg';
        $this->ulozNaDisk($stary, now()->subDays(2));
        $this->ulozNaDisk($cerstvy, now()->subHours(2));

        $this->artisan('gallery:clean-temp')->assertExitCode(0);

        Storage::disk('local')->assertMissing($stary);
        Storage::disk('local')->assertExists($cerstvy);
    }

    /**
     * Exporty a složené nahrávky se zapisují přímo pod `storage/app` (ne přes disk).
     *
     * Tam úklid hledal správně; složku po složené nahrávce ale nechával prázdnou.
     */
    public function test_stare_exporty_a_slozene_nahravky_se_smazou_a_cerstve_zustanou(): void
    {
        $staryExport = storage_path('app/exports/stary.zip');
        $cerstvyExport = storage_path('app/exports/cerstvy.zip');
        $staraSlozena = storage_path('app/uploads/'.Str::uuid().'/source.jpg');
        $cerstvaSlozena = storage_path('app/uploads/'.Str::uuid().'/source.jpg');

        $this->ulozDoStorage($staryExport, now()->subDays(10));
        $this->ulozDoStorage($cerstvyExport, now());
        $this->ulozDoStorage($staraSlozena, now()->subDays(10));
        $this->ulozDoStorage($cerstvaSlozena, now());

        $this->artisan('gallery:clean-temp')->assertExitCode(0);

        $this->assertFileDoesNotExist($staryExport);
        $this->assertFileExists($cerstvyExport);
        $this->assertDirectoryDoesNotExist(dirname($staraSlozena));
        $this->assertFileExists($cerstvaSlozena);
    }

    // ——— pomocné ———

    private function ulozNaDisk(string $cesta, \DateTimeInterface $kdy): void
    {
        Storage::disk('local')->put($cesta, 'x');
        // `put()` nechává čas skutečných hodin, ne posunuté `now()` z testu.
        touch(Storage::disk('local')->path($cesta), $kdy->getTimestamp());
    }

    private function ulozDoStorage(string $cesta, \DateTimeInterface $kdy): void
    {
        File::ensureDirectoryExists(dirname($cesta));
        File::put($cesta, 'x');
        touch($cesta, $kdy->getTimestamp());
    }
}
