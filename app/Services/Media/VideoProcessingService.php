<?php

namespace App\Services\Media;

use App\Models\MediaItem;
use App\Models\MediaVariant;
use App\Support\Program;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * ffmpeg a ffprobe se spouštějí přes `Program::spust()` — `proc_open`, pole
 * argumentů, vlastní strop času. `exec`/`shell_exec` jsou na serveru vypnuté
 * a video s nimi tiše zůstávalo bez náhledu, údajů i kopie pro prohlížeč.
 */
class VideoProcessingService
{
    /** ffprobe čte jen hlavičky; déle trvá jen soubor, který je rozbitý nebo na mrtvém disku. */
    private const LIMIT_SONDY = 60;

    /** Jeden snímek s `-ss` před `-i` — ani 4K video nedekóduje celé. */
    private const LIMIT_PLAKATU = 120;

    /** `ffmpeg -encoders` jen vypíše seznam. */
    private const LIMIT_KODERU = 30;

    /**
     * Přebalení bez překódování (`-c copy`) — rychlost dá disk, ne procesor.
     * Kopie k přehrávání má nejvýš 5 Mb/s, i hodinová je pod 2,5 GB.
     */
    private const LIMIT_PREBALENI = 600;

    /**
     * Strop pro převod, ať je v konfiguraci cokoli.
     *
     * `GenerateVideoCompatibilityVariantJob::$timeout` je 3600 s. Převod musí
     * skončit dřív, než úlohu zabije worker — jinak zůstane ffmpeg běžet
     * a dočasný soubor napůl zapsaný. Pět minut rezervy je na uložení kopie
     * na disk. Hlídá `tests/Feature/Media/VideoPresProcesTest.php`.
     */
    public const STROP_PREVODU = 3300;

    /** Kratší zbytek stropu už na softwarový pokus nestačí — radši nic než useknuté video. */
    private const NEJKRATSI_POKUS = 60;

    /** Hardwarové kodéry v pořadí přednosti; bez nich libx264. */
    private const HARDWAROVE_KODERY = ['h264_qsv', 'h264_vaapi', 'h264_nvenc'];

    /** Značky, pod kterými telefony zapisují polohu (Android, iPhone). */
    private const ZNACKY_POLOHY = ['location', 'location-eng', 'com.apple.quicktime.location.iso6709'];

    private string $ffmpegPath;

    private string $ffprobePath;

    /** Vybraný kodér — seznam se čte jednou za život instance, ne před každým převodem. */
    private ?string $koder = null;

    public function __construct()
    {
        $this->ffmpegPath = config('gallery.ffmpeg_path', '/usr/bin/ffmpeg');
        $this->ffprobePath = config('gallery.ffprobe_path', '/usr/bin/ffprobe');
    }

    public function isAvailable(): bool
    {
        // Ne `is_executable()` napřímo: pod `open_basedir` webového serveru
        // hází varování → výjimku a nahrávání z prohlížeče pak ffmpeg vynechalo.
        return Program::lzeSpustit($this->ffmpegPath) && Program::lzeSpustit($this->ffprobePath);
    }

    /**
     * Extract video metadata via ffprobe.
     */
    public function extractMetadata(string $path): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $data = $this->sonda($path, 'ffprobe');

