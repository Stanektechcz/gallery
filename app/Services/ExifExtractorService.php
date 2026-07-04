<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Robust EXIF/GPS extractor for all image formats including HEIC/HEIF.
 *
 * Priority chain:
 * 1. exiftool via proc_open (bypasses shell_exec disable, reads XMP+EXIF+ISOBMFF)
 * 2. Imagick getImageProperties (EXIF IFD only)
 * 3. PHP exif_read_data (JPEG/TIFF only)
 * 4. Native binary HEIC/EXIF parser (GPS from raw bytes, no extensions needed)
 */
class ExifExtractorService
{
    private string $exiftoolPath;

    public function __construct()
    {
        // Do NOT use file_exists() on exiftool path — blocked by PHP open_basedir.
        // Just store configured path and let proc_open fail gracefully if missing.
        $this->exiftoolPath = config('gallery.exiftool_path', '/usr/bin/exiftool');
    }

    /**
     * Extract all available metadata from a file.
     * Returns array with keys: latitude, longitude, altitude, taken_at,
     * camera_make, camera_model, lens_model, width, height,
     * iso, aperture, shutter_speed, focal_length
     */
    public function extract(string $sourcePath): array
    {
        if (!file_exists($sourcePath)) {
            return [];
        }

        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        // 1. exiftool via proc_open (most reliable, reads all metadata formats)
        $data = $this->extractViaExiftool($sourcePath);
        if (!empty($data)) {
            Log::debug('EXIF extracted via exiftool', [
                'file' => basename($sourcePath),
                'has_gps' => isset($data['latitude']),
            ]);
            return $data;
        }

        // 2. Imagick (EXIF IFD segment — may miss XMP GPS on HEIC)
        $data = $this->extractViaImagick($sourcePath);
        if (!empty($data)) {
            Log::debug('EXIF extracted via Imagick', ['file' => basename($sourcePath)]);
            return $data;
        }

        // 3. PHP exif_read_data (JPEG/TIFF only)
        if (in_array($ext, ['jpg', 'jpeg', 'tiff', 'tif'])) {
            $data = $this->extractViaPhpExif($sourcePath);
            if (!empty($data)) {
                Log::debug('EXIF extracted via PHP exif_read_data', ['file' => basename($sourcePath)]);
                return $data;
            }
        }

        // 4. Native binary parser — reads EXIF IFD from raw bytes, no extensions needed
        $data = $this->extractViaBinaryParser($sourcePath, $ext);
        if (!empty($data)) {
            Log::debug('EXIF extracted via binary parser', ['file' => basename($sourcePath)]);
            return $data;
        }

        Log::info('No EXIF/GPS data found in file', ['file' => basename($sourcePath), 'ext' => $ext]);
        return [];
    }

    // ─── Method 1: exiftool via proc_open ───────────────────────────────────

