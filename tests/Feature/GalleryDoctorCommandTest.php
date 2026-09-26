<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * `gallery:doctor` — binárky na disku, které se ještě musí umět spustit.
 *
 * `is_executable()` pozná jen práva souboru, ne to, jestli binárka doopravdy
 * poběží: chybějící sdílená knihovna, špatná architektura nebo `disable_functions`
 * blokující `proc_open` (kterým `Process` spouští cokoli) — ve všech třech
 * případech dřív doktor hlásil ffmpeg/exiftool jako v pořádku a první, kdo se
 * to dozvěděl, byl žadatel o zpracované video.
 */
class GalleryDoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `proc_open` se hlásí — bez něj Process nikdy nic nespustí. `exec`
     * a `shell_exec` ne: aplikace je nepotřebuje a na produkci jsou vypnuté,
     * varování by tam strašilo při každém běhu.
     */
    public function test_hlasi_dostupnost_proc_open_a_pribuznych(): void
    {
        $this->artisan('gallery:doctor')
            ->expectsOutputToContain('proc_open povoleno')
            ->doesntExpectOutputToContain('shell_exec')
            ->run();
    }

    /** Binárka na disku, která se opravdu spustí, je PASS — ne jen „soubor existuje". */
    public function test_binarka_ktera_se_spusti_je_v_poradku(): void
    {
        config([
            'gallery.ffmpeg_path' => PHP_BINARY,
            'gallery.ffprobe_path' => PHP_BINARY,
            'gallery.exiftool_path' => PHP_BINARY,
        ]);

        Process::fake(fn (PendingProcess $p) => Process::result('ok'));

        $this->artisan('gallery:doctor')
            ->expectsOutputToContain('ffmpeg se opravdu spustí')
            ->expectsOutputToContain('ffprobe se opravdu spustí')
            ->expectsOutputToContain('exiftool se opravdu spustí')
            ->run();

        Process::assertRan(fn (PendingProcess $p) => $p->command === [PHP_BINARY, '-version']);
    }

    /**
     * Soubor existuje a je spustitelný, ale samotné spuštění selže (chybějící
     * knihovna, špatná architektura) — to je přesně případ, který `is_executable()`
     * sám o sobě nikdy nepozná.
     */
    public function test_binarka_ktera_selze_pri_spusteni_je_varovani(): void
    {
        config(['gallery.ffmpeg_path' => PHP_BINARY]);

        Process::fake(fn (PendingProcess $p) => Process::result(exitCode: 127, errorOutput: 'error while loading shared libraries'));

        $this->artisan('gallery:doctor')
            ->expectsOutputToContain('ffmpeg se opravdu spustí')
            ->run();
    }

    /** Chybějící binárka se nezkouší vůbec spustit — jen se to řekne. */
    public function test_chybejici_binarka_se_nezkousi_spustit(): void
    {
        config(['gallery.ffmpeg_path' => '/tmp/zadne-takove-ffmpeg-'.uniqid()]);

        Process::fake();

        $this->artisan('gallery:doctor')->run();

        Process::assertNothingRan();
    }
}
