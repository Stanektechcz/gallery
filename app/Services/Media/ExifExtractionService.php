<?php

namespace App\Services\Media;

use App\Models\MediaItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ExifExtractionService
{
    private string $exiftoolPath;

    public function __construct()
    {
        $this->exiftoolPath = config('gallery.exiftool_path', '/usr/bin/exiftool');
    }

    public function isAvailable(): bool
    {
        return is_executable($this->exiftoolPath);
    }

    /**
     * Extract all relevant EXIF/metadata from a file.
     */
    public function extract(string $filePath): array
    {
        if (!$this->isAvailable()) {
            return $this->fallbackExtract($filePath);
        }

        $cmd    = escapeshellcmd($this->exiftoolPath) . ' -json -n ' . escapeshellarg($filePath);
        $output = shell_exec($cmd);

        if (!$output) return [];

        $data = json_decode($output, true);
        if (!is_array($data) || empty($data)) return [];

        return $this->normalizeExifData($data[0]);
    }

    private function normalizeExifData(array $raw): array
    {
        $result = [];

        // Taken at (priority order)
        $result['taken_at']          = $this->parseDatetime($raw['DateTimeOriginal'] ?? $raw['CreateDate'] ?? $raw['MediaCreateDate'] ?? null);
        $result['taken_at_timezone'] = $raw['OffsetTimeOriginal'] ?? $raw['OffsetTime'] ?? null;

        // GPS
        $result['latitude']  = isset($raw['GPSLatitude']) ? (float) $raw['GPSLatitude'] : null;
        $result['longitude'] = isset($raw['GPSLongitude']) ? (float) $raw['GPSLongitude'] : null;
        $result['altitude']  = isset($raw['GPSAltitude']) ? (float) $raw['GPSAltitude'] : null;

        // Camera
        $result['camera_make']  = $raw['Make'] ?? null;
        $result['camera_model'] = $raw['Model'] ?? null;
        $result['lens_model']   = $raw['LensModel'] ?? $raw['Lens'] ?? null;

        // Exposure
        $result['iso']           = isset($raw['ISO']) ? (int) $raw['ISO'] : null;
        $result['aperture']      = $raw['Aperture'] ?? $raw['FNumber'] ?? null;
        $result['shutter_speed'] = $raw['ShutterSpeed'] ?? $raw['ExposureTime'] ?? null;
        $result['focal_length']  = isset($raw['FocalLength']) ? (string) $raw['FocalLength'] : null;
        $result['orientation']   = isset($raw['Orientation']) ? (int) $raw['Orientation'] : null;

        // Dimensions
        $result['width']  = isset($raw['ImageWidth']) ? (int) $raw['ImageWidth'] : null;
        $result['height'] = isset($raw['ImageHeight']) ? (int) $raw['ImageHeight'] : null;

        // Rating and description
        $result['rating']      = isset($raw['Rating']) ? (int) $raw['Rating'] : null;
        $result['description'] = $raw['ImageDescription'] ?? $raw['Description'] ?? null;
        $result['caption']     = $raw['Caption-Abstract'] ?? null;
        $result['display_title'] = $raw['Title'] ?? null;

        // XMP keywords
        $result['xmp_keywords'] = $this->parseKeywords($raw['Subject'] ?? $raw['Keywords'] ?? null);

        // Clean nulls
        return array_filter($result, fn($v) => $v !== null && $v !== '');
    }

    private function parseDatetime(?string $dateString): ?Carbon
    {
        if (!$dateString) return null;

        try {
            // ExifTool with -n returns formatted datetime
            return Carbon::parse($dateString);
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseKeywords(mixed $keywords): array
    {
        if (is_array($keywords)) return array_values(array_filter($keywords));
        if (is_string($keywords)) return array_map('trim', explode(',', $keywords));
        return [];
    }

    /**
     * Fallback using PHP's built-in EXIF reader for JPEG.
     */
    private function fallbackExtract(string $filePath): array
    {
        if (!function_exists('exif_read_data')) return [];

        try {
            $exif = @exif_read_data($filePath, null, true);
            if (!$exif) return [];

            $result = [];
            $ifd0 = $exif['IFD0'] ?? [];
            $exifSub = $exif['EXIF'] ?? [];
            $gps = $exif['GPS'] ?? [];

            $result['camera_make']   = $ifd0['Make'] ?? null;
            $result['camera_model']  = $ifd0['Model'] ?? null;
            $result['description']   = $ifd0['ImageDescription'] ?? null;
            $result['orientation']   = isset($ifd0['Orientation']) ? (int) $ifd0['Orientation'] : null;
            $result['iso']           = isset($exifSub['ISOSpeedRatings']) ? (int) $exifSub['ISOSpeedRatings'] : null;
            $result['shutter_speed'] = $exifSub['ExposureTime'] ?? null;

            if ($dateStr = ($exifSub['DateTimeOriginal'] ?? null)) {
                $result['taken_at'] = $this->parseDatetime($dateStr);
            }

            if (!empty($gps)) {
                $result['latitude']  = $this->gpsToDecimal($gps['GPSLatitude'] ?? null, $gps['GPSLatitudeRef'] ?? 'N');
                $result['longitude'] = $this->gpsToDecimal($gps['GPSLongitude'] ?? null, $gps['GPSLongitudeRef'] ?? 'E');
            }

            return array_filter($result, fn($v) => $v !== null);
        } catch (\Throwable $e) {
            Log::warning('Fallback EXIF extraction failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function gpsToDecimal(?array $gps, string $ref): ?float
    {
        if (!$gps || count($gps) < 3) return null;

        $degrees = $this->gpsRationalToFloat($gps[0]);
        $minutes = $this->gpsRationalToFloat($gps[1]);
        $seconds = $this->gpsRationalToFloat($gps[2]);

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

        return in_array(strtoupper($ref), ['S', 'W']) ? -$decimal : $decimal;
    }

    private function gpsRationalToFloat(string $rational): float
    {
        if (str_contains($rational, '/')) {
            [$num, $den] = explode('/', $rational);
            return $den != 0 ? (float) $num / (float) $den : 0.0;
        }
        return (float) $rational;
    }

    /**
     * Write metadata to XMP sidecar.
     */
    public function writeXmpSidecar(string $mediaPath, array $metadata): bool
    {
        if (!$this->isAvailable()) return false;

        $sidecarPath = $mediaPath . '.xmp';
        $args = [];

        if (isset($metadata['description'])) {
            $args[] = '-Description=' . escapeshellarg($metadata['description']);
        }
        if (isset($metadata['rating'])) {
            $args[] = '-Rating=' . (int) $metadata['rating'];
        }
        if (isset($metadata['latitude'], $metadata['longitude'])) {
            $args[] = '-GPSLatitude=' . $metadata['latitude'];
            $args[] = '-GPSLongitude=' . $metadata['longitude'];
        }
        if (!empty($metadata['tags'])) {
            foreach ($metadata['tags'] as $tag) {
                $args[] = '-Subject+=' . escapeshellarg($tag);
            }
        }

        if (empty($args)) return true;

        $cmd = escapeshellcmd($this->exiftoolPath)
            . ' ' . implode(' ', $args)
            . ' -o ' . escapeshellarg($sidecarPath)
            . ' ' . escapeshellarg($mediaPath)
            . ' 2>/dev/null';

        exec($cmd, $out, $code);
        return $code === 0;
    }
}