    private function extractViaExiftool(string $sourcePath): array
    {
        // open_basedir prevents file_exists() on /usr/bin/exiftool.
        // Always attempt proc_open — exit code != 0 if binary missing.
        if (empty($this->exiftoolPath)) {
            return [];
        }

        try {
            // proc_open works even when shell_exec / exec are disabled in php.ini
            // Note: NO -G1 (would prefix keys), NO -a (would create arrays for duplicates)
            // -n gives decimal GPS values directly
            $cmd = [
                $this->exiftoolPath,
                '-json',
                '-n',
                '-charset',
                'UTF8',
                $sourcePath,
            ];

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $proc = proc_open($cmd, $descriptors, $pipes);
            if (!is_resource($proc)) {
                return [];
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);

            if ($exitCode !== 0 || !$stdout) {
                if ($stderr) {
                    Log::debug('exiftool stderr', ['err' => substr($stderr, 0, 200)]);
                }
                return [];
            }

            $raw = json_decode($stdout, true);
            if (!$raw || !isset($raw[0])) {
                return [];
            }

            // Log GPS keys found for debugging
            $gpsKeys = array_filter(array_keys($raw[0]), fn($k) => stripos($k, 'gps') !== false || stripos($k, 'latitude') !== false || stripos($k, 'longitude') !== false);
            if ($gpsKeys) {
                Log::info('exiftool GPS keys found', array_intersect_key($raw[0], array_flip($gpsKeys)));
            } else {
                Log::info('exiftool: no GPS keys in output', ['file' => basename($sourcePath), 'total_keys' => count($raw[0])]);
            }

            return $this->normalizeExiftoolData($raw[0]);
        } catch (\Throwable $e) {
            Log::warning('exiftool extraction failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function normalizeExiftoolData(array $raw): array
    {
        $result = [];

        // Without -G1, exiftool returns simple keys: GPSLatitude, DateTimeOriginal, etc.
        // exiftool -n returns GPS as decimal floats directly
        $get = function (string ...$keys) use ($raw): mixed {
            foreach ($keys as $key) {
                // Direct match (no prefix, most common without -G1)
                if (isset($raw[$key]) && $raw[$key] !== '' && $raw[$key] !== null) {
                    return $raw[$key];
                }
                // Case-insensitive search as fallback
                $lower = strtolower($key);
                foreach ($raw as $k => $v) {
                    if (strtolower($k) === $lower && $v !== '' && $v !== null) {
                        return $v;
                    }
                }
            }
            return null;
        };

        // GPS — exiftool -n returns decimal degrees directly (no rational conversion needed)
        $lat = $get('GPSLatitude', 'Latitude');
        $lng = $get('GPSLongitude', 'Longitude');
        if ($lat !== null && $lng !== null && is_numeric($lat) && is_numeric($lng)) {
            $result['latitude']  = (float) $lat;
            $result['longitude'] = (float) $lng;
        }

        $alt = $get('GPSAltitude', 'Altitude');
        if ($alt !== null && is_numeric($alt)) {
            $result['altitude'] = round((float) $alt, 1);
        }

        // Date/time
        $dt = $get('DateTimeOriginal', 'CreateDate', 'DateTime', 'MediaCreateDate', 'TrackCreateDate');
        if ($dt) {
            try {
                $result['taken_at'] = \Carbon\Carbon::parse((string) $dt);
            } catch (\Throwable) {
            }
        }

        // Camera
        $make = $get('Make', 'CameraManufacturer');
        if ($make) $result['camera_make'] = substr((string)$make, 0, 100);

        $model = $get('Model', 'CameraModelName');
        if ($model) $result['camera_model'] = substr((string)$model, 0, 100);

        $lens = $get('LensModel', 'Lens', 'LensID');
        if ($lens) $result['lens_model'] = substr((string)$lens, 0, 255);

        // Technical
        $iso = $get('ISO', 'ISOSpeedRatings', 'PhotographicSensitivity');
        if ($iso) $result['iso'] = (int) $iso;

        $aperture = $get('Aperture', 'FNumber', 'ApertureValue');
        if ($aperture) $result['aperture'] = (string) $aperture;

        $shutter = $get('ExposureTime', 'ShutterSpeed', 'ShutterSpeedValue');
        if ($shutter) $result['shutter_speed'] = (string) $shutter;

        $focal = $get('FocalLength', 'FocalLength35efl');
        if ($focal) $result['focal_length'] = (string) $focal;

        $w = $get('ImageWidth', 'ExifImageWidth', 'PixelXDimension', 'ImageSize');
        $h = $get('ImageHeight', 'ExifImageHeight', 'PixelYDimension');
        if ($w && is_numeric($w)) $result['width']  = (int) $w;
        if ($h && is_numeric($h)) $result['height'] = (int) $h;

        return $result;
    }

    // ─── Method 2: Imagick ──────────────────────────────────────────────────

    private function extractViaImagick(string $sourcePath): array
    {
        if (!extension_loaded('imagick')) return [];

        try {
            $im    = new \Imagick($sourcePath . '[0]');
            $props = $im->getImageProperties('exif:*');
            $w     = $im->getImageWidth();
            $h     = $im->getImageHeight();
            $im->destroy();

            $result = [];
            if ($w > 0) {
                $result['width'] = $w;
                $result['height'] = $h;
            }

            if (!empty($props['exif:DateTimeOriginal'])) {
                try {
                    $result['taken_at'] = \Carbon\Carbon::createFromFormat('Y:m:d H:i:s', $props['exif:DateTimeOriginal']);
                } catch (\Throwable) {
                }
            }
            if (!empty($props['exif:Make']))  $result['camera_make']  = substr($props['exif:Make'],  0, 100);
            if (!empty($props['exif:Model'])) $result['camera_model'] = substr($props['exif:Model'], 0, 100);

            $lat = $this->parseImagickGps($props['exif:GPSLatitude'] ?? null, $props['exif:GPSLatitudeRef'] ?? 'N');
            $lng = $this->parseImagickGps($props['exif:GPSLongitude'] ?? null, $props['exif:GPSLongitudeRef'] ?? 'E');
            if ($lat && $lng) {
                $result['latitude']  = $lat;
                $result['longitude'] = $lng;
            }

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    // ─── Method 3: PHP exif_read_data ───────────────────────────────────────

    private function extractViaPhpExif(string $sourcePath): array
    {
        if (!function_exists('exif_read_data')) return [];

        try {
            $exif = @exif_read_data($sourcePath, 'EXIF,GPS,IFD0', false);
            if (!$exif) return [];

            $result = [];

            if (!empty($exif['DateTimeOriginal'])) {
                try {
                    $result['taken_at'] = \Carbon\Carbon::createFromFormat('Y:m:d H:i:s', $exif['DateTimeOriginal']);
                } catch (\Throwable) {
                }
            }
            if (!empty($exif['Make']))  $result['camera_make']  = substr($exif['Make'],  0, 100);
            if (!empty($exif['Model'])) $result['camera_model'] = substr($exif['Model'], 0, 100);

            if (!empty($exif['GPSLatitude']) && !empty($exif['GPSLongitude'])) {
                $lat = $this->rationalGps($exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N');
                $lng = $this->rationalGps($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E');
                if ($lat && $lng) {
                    $result['latitude']  = $lat;
                    $result['longitude'] = $lng;
                }
                if (!empty($exif['GPSAltitude'])) {
                    $parts = explode('/', $exif['GPSAltitude']);
                    if (count($parts) === 2 && $parts[1]) {
                        $result['altitude'] = round($parts[0] / $parts[1], 1);
                    }
                }
            }

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    // ─── Method 4: Native binary EXIF parser ────────────────────────────────

    /**
     * Read EXIF GPS directly from binary file data.
     * Works for JPEG, HEIC, and any file embedding a standard EXIF IFD.
     */
    private function extractViaBinaryParser(string $sourcePath, string $ext): array
    {
        try {
            $bytes = file_get_contents($sourcePath, false, null, 0, 65536); // Read first 64 KB
            if (!$bytes) return [];

            // Find EXIF marker in JPEG (FF E1 + "Exif\0\0")
            if (in_array($ext, ['jpg', 'jpeg'])) {
                $pos = strpos($bytes, "\xFF\xE1");
                if ($pos !== false) {
                    $exifStart = $pos + 4; // skip marker + length
                    if (substr($bytes, $exifStart, 6) === "Exif\x00\x00") {
                        return $this->parseExifIfd($bytes, $exifStart + 6);
                    }
                }
            }

            // For HEIC/HEIF — find EXIF in ISOBMFF 'Exif' item
            if (in_array($ext, ['heic', 'heif'])) {
                $pos = strpos($bytes, "Exif\x00\x00");
                if ($pos !== false) {
                    return $this->parseExifIfd($bytes, $pos + 6);
                }
            }

            return [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function parseExifIfd(string $bytes, int $tiffStart): array
    {
        if (strlen($bytes) < $tiffStart + 8) return [];

        // Determine byte order
        $bom = substr($bytes, $tiffStart, 2);
        if ($bom === 'II') {
            $unpack = fn($fmt, $offset) => unpack($fmt, substr($bytes, $tiffStart + $offset, 8))[1] ?? null;
        } elseif ($bom === 'MM') {
            $unpack = fn($fmt, $offset) => unpack(strtoupper($fmt), substr($bytes, $tiffStart + $offset, 8))[1] ?? null;
        } else {
            return [];
        }

        $littleEndian = ($bom === 'II');
        $readShort    = fn($pos) => $littleEndian ? unpack('v', substr($bytes, $pos, 2))[1] : unpack('n', substr($bytes, $pos, 2))[1];
        $readLong     = fn($pos) => $littleEndian ? unpack('V', substr($bytes, $pos, 4))[1] : unpack('N', substr($bytes, $pos, 4))[1];

        // IFD0 offset
        $ifd0Offset = $readLong($tiffStart + 4);
        if (!$ifd0Offset) return [];

        $result   = [];
        $gpsIfd   = null;
        $exifIfd  = null;

        // Parse IFD0
        $pos  = $tiffStart + $ifd0Offset;
        $count = $readShort($pos);
        $pos += 2;

        for ($i = 0; $i < $count && $pos + 12 <= strlen($bytes); $i++, $pos += 12) {
            $tag  = $readShort($pos);
            $type = $readShort($pos + 2);
            $num  = $readLong($pos + 4);
            $valOff = $pos + 8;

            if ($tag === 0x8825) { // GPSInfo IFD pointer
                $gpsIfd = $tiffStart + $readLong($valOff);
            } elseif ($tag === 0x8769) { // ExifIFD pointer
                $exifIfd = $tiffStart + $readLong($valOff);
            } elseif ($tag === 0x010F) { // Make
                $result['camera_make'] = $this->readExifString($bytes, $tiffStart, $num, $valOff, $readLong, $littleEndian);
            } elseif ($tag === 0x0110) { // Model
                $result['camera_model'] = $this->readExifString($bytes, $tiffStart, $num, $valOff, $readLong, $littleEndian);
            }
        }

        // Parse GPS IFD
        if ($gpsIfd) {
            $gps = $this->parseGpsIfd($bytes, $gpsIfd, $tiffStart, $readShort, $readLong, $littleEndian);
            if ($gps) $result = array_merge($result, $gps);
        }

        return $result;
    }

    private function parseGpsIfd(string $bytes, int $offset, int $tiffStart, callable $readShort, callable $readLong, bool $le): array
    {
        if ($offset <= 0 || $offset >= strlen($bytes)) return [];

        $count = $readShort($offset);
        $pos   = $offset + 2;
        $tags  = [];

        for ($i = 0; $i < $count && $pos + 12 <= strlen($bytes); $i++, $pos += 12) {
            $tag     = $readShort($pos);
            $type    = $readShort($pos + 2);
            $num     = $readLong($pos + 4);
            $valOff  = $pos + 8;
            $tags[$tag] = ['type' => $type, 'num' => $num, 'valOff' => $valOff];
        }

        $readRational = function (int $dataOff, int $n) use ($bytes, $readLong, $le): ?float {
            $num = $readLong($dataOff);
            $den = $readLong($dataOff + 4);
            return $den != 0 ? $num / $den : null;
        };

        $getOffset = function (array $tag) use ($tiffStart, $readLong): int {
            return $tiffStart + $readLong($tag['valOff']);
        };

        $result = [];

        // GPS Latitude (tag 0x0002), Ref (0x0001)
        if (isset($tags[2]) && isset($tags[1])) {
            $off = $getOffset($tags[2]);
            $d = $readRational($off, 0);
            $m = $readRational($off + 8, 0);
            $s = $readRational($off + 16, 0);
            if ($d !== null) {
                $lat = $d + ($m ?? 0) / 60 + ($s ?? 0) / 3600;
                $ref = substr($bytes, $tags[1]['valOff'], 1);
                $result['latitude'] = ($ref === 'S') ? -$lat : $lat;
            }
        }

        // GPS Longitude (tag 0x0004), Ref (0x0003)
        if (isset($tags[4]) && isset($tags[3])) {
            $off = $getOffset($tags[4]);
            $d = $readRational($off, 0);
            $m = $readRational($off + 8, 0);
            $s = $readRational($off + 16, 0);
            if ($d !== null) {
                $lng = $d + ($m ?? 0) / 60 + ($s ?? 0) / 3600;
                $ref = substr($bytes, $tags[3]['valOff'], 1);
                $result['longitude'] = ($ref === 'W') ? -$lng : $lng;
            }
        }

        // GPS Altitude (tag 0x0006)
        if (isset($tags[6])) {
            $off = $getOffset($tags[6]);
            $alt = $readRational($off, 0);
            if ($alt !== null) $result['altitude'] = round($alt, 1);
        }

        return $result;
    }

    private function readExifString(string $bytes, int $tiffStart, int $num, int $valOff, callable $readLong, bool $le): string
    {
        $dataOff = ($num > 4) ? $tiffStart + $readLong($valOff) : $valOff;
        return rtrim(substr($bytes, $dataOff, $num), "\x00");
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function parseImagickGps(?string $raw, string $ref): ?float
    {
        if (!$raw) return null;
        $parts = array_map('trim', explode(',', $raw));
        if (count($parts) < 3) return null;
        $f = fn($v) => str_contains($v, '/') ? (float)explode('/', $v)[0] / max(1, (float)explode('/', $v)[1]) : (float)$v;
        $d = $f($parts[0]) + $f($parts[1]) / 60 + $f($parts[2]) / 3600;
        return in_array(strtoupper($ref), ['S', 'W']) ? -$d : $d;
    }

    private function rationalGps(array $c, string $ref): ?float
    {
        if (count($c) < 3) return null;
        $f = fn($v) => is_string($v) && str_contains($v, '/') ? (float)explode('/', $v)[0] / max(1, (float)explode('/', $v)[1]) : (float)$v;
        $d = $f($c[0]) + $f($c[1]) / 60 + $f($c[2]) / 3600;
        return in_array(strtoupper($ref), ['S', 'W']) ? -$d : $d;
    }

    private function discoverExiftool(): string
    {
        return '/usr/bin/exiftool'; // not used anymore, kept for compatibility
    }
}