        return is_array($data) ? $this->metadataZeSondy($data) : [];
    }

    /**
     * Výstup `ffprobe -show_format -show_streams` jako pole, nebo `null`.
     *
     * `-v error` místo `-v quiet`: stdout zůstane čistý JSON, a když sonda
     * selže, v logu je důvod.
     */
    private function sonda(string $path, string $popis): ?array
    {
        $vysledek = Program::spust(
            [$this->ffprobePath, '-v', 'error', '-print_format', 'json', '-show_streams', '-show_format', $path],
            self::LIMIT_SONDY,
            $popis,
            ['path' => $path],
        );
        $data = $vysledek ? json_decode($vysledek->output(), true) : null;

        return is_array($data) ? $data : null;
    }

    /**
     * Výstup ffprobe → sloupce `media_items`.
     *
     * Čas: `creation_time` je UTC a ukládal se tak, jak je — video natočené
     * v Praze ve 0:30 mělo v knihovně 22:30 předchozího dne a na časové ose
     * i ve vzpomínkách patřilo ke špatnému dni. Převádí se na hodiny dvojice
     * (jako se ukládají fotky z EXIF); Apple `creationdate` nese posun
     * a má přednost.
     *
     * Rozměry: telefon na výšku ukládá stopu 1920×1080 s otočením ±90°.
     * Bez prohození měla videa na výšku v mřížce dlaždici na šířku.
     */
    private function metadataZeSondy(array $data): array
    {
        $format = $data['format'] ?? [];
        $streams = $data['streams'] ?? [];

        $videoStream = collect($streams)->firstWhere('codec_type', 'video');
        $audioStream = collect($streams)->firstWhere('codec_type', 'audio');

        $durationSec = (float) ($format['duration'] ?? 0);
        $width = (int) ($videoStream['width'] ?? 0);
        $height = (int) ($videoStream['height'] ?? 0);
        if (abs($this->otoceni($videoStream ?? [])) % 180 === 90) {
            [$width, $height] = [$height, $width];
        }

        $metadata = [
            'duration_ms' => (int) ($durationSec * 1000),
            'bitrate' => (int) ($format['bit_rate'] ?? 0),
            'width' => $width,
            'height' => $height,
            // r_frame_rate může být u HEVC pouze časová základna (např.
            // 90000/1), nikoliv skutečná frekvence snímků. avg_frame_rate
            // je správná hodnota pro zobrazení i databázi.
            'frame_rate' => $this->parseFrameRate($videoStream['avg_frame_rate'] ?? $videoStream['r_frame_rate'] ?? '0/1'),
            'video_codec' => $videoStream['codec_name'] ?? null,
            'audio_codec' => $audioStream['codec_name'] ?? null,
        ];

        $tagy = array_merge($videoStream['tags'] ?? [], $format['tags'] ?? []);
        $metadata['taken_at'] = ExifExtractionService::hodinyVidea(
            $tagy['com.apple.quicktime.creationdate'] ?? null,
            $tagy['creation_time'] ?? null,
        );

        return array_filter($metadata, fn ($value) => $value !== null && $value !== 0 && $value !== 0.0);
    }

    /** Otočení stopy ve stupních — novější ffprobe v `side_data_list`, starší v `tags.rotate`. */
    private function otoceni(array $videoStream): int
    {
        foreach ($videoStream['side_data_list'] ?? [] as $side) {
            if (is_array($side) && isset($side['rotation']) && is_numeric($side['rotation'])) {
                return (int) $side['rotation'];
            }
        }

        $rotate = $videoStream['tags']['rotate'] ?? null;

        return is_numeric($rotate) ? (int) $rotate : 0;
    }

    /**
     * Generate a video poster (thumbnail at specified time).
     */
    public function generatePoster(MediaItem $mediaItem, string $sourcePath, float $timeSeconds = 2.0): ?MediaVariant
    {
        if (! $this->isAvailable()) {
            return null;
        }

        // Stejný adresář jako originál. Má ověřená oprávnění z uploadu a
        // obsah médií jde takto smazat i archivovat jako jeden celek.
        $dir = "media/{$mediaItem->uuid}";
        $filename = 'video_poster.jpg';
        $tmpPath = storage_path("app/temp/poster_{$mediaItem->uuid}.jpg");

        @mkdir(dirname($tmpPath), 0755, true);

        // Seek before opening the input. This avoids decoding a whole long
        // recording just to produce its preview.
        $vysledek = Program::spust(
            [...$this->ffmpeg(), '-ss', (string) $timeSeconds, '-i', $sourcePath, '-vframes', '1', '-q:v', '2', $tmpPath],
            self::LIMIT_PLAKATU,
            'ffmpeg (plakát videa)',
            ['media_id' => $mediaItem->id],
        );

        if (! $this->vzniklo($vysledek, $tmpPath)) {
            // Po vypršení času nebo pádu může zbýt napůl zapsaný JPEG.
            @unlink($tmpPath);
            Log::warning("FFmpeg poster generation failed for media #{$mediaItem->id}");

            return null;
        }

        $path = "{$dir}/{$filename}";
        $stream = fopen($tmpPath, 'rb');
        $stored = $stream && Storage::disk('public')->put($path, $stream, 'public');
        if (is_resource($stream)) {
            fclose($stream);
        }
        @unlink($tmpPath);

        if (! $stored) {
            Log::error("Video poster could not be stored for media #{$mediaItem->id}", ['path' => $path]);

            return null;
        }

        $poster = MediaVariant::updateOrCreate(
            ['media_item_id' => $mediaItem->id, 'type' => 'video_poster'],
            [
                'disk' => 'public',
                'path' => $path,
                'format' => 'jpg',
                'size_bytes' => Storage::disk('public')->size($path),
            ]
        );

        // Most gallery grids ask only for the canonical thumbnail variant.
        // Reuse the same tiny JPEG rather than ever loading a video as an img.
        MediaVariant::updateOrCreate(
            ['media_item_id' => $mediaItem->id, 'type' => 'thumbnail'],
            [
                'disk' => 'public',
                'path' => $path,
                'format' => 'jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => $poster->size_bytes,
                'width' => $poster->width,
                'height' => $poster->height,
            ]
        );

        return $poster;
    }

    /**
     * A visible fallback when FFmpeg is unavailable or a damaged video cannot
     * yield a frame. It is intentionally small and is later replaced by a
     * real poster without changing any frontend URLs.
     */
    public function generateFallbackPoster(MediaItem $mediaItem): MediaVariant
    {
        $path = "media/{$mediaItem->uuid}/video_placeholder.svg";
        $title = htmlspecialchars($mediaItem->display_title ?: pathinfo($mediaItem->original_filename, PATHINFO_FILENAME), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="800" height="450" viewBox="0 0 800 450">
  <rect width="800" height="450" fill="#171725"/>
  <rect x="330" y="155" width="140" height="140" rx="70" fill="#7c3aed"/>
  <path d="M388 197v56l48-28z" fill="white"/>
  <text x="400" y="345" text-anchor="middle" fill="#e9d5ff" font-family="Arial, sans-serif" font-size="20">{$title}</text>
</svg>
SVG;

        if (! Storage::disk('public')->put($path, $svg, 'public')) {
            throw new \RuntimeException("Nepodařilo se uložit náhradní náhled videa: {$path}");
        }

        $values = [
            'disk' => 'public', 'path' => $path, 'format' => 'svg',
            'mime_type' => 'image/svg+xml', 'size_bytes' => strlen($svg),
            'width' => 800, 'height' => 450,
        ];
        $mediaItem->variants()->updateOrCreate(['type' => 'video_poster'], $values);

        return $mediaItem->variants()->updateOrCreate(['type' => 'thumbnail'], $values);
    }

    /**
     * Generate H.264 + AAC web-compatible variant.
     */
    public function generateCompatibilityVariant(MediaItem $mediaItem, string $sourcePath): ?MediaVariant
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $dir = "media/{$mediaItem->uuid}";
        $tmpPath = storage_path("app/temp/compat_{$mediaItem->uuid}.mp4");

        @mkdir(dirname($tmpPath), 0755, true);

        // Try to detect hardware acceleration
        $encoder = $this->selectVideoEncoder();

        // A phone's 4K/HEVC original is kept untouched, but it is a poor
        // browser stream: many devices cannot decode it and its bitrate makes
        // seeking stall. The playback copy is capped at 1080p/5 Mb/s, uses a
        // universally decodable pixel format and moves MP4 metadata to the
        // beginning so the first frame can play before the full download.
        //
        // `-map_metadata -1 -map_chapters -1`: kopie nese jen obraz a zvuk,
        // ne metadata zdroje. Telefon do nich zapisuje polohu (iPhone
        // `com.apple.quicktime.location.ISO6709`, Android `location`) a ffmpeg
        // je jinak do kopie přenese — kopii přitom dostává i sdílená stránka.
        // Otočení z telefonu ffmpeg při překódování rovnou použije na snímky
        // (autorotate je výchozí), takže o ně kopie bez metadat nepřijde.
        //
        // Obě větve sdílí jeden strop (`rozpocetPrevodu()`): softwarový pokus
        // dostane jen to, co z něj zbylo. Dohromady se tak vejdou pod
        // `$timeout` úlohy a worker ji nezabije uprostřed zápisu.
        $filter = "scale=w='min(1920,iw)':h='min(1080,ih)':force_original_aspect_ratio=decrease:force_divisible_by=2";
        $rozpocet = $this->rozpocetPrevodu();
        $zacatek = microtime(true);
        $kontext = ['media_id' => $mediaItem->id];

        try {
            $vysledek = Program::spust(
                $this->prikazKopie($sourcePath, $filter, ['-c:v', $encoder, '-preset', 'fast', '-b:v', '4M'], $tmpPath),
                $rozpocet,
                "ffmpeg (kopie videa, {$encoder})",
                $kontext,
            );

            if (! $this->vzniklo($vysledek, $tmpPath)) {
                // Hardware encoders occasionally advertise themselves but reject
                // one source format. A software H.264 retry is slower to create
                // yet guarantees a playable result instead of a permanently
                // stuttering original.
                @unlink($tmpPath);
                $zbyva = $rozpocet - (int) ceil(microtime(true) - $zacatek);
                if ($zbyva < self::NEJKRATSI_POKUS) {
                    Log::warning("FFmpeg compat variant failed for media #{$mediaItem->id}: no time left for the libx264 retry");

                    return null;
                }

                $vysledek = Program::spust(
                    $this->prikazKopie($sourcePath, $filter, ['-c:v', 'libx264', '-preset', 'veryfast', '-crf', '24'], $tmpPath),
                    $zbyva,
                    'ffmpeg (kopie videa, libx264)',
                    $kontext,
                );
                if (! $this->vzniklo($vysledek, $tmpPath)) {
                    Log::warning("FFmpeg compat variant failed for media #{$mediaItem->id}");

                    return null;
                }
            }

            $path = "{$dir}/video_compat.mp4";
            $stream = fopen($tmpPath, 'rb');
            $stored = $stream && Storage::disk('public')->put($path, $stream, 'public');
            if (is_resource($stream)) {
                fclose($stream);
            }
        } finally {
            // Po selhání i po vypršení času zbývá napůl zapsaný soubor, který
            // by jinak ležel v `storage/app/temp` do dalšího úklidu.
            @unlink($tmpPath);
        }

        if (! $stored) {
            Log::error("Compatible video could not be stored for media #{$mediaItem->id}", ['path' => $path]);

            return null;
        }

        return MediaVariant::updateOrCreate(
            ['media_item_id' => $mediaItem->id, 'type' => 'video_compat'],
            [
                'disk' => 'public',
                'path' => $path,
                'format' => 'mp4',
                'size_bytes' => Storage::disk('public')->size($path),
            ]
        );
    }

    /**
     * Select the best available video encoder.
     */
    public function selectVideoEncoder(): string
    {
        if ($this->koder !== null) {
            return $this->koder;
        }

        // Seznam jednou a přečtený v PHP. Dřív to byly tři běhy
        // `ffmpeg -encoders | grep …` — a `exec` ani roura na serveru nejdou.
        // Řádek seznamu: ` V....D h264_nvenc   NVIDIA NVENC H.264 encoder`.
        $vysledek = Program::spust([$this->ffmpegPath, '-hide_banner', '-encoders'], self::LIMIT_KODERU, 'ffmpeg -encoders');
        preg_match_all('/^\s*V\S{5}\s+(\S+)/m', $vysledek?->output() ?? '', $shody);

        $dostupne = array_intersect(self::HARDWAROVE_KODERY, $shody[1]);

        return $this->koder = reset($dostupne) ?: 'libx264';
    }

    /**
     * Kolik vteřin smí trvat převod jednoho videa (oba pokusy dohromady).
     *
     * Z `gallery.video_transcode_timeout`, ale nikdy přes `STROP_PREVODU`:
     * přehnané číslo v `.env` by jinak vrátilo zabíjení úlohy uprostřed převodu.
     */
    public function rozpocetPrevodu(): int
    {
        $nastaveno = (int) config('gallery.video_transcode_timeout', 3000);

        return max(self::NEJKRATSI_POKUS, min($nastaveno, self::STROP_PREVODU));
    }

    /**
     * Nese soubor v metadatech polohu? `null`, když se to nepodařilo zjistit.
     *
     * Telefon ji zapisuje do metadat kontejneru i stopy: Android `location`
     * (a `location-eng`), iPhone `com.apple.quicktime.location.ISO6709`.
     */
    public function nesePolohu(string $path): ?bool
    {
        $data = $this->sonda($path, 'ffprobe (poloha)');
        if ($data === null) {
            return null;
        }

        $vsechnyTagy = [$data['format']['tags'] ?? []];
        foreach ($data['streams'] ?? [] as $stopa) {
            $vsechnyTagy[] = is_array($stopa) ? ($stopa['tags'] ?? []) : [];
        }

        foreach ($vsechnyTagy as $tagy) {
            foreach (is_array($tagy) ? array_keys($tagy) : [] as $klic) {
                if (in_array(strtolower((string) $klic), self::ZNACKY_POLOHY, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Přebalí kopii videa bez metadat (bez překódování) a vymění ji na místě.
     *
     * Dočasný soubor leží ve stejném adresáři, takže `rename()` je na témž
     * disku atomický: přehrávač, který si kopii zrovna stahuje, dostane
     * celou starou, nebo celou novou — nikdy půlku. Před výměnou se nová
     * kopie ještě jednou prozkoumá; když polohu nese dál, nic se nemění.
     */
    public function odstranPolohu(string $path): bool
    {
        $tmp = dirname($path).'/.'.pathinfo($path, PATHINFO_FILENAME).'.bez-polohy.mp4';
        @unlink($tmp);

        try {
            $vysledek = Program::spust(
                [...$this->ffmpeg(), '-i', $path, '-map', '0', '-map_metadata', '-1', '-map_chapters', '-1',
                    '-c', 'copy', '-movflags', '+faststart', $tmp],
                self::LIMIT_PREBALENI,
                'ffmpeg (kopie videa bez polohy)',
                ['path' => $path],
            );
            if (! $this->vzniklo($vysledek, $tmp)) {
                return false;
            }
            if ($this->nesePolohu($tmp) !== false) {
                Log::warning('Přebalená kopie videa pořád nese polohu, nechávám původní', ['path' => $path]);

                return false;
            }

            // Stejná práva jako měl původní soubor — webový server ho musí dál číst.
            $prava = @fileperms($path);
            if ($prava !== false) {
                @chmod($tmp, $prava & 0777);
            }

            if (! @rename($tmp, $path)) {
                Log::warning('Kopii videa bez polohy nešlo přesunout na místo původní', ['path' => $path]);

                return false;
            }

            return true;
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** ffmpeg s volbami pro běh bez člověka: nečte vstup z terminálu, do stderr píše jen chyby. */
    private function ffmpeg(): array
    {
        return [$this->ffmpegPath, '-y', '-nostdin', '-hide_banner', '-loglevel', 'error'];
    }

    /**
     * Příkaz pro kopii k přehrávání; liší se jen volbami kodéru obrazu.
     *
     * @param  list<string>  $obraz  `-c:v …` a jeho nastavení
     */
    private function prikazKopie(string $zdroj, string $filtr, array $obraz, string $cil): array
    {
        return [
            ...$this->ffmpeg(),
            '-i', $zdroj,
            '-map', '0:v:0', '-map', '0:a?',
            '-map_metadata', '-1', '-map_chapters', '-1',
            '-vf', $filtr,
            ...$obraz,
            '-maxrate', '5M', '-bufsize', '10M',
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '128k',
            '-movflags', '+faststart',
            $cil,
        ];
    }

    /** Program doběhl a po sobě nechal neprázdný soubor. */
    private function vzniklo(?ProcessResult $vysledek, string $soubor): bool
    {
        clearstatcache(true, $soubor);

        return $vysledek !== null && is_file($soubor) && filesize($soubor) > 0;
    }

    private function parseFrameRate(string $frStr): ?float
    {
        if (str_contains($frStr, '/')) {
            [$num, $den] = explode('/', $frStr);
            $value = (float) $den !== 0.0 ? (float) $num / (float) $den : 0.0;
        } else {
            $value = (float) $frStr;
        }

        // 1–240 fps pokrývá běžné i slow-motion záznamy. Hodnoty jako
        // 90 000 jsou transportní časová základna a nesmí se ukládat.
        return $value > 0 && $value <= 240 ? round($value, 3) : null;
    }
}
