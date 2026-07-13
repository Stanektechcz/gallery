<?php

namespace App\Services\Media;

use App\Models\MediaItem;
use App\Models\MediaVariant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class VideoProcessingService
{
    private string $ffmpegPath;
    private string $ffprobePath;

    public function __construct()
    {
        $this->ffmpegPath  = config('gallery.ffmpeg_path', '/usr/bin/ffmpeg');
        $this->ffprobePath = config('gallery.ffprobe_path', '/usr/bin/ffprobe');
    }

    public function isAvailable(): bool
    {
        return is_executable($this->ffmpegPath) && is_executable($this->ffprobePath);
    }

    /**
     * Extract video metadata via ffprobe.
     */
    public function extractMetadata(string $path): array
    {
        if (!$this->isAvailable()) return [];

        $cmd = escapeshellcmd($this->ffprobePath)
            . ' -v quiet -print_format json -show_streams -show_format '
            . escapeshellarg($path);

        $output = shell_exec($cmd);
        if (!$output) return [];

        $data   = json_decode($output, true);
        $format = $data['format'] ?? [];
        $streams = $data['streams'] ?? [];

        $videoStream = collect($streams)->firstWhere('codec_type', 'video');
        $audioStream = collect($streams)->firstWhere('codec_type', 'audio');

        $durationSec = (float) ($format['duration'] ?? 0);

        $metadata = [
            'duration_ms'  => (int) ($durationSec * 1000),
            'bitrate'      => (int) ($format['bit_rate'] ?? 0),
            'width'        => (int) ($videoStream['width'] ?? 0),
            'height'       => (int) ($videoStream['height'] ?? 0),
            // r_frame_rate může být u HEVC pouze časová základna (např.
            // 90000/1), nikoliv skutečná frekvence snímků. avg_frame_rate
            // je správná hodnota pro zobrazení i databázi.
            'frame_rate'   => $this->parseFrameRate($videoStream['avg_frame_rate'] ?? $videoStream['r_frame_rate'] ?? '0/1'),
            'video_codec'  => $videoStream['codec_name'] ?? null,
            'audio_codec'  => $audioStream['codec_name'] ?? null,
        ];

        $createdAt = $format['tags']['creation_time'] ?? $videoStream['tags']['creation_time'] ?? null;
        if ($createdAt) {
            try {
                $metadata['taken_at'] = \Carbon\Carbon::parse($createdAt);
            } catch (\Throwable) {
            }
        }

        return array_filter($metadata, fn ($value) => $value !== null && $value !== 0 && $value !== 0.0);
    }

    /**
     * Generate a video poster (thumbnail at specified time).
     */
    public function generatePoster(MediaItem $mediaItem, string $sourcePath, float $timeSeconds = 2.0): ?MediaVariant
    {
        if (!$this->isAvailable()) return null;

        // Stejný adresář jako originál. Má ověřená oprávnění z uploadu a
        // obsah médií jde takto smazat i archivovat jako jeden celek.
        $dir      = "media/{$mediaItem->uuid}";
        $filename = 'video_poster.jpg';
        $tmpPath  = storage_path("app/temp/poster_{$mediaItem->uuid}.jpg");

        @mkdir(dirname($tmpPath), 0755, true);

        // Seek before opening the input. This avoids decoding a whole long
        // recording just to produce its preview.
        $cmd = sprintf(
            '%s -y -ss %s -i %s -vframes 1 -q:v 2 %s 2>/dev/null',
            escapeshellcmd($this->ffmpegPath),
            escapeshellarg((string) $timeSeconds),
            escapeshellarg($sourcePath),
            escapeshellarg($tmpPath)
        );

        exec($cmd, $out, $exitCode);

        if ($exitCode !== 0 || !file_exists($tmpPath)) {
            Log::warning("FFmpeg poster generation failed for media #{$mediaItem->id}");
            return null;
        }

        $path = "{$dir}/{$filename}";
        $stream = fopen($tmpPath, 'rb');
        $stored = $stream && Storage::disk('public')->put($path, $stream, 'public');
        if (is_resource($stream)) fclose($stream);
        @unlink($tmpPath);

        if (!$stored) {
            Log::error("Video poster could not be stored for media #{$mediaItem->id}", ['path' => $path]);
            return null;
        }

        $poster = MediaVariant::updateOrCreate(
            ['media_item_id' => $mediaItem->id, 'type' => 'video_poster'],
            [
                'disk'       => 'public',
                'path'       => $path,
                'format'     => 'jpg',
                'size_bytes' => Storage::disk('public')->size($path),
            ]
        );

        // Most gallery grids ask only for the canonical thumbnail variant.
        // Reuse the same tiny JPEG rather than ever loading a video as an img.
        MediaVariant::updateOrCreate(
            ['media_item_id' => $mediaItem->id, 'type' => 'thumbnail'],
            [
                'disk'       => 'public',
                'path'       => $path,
                'format'     => 'jpg',
                'mime_type'  => 'image/jpeg',
                'size_bytes' => $poster->size_bytes,
                'width'      => $poster->width,
                'height'     => $poster->height,
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

        if (!Storage::disk('public')->put($path, $svg, 'public')) {
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
        if (!$this->isAvailable()) return null;

        $dir     = "media/{$mediaItem->uuid}";
        $tmpPath = storage_path("app/temp/compat_{$mediaItem->uuid}.mp4");

        @mkdir(dirname($tmpPath), 0755, true);

        // Try to detect hardware acceleration
        $encoder = $this->selectVideoEncoder();

        $cmd = sprintf(
            '%s -y -i %s -c:v %s -preset fast -crf 23 -c:a aac -b:a 128k -movflags +faststart %s 2>/dev/null',
            escapeshellcmd($this->ffmpegPath),
            escapeshellarg($sourcePath),
            $encoder,
            escapeshellarg($tmpPath)
        );

        exec($cmd, $out, $exitCode);

        if ($exitCode !== 0 || !file_exists($tmpPath)) {
            Log::warning("FFmpeg compat variant failed for media #{$mediaItem->id}");
            return null;
        }

        $path = "{$dir}/video_compat.mp4";
        $stream = fopen($tmpPath, 'rb');
        $stored = $stream && Storage::disk('public')->put($path, $stream, 'public');
        if (is_resource($stream)) fclose($stream);
        @unlink($tmpPath);

        if (!$stored) {
            Log::error("Compatible video could not be stored for media #{$mediaItem->id}", ['path' => $path]);
            return null;
        }

        return MediaVariant::updateOrCreate(
            ['media_item_id' => $mediaItem->id, 'type' => 'video_compat'],
            [
                'disk'       => 'public',
                'path'       => $path,
                'format'     => 'mp4',
                'size_bytes' => Storage::disk('public')->size($path),
            ]
        );
    }

    /**
     * Select the best available video encoder.
     */
    public function selectVideoEncoder(): string
    {
        // Try Intel Quick Sync
        exec(escapeshellcmd($this->ffmpegPath) . ' -encoders 2>/dev/null | grep h264_qsv', $out);
        if (!empty($out)) return 'h264_qsv';

        // Try VAAPI
        exec(escapeshellcmd($this->ffmpegPath) . ' -encoders 2>/dev/null | grep h264_vaapi', $out);
        if (!empty($out)) return 'h264_vaapi';

        // Try NVENC
        exec(escapeshellcmd($this->ffmpegPath) . ' -encoders 2>/dev/null | grep h264_nvenc', $out);
        if (!empty($out)) return 'h264_nvenc';

        // Software fallback
        return 'libx264';
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
