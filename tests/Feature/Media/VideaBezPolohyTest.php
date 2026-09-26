<?php

namespace Tests\Feature\Media;

use App\Models\MediaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `gallery:videa-bez-polohy` — starší kopie videí k přehrávání bez polohy.
 *
 * Kopie vzniklé před `-map_metadata -1` nesou souřadnice z telefonu. Příkaz
 * je bez `--provest` jen spočítá; s ním je přebalí bez překódování (`-c copy`)
 * a vymění soubor přejmenováním. Originály nechává být.
 *
 * ffprobe i ffmpeg jsou podvržené: sonda pozná polohu podle obsahu souboru
 * („S-POLOHOU"), přebalení zapíše do cílového souboru kopii bez ní. Díky
 * tomu jde ověřit i to, že druhý běh už nic nedělá.
 */
class VideaBezPolohyTest extends TestCase
{
    use RefreshDatabase;
    use VytvariMedia;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gallery.ffmpeg_path' => PHP_BINARY, 'gallery.ffprobe_path' => PHP_BINARY]);
        Storage::fake('public');
        Process::preventStrayProcesses();
        $this->zalozProstor();
    }

    private function falesnyFfmpeg(bool $prebaleniSelze = false): void
    {
        Process::fake(function (PendingProcess $p) use ($prebaleniSelze) {
            $args = $p->command;
            if (in_array('-show_format', $args, true)) {
                $obsah = (string) @file_get_contents(end($args));
                $tagy = str_contains($obsah, 'S-POLOHOU')
                    ? ['location' => '+50.0755+014.4378/', 'com.apple.quicktime.location.ISO6709' => '+50.0755+014.4378+000.000/']
                    : ['encoder' => 'Lavf61'];

                return Process::result(json_encode(['format' => ['tags' => $tagy], 'streams' => [['codec_type' => 'video']]]));
            }
            if ($prebaleniSelze) {
                file_put_contents(end($args), 'NAPUL');

                return Process::result(errorOutput: 'Invalid data found when processing input', exitCode: 1);
            }
            file_put_contents(end($args), 'KOPIE-BEZ-METADAT');

            return Process::result();
        });
    }

    /** @return array{0: MediaItem, 1: string} video a cesta ke kopii */
    private function video(string $obsahKopie, array $atributy = []): array
    {
        $video = $this->media(array_merge(['media_type' => 'video', 'extension' => 'mov', 'mime_type' => 'video/quicktime'], $atributy));
        $original = "media/{$video->uuid}/original.mov";
        $kopie = "media/{$video->uuid}/video_compat.mp4";
        Storage::disk('public')->put($original, 'ORIGINAL-S-POLOHOU');
        $this->varianta($video, 'original', $original);
        Storage::disk('public')->put($kopie, $obsahKopie);
        $this->varianta($video, 'video_compat', $kopie);

        return [$video, $kopie];
    }

    public function test_bez_provest_jen_spocita(): void
    {
        $this->falesnyFfmpeg();
        [, $sPolohou] = $this->video('KOPIE-S-POLOHOU');
        [, $bezPolohy] = $this->video('KOPIE-CISTA');

        $this->artisan('gallery:videa-bez-polohy')
            ->expectsOutputToContain('Kopií s polohou: 1 z 2')
            ->assertSuccessful();

        $this->assertSame('KOPIE-S-POLOHOU', Storage::disk('public')->get($sPolohou));
        $this->assertSame('KOPIE-CISTA', Storage::disk('public')->get($bezPolohy));
        Process::assertDidntRun(fn (PendingProcess $p) => in_array('copy', $p->command, true));
    }

    public function test_s_provest_prebali_kopii_a_original_necha(): void
    {
        $this->falesnyFfmpeg();
        [$video, $kopie] = $this->video('KOPIE-S-POLOHOU');
        $cesta = Storage::disk('public')->path($kopie);

        $this->artisan('gallery:videa-bez-polohy', ['--provest' => true])
            ->expectsOutputToContain('Poloha odstraněna: 1')
            ->assertSuccessful();

        $this->assertSame('KOPIE-BEZ-METADAT', Storage::disk('public')->get($kopie));
        $this->assertSame('ORIGINAL-S-POLOHOU', Storage::disk('public')->get("media/{$video->uuid}/original.mov"));
        $this->assertSame(strlen('KOPIE-BEZ-METADAT'), (int) DB::table('media_variants')
            ->where('media_item_id', $video->id)->where('type', 'video_compat')->value('size_bytes'));
        $this->assertSame(['original.mov', 'video_compat.mp4'], $this->souboryVAdresari(dirname($cesta)),
            'Po výměně nesmí v adresáři zůstat dočasný soubor.');

        Process::assertRan(function (PendingProcess $p) use ($cesta) {
            $args = $p->command;
            $i = array_search('-i', $args, true);

            return $i !== false && $args[$i + 1] === $cesta
                && array_slice($args, $i + 2, 11) === ['-map', '0', '-map_metadata', '-1', '-map_chapters', '-1', '-c', 'copy', '-movflags', '+faststart', end($args)]
                && dirname(end($args)) === dirname($cesta)
                && end($args) !== $cesta;
        });

        // Druhý běh už nemá co dělat.
        $this->artisan('gallery:videa-bez-polohy', ['--provest' => true])
            ->expectsOutputToContain('Kopií s polohou: 0 z 1')
            ->assertSuccessful();
    }

    public function test_chybejici_soubor_a_smazane_video_preskoci(): void
    {
        $this->falesnyFfmpeg();
        [, $kopie] = $this->video('KOPIE-S-POLOHOU');
        Storage::disk('public')->delete($kopie);
        [$smazane, $kopieSmazaneho] = $this->video('KOPIE-S-POLOHOU');
        $smazane->delete();

        $this->artisan('gallery:videa-bez-polohy', ['--provest' => true])
            ->expectsOutputToContain('Chybí soubor: 1')
            ->assertSuccessful();

        $this->assertSame('KOPIE-S-POLOHOU', Storage::disk('public')->get($kopieSmazaneho));
    }

    public function test_selhani_prebaleni_nahlasi_a_soubor_necha(): void
    {
        $this->falesnyFfmpeg(prebaleniSelze: true);
        [$video, $kopie] = $this->video('KOPIE-S-POLOHOU');
        $cesta = Storage::disk('public')->path($kopie);

        $this->artisan('gallery:videa-bez-polohy', ['--provest' => true])
            ->expectsOutputToContain("media #{$video->id}")
            ->assertFailed();

        $this->assertSame('KOPIE-S-POLOHOU', Storage::disk('public')->get($kopie));
        $this->assertSame(['original.mov', 'video_compat.mp4'], $this->souboryVAdresari(dirname($cesta)));
    }

    /** @return list<string> */
    private function souboryVAdresari(string $adresar): array
    {
        $soubory = array_values(array_diff(scandir($adresar) ?: [], ['.', '..']));
        sort($soubory);

        return $soubory;
    }
}
