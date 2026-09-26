<?php

namespace Tests\Feature\Media;

use App\Jobs\Media\GenerateVideoCompatibilityVariantJob;
use App\Jobs\Media\GenerateVideoPosterJob;
use App\Services\Media\VideoProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeout;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

/**
 * ffmpeg a ffprobe přes `proc_open`, ne přes shell.
 *
 * Na serveru jsou `shell_exec` i `exec` vypnuté — video tak nemělo technické
 * údaje, náhled ani kopii pro prohlížeč, a v logu o tom nebylo nic. Testy
 * hlídají, že se program volá polem argumentů (bez uvozovek z
 * `escapeshellarg` a bez `2>/dev/null`) a s vlastním stropem času.
 *
 * `isAvailable()` ověřuje spustitelnost nastavených cest; na Windows i Linuxu
 * projde PHP_BINARY, samotné spuštění pak obstará `Process::fake()`.
 */
class VideoPresProcesTest extends TestCase
{
    use RefreshDatabase;
    use VytvariMedia;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gallery.ffmpeg_path' => PHP_BINARY,
            'gallery.ffprobe_path' => PHP_BINARY,
            'gallery.video_transcode_timeout' => 1200,
        ]);
        Process::preventStrayProcesses();
    }

    private const KODERY = <<<'TXT'
Encoders:
 V..... = Video
 ------
 V....D libx264              libx264 H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10 (codec h264)
 V....D h264_nvenc           NVIDIA NVENC H.264 encoder (codec h264)
 V....D h264_vaapi           H.264/AVC (VAAPI) (codec h264)
 A....D aac                  AAC (Advanced Audio Coding)
TXT;

    /** Distribuční ffmpeg vypisuje vaapi v `-encoders`, i když stroj nemá žádné VAAPI zařízení. */
    private const KODERY_JEN_VAAPI = <<<'TXT'
Encoders:
 V..... = Video
 ------
 V....D libx264              libx264 H.264 / AVC / MPEG-4 AVC / MPEG-4 part 10 (codec h264)
 V....D h264_vaapi           H.264/AVC (VAAPI) (codec h264)
