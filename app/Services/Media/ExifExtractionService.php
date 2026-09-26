<?php

namespace App\Services\Media;

use App\Support\Cas;
use App\Support\Program;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ExifExtractionService
{
    /** exiftool čte hlavičky i u velkého videa za zlomek vteřiny; déle visí jen na rozbitém souboru. */
    private const LIMIT_EXIFTOOLU = 30;

    private string $exiftoolPath;

    public function __construct()
    {
        $this->exiftoolPath = config('gallery.exiftool_path', '/usr/bin/exiftool');
    }

    public function isAvailable(): bool
    {
        // Pod `open_basedir` webového serveru `is_executable()` hází výjimku — viz `Program::lzeSpustit()`.
        return Program::lzeSpustit($this->exiftoolPath);
    }

    /**
     * Extract all relevant EXIF/metadata from a file.
     */
    public function extract(string $filePath): array
    {
        if (! $this->isAvailable()) {
            return $this->fallbackExtract($filePath);
        }

        // Přes `proc_open` a polem argumentů: `shell_exec` je na serveru vypnutý
        // a EXIF z nahrávek (datum, GPS, fotoaparát) tak tiše chyběl.
        $vysledek = Program::spust([$this->exiftoolPath, '-json', '-n', $filePath], self::LIMIT_EXIFTOOLU, 'exiftool', ['path' => $filePath]);
        $output = $vysledek?->output();

        if (! $output) {
            return [];
        }

        $data = json_decode($output, true);
        if (! is_array($data) || empty($data)) {
            return [];
        }

        return $this->normalizeExifData($data[0]);
    }

    private function normalizeExifData(array $raw): array
    {
        $result = [];

        // Taken at (priority order)
        $result['taken_at'] = $this->casPorizeni($raw);
        $result['taken_at_timezone'] = $raw['OffsetTimeOriginal'] ?? $raw['OffsetTime'] ?? null;

        // GPS
        $result['latitude'] = $raw['GPSLatitude'] ?? null;
        $result['longitude'] = $raw['GPSLongitude'] ?? null;
        $result['altitude'] = $raw['GPSAltitude'] ?? null;

        // Camera
        $result['camera_make'] = $raw['Make'] ?? null;
        $result['camera_model'] = $raw['Model'] ?? null;
        $result['lens_model'] = $raw['LensModel'] ?? $raw['Lens'] ?? null;

        // Exposure
        $result['iso'] = $raw['ISO'] ?? null;
        $result['aperture'] = $raw['Aperture'] ?? $raw['FNumber'] ?? null;
        $result['shutter_speed'] = $raw['ShutterSpeed'] ?? $raw['ExposureTime'] ?? null;
        $result['focal_length'] = $raw['FocalLength'] ?? null;
        $result['orientation'] = $raw['Orientation'] ?? null;

        // Dimensions
        $result['width'] = $raw['ImageWidth'] ?? null;
        $result['height'] = $raw['ImageHeight'] ?? null;
        // Video: stopa je uložená na šířku a přehrávač ji otáčí podle `Rotation`.
        $result['rotation'] = $raw['Rotation'] ?? null;

        // Rating and description
        $result['rating'] = $raw['Rating'] ?? null;
        $result['description'] = $raw['ImageDescription'] ?? $raw['Description'] ?? null;
        $result['caption'] = $raw['Caption-Abstract'] ?? null;
        $result['display_title'] = $raw['Title'] ?? null;

        // XMP keywords
        $result['xmp_keywords'] = $this->parseKeywords($raw['Subject'] ?? $raw['Keywords'] ?? null);

        return $this->ocisti($result);
    }

    /**
     * Hodnoty oříznuté na sloupce `media_items`.
     *
     * Úloha zapisuje všechno jedním `update()`. ISO 102 400 (sloupec má
     * smallint), hodnocení −1 („zamítnuto" v Lightroomu) nebo dlouhý název
     * objektivu MySQL ve striktním režimu odmítne — a s nimi i datum, GPS
     * a všechno ostatní z téže fotky. SQLite v testech projde čímkoli, proto
     * se hlídají samotné hodnoty. Co do sloupce nepatří, zmizí; nic se
     * nepřiohýbá na jinou hodnotu, která by vypadala jako skutečná.
     *
     * GPS: exiftool vypíše `undef` pro nulové zlomky a přetypování z toho
     * dělalo 0,0 — fotky se sypaly do Guinejského zálivu. Bere se jen číslo
     * v rozsahu a přesné 0,0 se považuje za „poloha chybí".
     */
    private function ocisti(array $result): array
    {
        $result['iso'] = $this->celeCislo($result['iso'] ?? null, 1, 65535);
        $result['rating'] = $this->celeCislo($result['rating'] ?? null, 0, 5);
        $result['orientation'] = $this->celeCislo($result['orientation'] ?? null, 1, 8);
        $result['width'] = $this->celeCislo($result['width'] ?? null, 1, 1_000_000);
        $result['height'] = $this->celeCislo($result['height'] ?? null, 1, 1_000_000);
        $result['rotation'] = $this->celeCislo($result['rotation'] ?? null, -360, 360);

        $sirka = $this->desetinne($result['latitude'] ?? null, -90, 90);
        $delka = $this->desetinne($result['longitude'] ?? null, -180, 180);
        if ($sirka === null || $delka === null || ($sirka == 0.0 && $delka == 0.0)) {
            $sirka = $delka = null;
        }
        $result['latitude'] = $sirka;
        $result['longitude'] = $delka;
        $result['altitude'] = $sirka === null ? null : $this->desetinne($result['altitude'] ?? null, -99_999, 99_999);

        foreach (self::DELKY_SLOUPCU as $sloupec => $delkaSloupce) {
            $result[$sloupec] = $this->text($result[$sloupec] ?? null, $delkaSloupce);
        }
        foreach (['description', 'caption'] as $sloupec) {
            $result[$sloupec] = $this->dlouhyText($result[$sloupec] ?? null);
        }

        if (isset($result['xmp_keywords'])) {
            $result['xmp_keywords'] = array_values(array_filter(array_map(
                fn ($slovo) => $this->text($slovo, 100),
                $result['xmp_keywords'],
            )));
        }

        return array_filter($result, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /** Délky textových sloupců `media_items` (migrace 2024_01_01_000050). */
    private const DELKY_SLOUPCU = [
        'taken_at_timezone' => 64,
        'camera_make' => 100,
        'camera_model' => 100,
        'lens_model' => 150,
        'aperture' => 20,
        'shutter_speed' => 20,
        'focal_length' => 20,
        'display_title' => 512,
    ];

    private function celeCislo(mixed $hodnota, int $od, int $do): ?int
    {
        if (is_array($hodnota)) {
            $hodnota = reset($hodnota);
        }
        if (! is_numeric($hodnota)) {
            return null;
        }
        $cislo = (int) round((float) $hodnota);

        return $cislo >= $od && $cislo <= $do ? $cislo : null;
    }

    private function desetinne(mixed $hodnota, float $od, float $do): ?float
    {
        if (! is_numeric($hodnota)) {
            return null;
        }
        $cislo = (float) $hodnota;

        return is_finite($cislo) && $cislo >= $od && $cislo <= $do ? $cislo : null;
    }

    private function text(mixed $hodnota, int $znaku): ?string
    {
        if (! is_scalar($hodnota) || is_bool($hodnota)) {
            return null;
        }
        $text = trim((string) $hodnota);
        if ($text === '' || ! mb_check_encoding($text, 'UTF-8')) {
            return null;
        }

        return mb_substr($text, 0, $znaku);
    }

    /** `TEXT` v MySQL má 65 535 bajtů, ne znaků. */
    private function dlouhyText(mixed $hodnota): ?string
    {
        $text = $this->text($hodnota, 65_535);

        return $text === null ? null : mb_strcut($text, 0, 65_535, 'UTF-8');
    }

    /**
     * Datum pořízení: fotka podle hodin, video z UTC na pražské hodiny.
     *
     * U fotek je `DateTimeOriginal` čas z hodin fotoaparátu a ukládá se, jak
     * je. QuickTime (`CreateDate`, `MediaCreateDate`) ale zapisuje UTC — video
     * natočené v Praze ve 0:30 mělo v knihovně 22:30 předchozího dne. Přednost
     * má Apple `CreationDate`, které nese i posun (`+02:00`): jeho hodiny
     * jsou přesně ty, co ukazoval telefon.
     */
    private function casPorizeni(array $raw): ?Carbon
    {
        if ($foto = self::rozumneDatum($raw['DateTimeOriginal'] ?? null)) {
            return $foto;
        }

        $video = str_starts_with(strtolower((string) ($raw['MIMEType'] ?? '')), 'video/')
            || isset($raw['MediaCreateDate'])
            || isset($raw['TrackCreateDate']);

        if ($video) {
            return self::hodinyVidea($raw['CreationDate'] ?? null, $raw['CreateDate'] ?? $raw['MediaCreateDate'] ?? null);
        }

        return self::rozumneDatum($raw['CreateDate'] ?? null);
    }

    /**
     * Čas videa jako hodiny dvojice (tak, jak se ukládají fotky z EXIF).
     *
     * @param  string|null  $sPosunem  značka s vlastním posunem (Apple `creationdate`) — bere se její místní čas
     * @param  string|null  $vUtc  `creation_time` / QuickTime `CreateDate` — UTC, převede se do `Cas::pasmo()`
     */
    public static function hodinyVidea(?string $sPosunem, ?string $vUtc): ?Carbon
    {
        if ($mistni = self::rozumneDatum($sPosunem)) {
            return $mistni;
        }

        $utc = self::rozumneDatum($vUtc, 'UTC', false);

        return $utc ? Carbon::parse($utc->setTimezone(Cas::pasmo())->format('Y-m-d H:i:s')) : null;
    }

    /**
     * Datum, které se vejde do sloupce `TIMESTAMP` (1970–2038).
     *
     * Kamery bez nastavených hodin píšou `0000:00:00 00:00:00`; Carbon z toho
     * udělá rok −1 a MySQL update odmítne. Vrací hodiny zápisu v pásmu
     * aplikace, s `$hodiny = false` okamžik v původním pásmu.
     */
    private static function rozumneDatum(mixed $text, ?string $pasmo = null, bool $hodiny = true): ?Carbon
    {
        if (! is_string($text) || trim($text) === '' || str_starts_with(trim($text), '0000')) {
            return null;
        }

        try {
            $cas = Carbon::parse(trim($text), $pasmo);
        } catch (\Throwable) {
            return null;
        }

        if ($cas->year < 1971 || $cas->year > 2037) {
            return null;
        }

        return $hodiny ? Carbon::parse($cas->format('Y-m-d H:i:s')) : $cas;
    }

    private function parseKeywords(mixed $keywords): array
    {
        if (is_array($keywords)) {
            return array_values(array_filter($keywords));
        }
        if (is_string($keywords)) {
            return array_map('trim', explode(',', $keywords));
        }

        return [];
    }

    /**
     * Fallback using PHP's built-in EXIF reader for JPEG.
     */
    private function fallbackExtract(string $filePath): array
    {
        if (! function_exists('exif_read_data')) {
            return [];
        }

        try {
            $exif = @exif_read_data($filePath, null, true);
            if (! $exif) {
                return [];
            }

            $result = [];
            $ifd0 = $exif['IFD0'] ?? [];
            $exifSub = $exif['EXIF'] ?? [];
            $gps = $exif['GPS'] ?? [];

            $result['camera_make'] = $ifd0['Make'] ?? null;
            $result['camera_model'] = $ifd0['Model'] ?? null;
            $result['description'] = $ifd0['ImageDescription'] ?? null;
            $result['orientation'] = isset($ifd0['Orientation']) ? (int) $ifd0['Orientation'] : null;
            $result['iso'] = $exifSub['ISOSpeedRatings'] ?? null;
            $result['shutter_speed'] = $exifSub['ExposureTime'] ?? null;

            if ($dateStr = ($exifSub['DateTimeOriginal'] ?? null)) {
                $result['taken_at'] = self::rozumneDatum($dateStr);
            }

            if (! empty($gps)) {
                $result['latitude'] = $this->gpsToDecimal($gps['GPSLatitude'] ?? null, $gps['GPSLatitudeRef'] ?? 'N');
                $result['longitude'] = $this->gpsToDecimal($gps['GPSLongitude'] ?? null, $gps['GPSLongitudeRef'] ?? 'E');
            }

            return $this->ocisti($result);
        } catch (\Throwable $e) {
            Log::warning('Fallback EXIF extraction failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function gpsToDecimal(?array $gps, string $ref): ?float
    {
        if (! $gps || count($gps) < 3) {
            return null;
        }

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
        if (! $this->isAvailable()) {
            return false;
        }

        $sidecarPath = $mediaPath.'.xmp';
        $args = [];

        // Hodnoty bez `escapeshellarg`: argumenty jdou programu přímo, bez
        // shellu, a uvozovky by se do XMP zapsaly doslova (`'léto'`).
        if (isset($metadata['description'])) {
            $args[] = '-Description='.$metadata['description'];
        }
        if (isset($metadata['rating'])) {
            $args[] = '-Rating='.(int) $metadata['rating'];
        }
        if (isset($metadata['latitude'], $metadata['longitude'])) {
            $args[] = '-GPSLatitude='.$metadata['latitude'];
            $args[] = '-GPSLongitude='.$metadata['longitude'];
        }
        if (! empty($metadata['tags'])) {
            foreach ($metadata['tags'] as $tag) {
                $args[] = '-Subject+='.$tag;
            }
        }

        if (empty($args)) {
            return true;
        }

        return Program::spust(
            [$this->exiftoolPath, ...$args, '-o', $sidecarPath, $mediaPath],
            self::LIMIT_EXIFTOOLU,
            'exiftool (XMP sidecar)',
            ['path' => $mediaPath],
        ) !== null;
    }
}
