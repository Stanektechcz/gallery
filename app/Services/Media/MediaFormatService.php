<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Log;

/**
 * MediaFormatService — Central registry for supported media formats.
 * Handles: format detection, RAW preview extraction, panorama/360 detection,
 * Live Photo pair detection.
 */
class MediaFormatService
{
    // ─── Supported formats ─────────────────────────────────────────────────

    public const IMAGE_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'webp',
        'gif',
        'avif',
        'heic',
        'heif',
        'tiff',
        'tif',
        'bmp',
    ];

    public const RAW_EXTENSIONS = [
        'cr2',
        'cr3',        // Canon
        'nef',
        'nrw',        // Nikon
        'arw',
        'sr2',
        'srf', // Sony
        'dng',               // Adobe DNG (universal RAW)
        'orf',               // Olympus
        'rw2',               // Panasonic
        'raf',               // Fujifilm
        'pef',
        'ptx',        // Pentax
        'srw',               // Samsung
        '3fr',
        'fff',        // Hasselblad
        'kdc',
        'dcr',        // Kodak
        'mrw',               // Minolta
        'rwl',               // Leica
        'x3f',               // Sigma
    ];

    public const VIDEO_EXTENSIONS = [
        'mp4',
        'mov',
        'mkv',
        'webm',
        'm4v',
        'avi',
        'wmv',
        'flv',
        '3gp',
        'ts',
        'mts',
        'm2ts',       // AVCHD
    ];

    /** All accepted extensions (image + raw + video) */
    public static function allExtensions(): array
    {
        return array_merge(self::IMAGE_EXTENSIONS, self::RAW_EXTENSIONS, self::VIDEO_EXTENSIONS);
    }

    public static function isRaw(string $ext): bool
    {
        return in_array(strtolower($ext), self::RAW_EXTENSIONS, true);
    }

    public static function isVideo(string $ext): bool
    {
        return in_array(strtolower($ext), self::VIDEO_EXTENSIONS, true);
    }

    public static function isImage(string $ext): bool
    {
        return in_array(strtolower($ext), array_merge(self::IMAGE_EXTENSIONS, self::RAW_EXTENSIONS), true);
    }

    /** Canonical MIME type for a RAW extension */
    public static function rawMime(string $ext): string
    {
        return match (strtolower($ext)) {
            'cr2'  => 'image/x-canon-cr2',
            'cr3'  => 'image/x-canon-cr3',
            'nef'  => 'image/x-nikon-nef',
            'nrw'  => 'image/x-nikon-nrw',
            'arw'  => 'image/x-sony-arw',
            'dng'  => 'image/x-adobe-dng',
            'orf'  => 'image/x-olympus-orf',
            'rw2'  => 'image/x-panasonic-rw2',
            'raf'  => 'image/x-fuji-raf',
            'pef'  => 'image/x-pentax-pef',
            'srw'  => 'image/x-samsung-srw',
            '3fr'  => 'image/x-hasselblad-3fr',
            default => 'image/x-raw',
        };
    }

    // ─── RAW preview extraction ─────────────────────────────────────────────

    /**
     * Extract the embedded JPEG preview from a RAW file using exiftool.
     * Returns a temp file path to the extracted JPEG, or null on failure.
     */
    public function extractRawPreview(string $rawPath): ?string
    {
        if (! function_exists('proc_open')) {
            return null;
        }

        $tmpJpeg = tempnam(sys_get_temp_dir(), 'raw_preview_') . '.jpg';

        // Try JpgFromRaw first (embedded full-size JPEG), then PreviewImage
        foreach (['-JpgFromRaw', '-PreviewImage'] as $tag) {
            $cmd = ['exiftool', $tag, '-b', '-w', '%d%f_preview.jpg', $rawPath];

            // Alternatively, write directly to stdout
            $cmd2 = ['exiftool', $tag, '-b', $rawPath];
            $desc = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
            $proc = proc_open($cmd2, $desc, $pipes);

            if ($proc === false) continue;

            $data  = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);

            if ($data && strlen($data) > 1000) {
                file_put_contents($tmpJpeg, $data);
                Log::info("RAW preview extracted via {$tag}", ['path' => $rawPath, 'size' => strlen($data)]);
                return $tmpJpeg;
            }
        }

        @unlink($tmpJpeg);
        return null;
    }

    // ─── Panorama / 360° detection ─────────────────────────────────────────

    /**
     * Detect panorama / 360° from EXIF data.
     * Returns array with 'is_panorama', 'is_360', 'panorama_projection'.
     */
    public function detectPanorama(array $exifData, ?int $width = null, ?int $height = null): array
    {
        $result = [
            'is_panorama'         => false,
            'is_360'              => false,
            'panorama_projection' => null,
        ];

        // Check XMP/EXIF panorama tags (exiftool output keys)
        $projection = $exifData['ProjectionType']
            ?? $exifData['GPano:ProjectionType']
            ?? null;

        $usePano = filter_var(
            $exifData['UsePanoramaViewer'] ?? $exifData['GPano:UsePanoramaViewer'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $fullW    = (int) ($exifData['FullPanoWidthPixels']          ?? $exifData['GPano:FullPanoWidthPixels']          ?? 0);
        $croppedW = (int) ($exifData['CroppedAreaImageWidthPixels']  ?? $exifData['GPano:CroppedAreaImageWidthPixels']  ?? 0);

        $isEquirect = $projection && strtolower((string)$projection) === 'equirectangular';

        if ($isEquirect || $usePano) {
            $result['is_panorama']         = true;
            $result['panorama_projection'] = $isEquirect ? 'equirectangular' : 'cylindrical';

            // Full 360° sphere: cropped area == full pano
            if ($fullW > 0 && $croppedW > 0 && $croppedW >= $fullW * 0.98) {
                $result['is_360'] = true;
            }
        }

        // Heuristic: very wide aspect ratio → cylindrical panorama
        $imgW = $width  ?? (int) ($exifData['ImageWidth']  ?? $exifData['ExifImageWidth']  ?? 0);
        $imgH = $height ?? (int) ($exifData['ImageHeight'] ?? $exifData['ExifImageHeight'] ?? 0);

        if ($imgH > 0 && ($imgW / $imgH) >= 2.5 && ! $result['is_panorama']) {
            $result['is_panorama']         = true;
            $result['panorama_projection'] = 'cylindrical';
        }

        // 2:1 aspect ratio with equirectangular projection hints = full 360°
        if ($imgH > 0 && abs(($imgW / $imgH) - 2.0) < 0.05 && $isEquirect) {
            $result['is_360'] = true;
        }

        return $result;
    }

    // ─── Live Photo / Motion Photo detection ───────────────────────────────

    /**
     * Extract Live Photo / Motion Photo metadata from EXIF.
     * Returns ['content_id', 'role', 'embedded_video'] or nulls.
     *
     * - Apple Live Photo: ContentIdentifier in EXIF (both JPEG and MOV share same UUID)
     * - Samsung Motion Photo: MotionPhoto=1 + embedded MP4 in HEIC
     * - Google Motion Photo: MicroVideo=1 in XMP
     */
    public function detectLivePhoto(array $exifData, string $ext): array
    {
        $result = [
            'content_id'      => null,
            'role'            => null,   // 'main' | 'video'
            'is_motion_photo' => false,
        ];

        // Apple Live Photo: JPEG or HEIC has ContentIdentifier
        $contentId = $exifData['ContentIdentifier']
            ?? $exifData['MediaGroupUUID']
            ?? null;

        if ($contentId) {
            $result['content_id'] = (string) $contentId;
            $result['role']       = in_array(strtolower($ext), ['mov', 'mp4']) ? 'video' : 'main';
            $result['is_motion_photo'] = true;
            return $result;
        }

        // Samsung Motion Photo (HEIC with embedded video)
        $motionPhoto = $exifData['MotionPhoto'] ?? $exifData['Samsung:MotionPhoto'] ?? null;
        if ($motionPhoto == '1' || $motionPhoto === 'On') {
            $result['is_motion_photo'] = true;
            $result['role']            = 'main';
            // No content_id from Samsung; we'll match by filename convention
        }

        // Google Motion Photo
        $microVideo = $exifData['MicroVideo'] ?? $exifData['XMP:MicroVideo'] ?? null;
        if ($microVideo == '1') {
            $result['is_motion_photo'] = true;
            $result['role']            = 'main';
        }

        return $result;
    }

    /**
     * Attempt to find a Live Photo pair in the database and link them.
     */
    public function linkLivePhotoPair(int $mediaId, string $contentId, string $role, int $gallerySpaceId): void
    {
        if (! $contentId) return;

        $pairRole    = $role === 'main' ? 'video' : 'main';
        $pairMediaId = \Illuminate\Support\Facades\DB::table('media_items')
            ->where('gallery_space_id', $gallerySpaceId)
            ->where('live_photo_content_id', $contentId)
            ->where('live_photo_role', $pairRole)
            ->value('id');

        if ($pairMediaId) {
            // Link both items to each other
            \Illuminate\Support\Facades\DB::table('media_items')->where('id', $mediaId)->update(['live_photo_pair_id' => $pairMediaId]);
            \Illuminate\Support\Facades\DB::table('media_items')->where('id', $pairMediaId)->update(['live_photo_pair_id' => $mediaId]);
        }
    }
}