TXT;

    public function test_sonda_bezi_polem_argumentu_s_kratkym_stropem(): void
    {
        Process::fake(fn () => Process::result(json_encode([
            'format' => ['duration' => '12.5'],
            'streams' => [['codec_type' => 'video', 'width' => 1920, 'height' => 1080, 'codec_name' => 'hevc']],
        ])));

        $meta = (new VideoProcessingService)->extractMetadata('C:/videa/výlet s mezerou.mov');

        $this->assertSame(12500, $meta['duration_ms']);
        $this->assertSame('hevc', $meta['video_codec']);
        Process::assertRan(fn (PendingProcess $p) => $p->command === [
            PHP_BINARY, '-v', 'error', '-print_format', 'json', '-show_streams', '-show_format', 'C:/videa/výlet s mezerou.mov',
        ] && $p->timeout === 60);
    }

    public function test_sonda_ktera_selze_vrati_prazdne_pole(): void
    {
        Process::fake(fn () => Process::result(errorOutput: 'moov atom not found', exitCode: 1));

        $this->assertSame([], (new VideoProcessingService)->extractMetadata('/tmp/rozbite.mp4'));
    }

    public function test_nahled_ma_dve_minuty_a_zadny_shell(): void
    {
        Storage::fake('public');
        $this->zalozProstor();
        $video = $this->media(['media_type' => 'video', 'extension' => 'mp4', 'mime_type' => 'video/mp4']);

        Process::fake(function (PendingProcess $p) {
            file_put_contents(end($p->command), 'JPEG');

            return Process::result();
        });

        $plakat = (new VideoProcessingService)->generatePoster($video, '/data/video.mp4');

        $this->assertNotNull($plakat);
        Storage::disk('public')->assertExists("media/{$video->uuid}/video_poster.jpg");
        Process::assertRan(function (PendingProcess $p) {
            $args = $p->command;

            return $p->timeout === 120
                && $args[0] === PHP_BINARY
                && array_slice($args, array_search('-ss', $args, true), 4) === ['-ss', '2', '-i', '/data/video.mp4']
                && ! $this->maShell($args);
        });
    }

    /**
     * Kodér se čte jednou za instanci a ověří skutečným zkušebním snímkem —
     * jen výpis v `-encoders` nic o funkčním hardwaru neříká.
     */
    public function test_kodery_se_ctou_jednou_a_z_vystupu_v_php(): void
    {
        Process::fake(function (PendingProcess $p) {
            if (in_array('-encoders', $p->command, true)) {
                return Process::result(self::KODERY);
            }

            // Zkušební snímek na h264_nvenc uspěje.
            return Process::result();
        });
        $videa = new VideoProcessingService;

        $this->assertSame('h264_nvenc', $videa->selectVideoEncoder());
        $this->assertSame('h264_nvenc', $videa->selectVideoEncoder());

        Process::assertRanTimes(fn (PendingProcess $p) => $p->command === [PHP_BINARY, '-hide_banner', '-encoders'], 1);
        Process::assertRanTimes(fn (PendingProcess $p) => in_array('lavfi', $p->command, true), 1);
    }

    public function test_bez_hardwarovych_koderu_zbude_libx264(): void
    {
        Process::fake(fn () => Process::result(" V....D libx264   libx264 H.264\n V....D h264_qsvx  něco jiného\n"));

        $this->assertSame('libx264', (new VideoProcessingService)->selectVideoEncoder());
    }

    public function test_seznam_koderu_ktery_selze_znamena_libx264(): void
    {
        Process::fake(fn () => Process::result(exitCode: 1));

        $this->assertSame('libx264', (new VideoProcessingService)->selectVideoEncoder());
    }

    /**
     * `h264_vaapi` je v distribučním ffmpeg v `-encoders` vždycky, i bez
     * jediného VAAPI zařízení — `prikazKopie()` navíc pro něj nesestavuje
     * `-vaapi_device`/`hwupload`, takže by ho automatika vybrala a nechala
     * spadnout na libx264 při každém převodu. Auto volba ho proto vůbec
     * nezkouší.
     */
    public function test_vaapi_bez_zarizeni_se_nevybira(): void
    {
        Process::fake(fn () => Process::result(self::KODERY_JEN_VAAPI));

        $this->assertSame('libx264', (new VideoProcessingService)->selectVideoEncoder());

        Process::assertRanTimes(fn (PendingProcess $p) => $p->command === [PHP_BINARY, '-hide_banner', '-encoders'], 1);
        Process::assertRanTimes(fn (PendingProcess $p) => in_array('lavfi', $p->command, true), 0);
    }

    /**
     * Verdikt (i úspěšný) se pamatuje v cache podle cesty k ffmpeg — druhá
     * instance služby (nová úloha ve frontě) nemá znovu spouštět zkušební
     * snímek, který už jednou uspěl.
     */
    public function test_hardware_se_overi_zkusebnim_snimkem_a_verdikt_se_pamatuje(): void
    {
        Process::fake(function (PendingProcess $p) {
            if (in_array('-encoders', $p->command, true)) {
                return Process::result(self::KODERY);
            }

            return Process::result();
        });

        $prvni = (new VideoProcessingService)->selectVideoEncoder();
        $druhy = (new VideoProcessingService)->selectVideoEncoder();

        $this->assertSame('h264_nvenc', $prvni);
        $this->assertSame('h264_nvenc', $druhy);

        Process::assertRanTimes(fn (PendingProcess $p) => $p->command === [PHP_BINARY, '-hide_banner', '-encoders'], 1);
        Process::assertRanTimes(fn (PendingProcess $p) => in_array('lavfi', $p->command, true), 1);
    }

    /** Neúspěšný verdikt („žádný hardware nefunguje") se pamatuje stejně jako úspěšný. */
    public function test_neuspesna_zkouska_se_pamatuje_taky(): void
    {
        Process::fake(function (PendingProcess $p) {
            if (in_array('-encoders', $p->command, true)) {
                return Process::result(self::KODERY);
            }

            return Process::result(exitCode: 1, errorOutput: 'Cannot load libnvidia-encode.so.1');
        });

        $prvni = (new VideoProcessingService)->selectVideoEncoder();
        $druhy = (new VideoProcessingService)->selectVideoEncoder();

        $this->assertSame('libx264', $prvni);
        $this->assertSame('libx264', $druhy);

        Process::assertRanTimes(fn (PendingProcess $p) => $p->command === [PHP_BINARY, '-hide_banner', '-encoders'], 1);
        Process::assertRanTimes(fn (PendingProcess $p) => in_array('lavfi', $p->command, true), 1);
    }

    /** Pevně nastavený kodér se použije napřímo — žádné zjišťování se nespouští. */
    public function test_nastaveny_koder_nic_nespousti(): void
    {
        config(['gallery.video_encoder' => 'h264_pevne_nastaveny']);
        Process::fake();

        $this->assertSame('h264_pevne_nastaveny', (new VideoProcessingService)->selectVideoEncoder());

        Process::assertNothingRan();
    }

    /**
     * Hardwarový kodér projde zkušebním snímkem, ale skutečný převod přesto
     * selže — hardwarové kodéry občas odmítnou konkrétní zdrojový formát,
     * i když obecně fungují. Druhý pokus softwarově, oba bez metadat zdroje.
     *
     * Druhý pokus dostane jen zbytek společného stropu — dohromady se musí
     * vejít pod `$timeout` úlohy, jinak ji worker zabije uprostřed zápisu.
     */
    public function test_po_selhani_hardwaru_prijde_libx264_bez_metadat(): void
    {
        Storage::fake('public');
        $this->zalozProstor();
        $video = $this->media(['media_type' => 'video', 'extension' => 'mov', 'mime_type' => 'video/quicktime']);

        $prevody = [];
        Process::fake(function (PendingProcess $p) use (&$prevody) {
            if (in_array('-encoders', $p->command, true)) {
                return Process::result(self::KODERY);
            }
            if (in_array('lavfi', $p->command, true)) {
                // Zkušební snímek na h264_nvenc uspěje — hardware obecně funguje.
                return Process::result();
            }
            $prevody[] = clone $p;
            if (in_array('libx264', $p->command, true)) {
                file_put_contents(end($p->command), 'MP4');

                return Process::result();
            }

            // Skutečný převod zdrojového souboru přesto selže.
            return Process::result(errorOutput: 'Unsupported input pixel format', exitCode: 1);
        });

        $kopie = (new VideoProcessingService)->generateCompatibilityVariant($video, '/data/IMG_0001.MOV');

        $this->assertNotNull($kopie);
        Storage::disk('public')->assertExists("media/{$video->uuid}/video_compat.mp4");

        $this->assertCount(2, $prevody);
        [$hardware, $software] = $prevody;

        $this->assertSame('h264_nvenc', $this->hodnota($hardware->command, '-c:v'));
        $this->assertSame('libx264', $this->hodnota($software->command, '-c:v'));
        foreach ([$hardware, $software] as $prevod) {
            $this->assertSame('-1', $this->hodnota($prevod->command, '-map_metadata'));
            $this->assertSame('-1', $this->hodnota($prevod->command, '-map_chapters'));
            $this->assertSame('/data/IMG_0001.MOV', $this->hodnota($prevod->command, '-i'));
            $this->assertStringStartsWith("scale=w='min(1920,iw)'", $this->hodnota($prevod->command, '-vf'));
            $this->assertFalse($this->maShell($prevod->command));
        }
        $this->assertSame(1200, $hardware->timeout);
        $this->assertGreaterThan(1100, $software->timeout);
        $this->assertLessThanOrEqual(1200, $software->timeout);
        $this->assertFileDoesNotExist(storage_path("app/temp/compat_{$video->uuid}.mp4"));
    }

    /** Vypršení je selhání jako každé jiné: `null`, žádný rozepsaný soubor, žádná výjimka. */
    public function test_vyprseni_casu_neshodi_ulohu_a_uklidi_docasny_soubor(): void
    {
        Storage::fake('public');
        $this->zalozProstor();
        $video = $this->media(['media_type' => 'video', 'extension' => 'mov', 'mime_type' => 'video/quicktime']);

        Process::fake(function (PendingProcess $p) {
            if (in_array('-encoders', $p->command, true)) {
                return Process::result(' V....D libx264   libx264');
            }
            file_put_contents(end($p->command), 'ROZEPSANE');
            $proces = new SymfonyProcess(['ffmpeg']);

            throw new ProcessTimedOutException(new SymfonyTimeout($proces, SymfonyTimeout::TYPE_GENERAL), new ProcessResult($proces));
        });

        $kopie = (new VideoProcessingService)->generateCompatibilityVariant($video, '/data/dlouhe.mov');

        $this->assertNull($kopie);
        $this->assertFileDoesNotExist(storage_path("app/temp/compat_{$video->uuid}.mp4"));
        Storage::disk('public')->assertMissing("media/{$video->uuid}/video_compat.mp4");
        $this->assertDatabaseMissing('media_variants', ['media_item_id' => $video->id, 'type' => 'video_compat']);
    }

    /**
     * Krátký klip nemá na 2. vteřině co zachytit (Live Photo, pár vteřin
     * z telefonu) — dřív z toho zůstala jen SVG náhrada, přestože video
     * obraz mělo. Známá délka sníží čas na polovinu, a když se nezachytí
     * ani tak, druhý pokus je na 0. vteřině.
     */
    public function test_kratke_video_zkusi_druhy_snimek_na_nulte_vterine(): void
    {
        Storage::fake('public');
        $this->zalozProstor();
        $video = $this->media([
            'media_type' => 'video', 'extension' => 'mp4', 'mime_type' => 'video/mp4',
            'duration_ms' => 1500,
        ]);

        $pokusy = [];
        Process::fake(function (PendingProcess $p) use (&$pokusy) {
            $pokusy[] = clone $p;
            if ($this->hodnota($p->command, '-ss') === '0') {
                file_put_contents(end($p->command), 'JPEG');

                return Process::result();
            }

            // Na 0,75 s (polovina délky) ffmpeg nic nezachytí.
            return Process::result(exitCode: 1);
        });

        $plakat = (new VideoProcessingService)->generatePoster($video, '/data/kratke.mp4');

        $this->assertNotNull($plakat);
        Storage::disk('public')->assertExists("media/{$video->uuid}/video_poster.jpg");

        $this->assertCount(2, $pokusy);
        $this->assertSame('0.75', $this->hodnota($pokusy[0]->command, '-ss'));
        $this->assertSame('0', $this->hodnota($pokusy[1]->command, '-ss'));
    }

    public function test_vyprseni_nahledu_vrati_null(): void
    {
        Storage::fake('public');
        $this->zalozProstor();
        $video = $this->media(['media_type' => 'video', 'extension' => 'mp4', 'mime_type' => 'video/mp4']);

        Process::fake(function (PendingProcess $p) {
            file_put_contents(end($p->command), 'PUL-JPEGU');
            $proces = new SymfonyProcess(['ffmpeg']);

            throw new ProcessTimedOutException(new SymfonyTimeout($proces, SymfonyTimeout::TYPE_GENERAL), new ProcessResult($proces));
        });

        $this->assertNull((new VideoProcessingService)->generatePoster($video, '/data/video.mp4'));
        $this->assertFileDoesNotExist(storage_path("app/temp/poster_{$video->uuid}.jpg"));
    }

    /**
     * Stropy do sebe musí zapadat: převod < úloha < `retry_after`.
     *
     * Když převod běží déle než `$timeout` úlohy, worker ji zabije a ffmpeg
     * zůstane napůl zapsaný. Když je úloha delší než `retry_after`, databázová
     * fronta pustí druhou kopii téhož převodu vedle první.
     */
    public function test_stropy_prevodu_ulohy_a_fronty_do_sebe_zapadaji(): void
    {
        $uloha = new GenerateVideoCompatibilityVariantJob(1);
        $retryAfter = (int) config('queue.connections.database.retry_after');

        config(['gallery.video_transcode_timeout' => 999_999]);
        $this->assertLessThan($uloha->timeout, (new VideoProcessingService)->rozpocetPrevodu(),
            'Ani přehnané VIDEO_TRANSCODE_TIMEOUT nesmí přerůst úlohu.');
        $this->assertLessThan($retryAfter, $uloha->timeout);

        // Náhled: sonda + snímek se vejdou do úlohy s rezervou na uložení.
        $this->assertLessThan((new GenerateVideoPosterJob(1))->timeout, 60 + 120 + 60);
    }

    // ——— pomocné ———

    /** Hodnota za přepínačem v poli argumentů. */
    private function hodnota(array $args, string $prepinac): ?string
    {
        $i = array_search($prepinac, $args, true);

        return $i === false ? null : ($args[$i + 1] ?? null);
    }

    /** Stopy shellu: přesměrování, roury a uvozovky z `escapeshellarg`. */
    private function maShell(array $args): bool
    {
        foreach ($args as $arg) {
            if (str_contains($arg, '2>') || $arg === '|' || preg_match("/^'.*'$/", $arg)) {
                return true;
            }
        }

        return false;
    }
}
