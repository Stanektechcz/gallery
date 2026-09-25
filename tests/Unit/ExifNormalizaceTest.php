<?php

namespace Tests\Unit;

use App\Services\Media\ExifExtractionService;
use Tests\TestCase;

/**
 * Výstup exiftoolu se musí vejít do sloupců `media_items`.
 *
 * MySQL ve striktním režimu odmítne celý `update()` kvůli jediné hodnotě
 * mimo rozsah — a s ní se ztratí datum, GPS i všechno ostatní z fotky.
 * SQLite v testech projde čímkoli, proto se testují samotné hodnoty.
 */
class ExifNormalizaceTest extends TestCase
{
    private function normalizuj(array $raw): array
    {
        $metoda = new \ReflectionMethod(ExifExtractionService::class, 'normalizeExifData');

        return $metoda->invoke(new ExifExtractionService, $raw);
    }

    public function test_hodnoty_mimo_rozsah_sloupcu_zmizi(): void
    {
        $data = $this->normalizuj([
            'DateTimeOriginal' => '2026:07:01 14:51:33',
            'ISO' => 102400,
            'Rating' => -1,
            'Orientation' => 0,
            'Make' => str_repeat('Ž', 150),
            'Model' => str_repeat('M', 140),
            'LensModel' => str_repeat('L', 200),
            'ExposureTime' => 0.00833333333333333333,
            'Title' => str_repeat('T', 600),
            'GPSLatitude' => 50.0755,
            'GPSLongitude' => 14.4378,
        ]);

        $this->assertArrayNotHasKey('iso', $data);
        $this->assertArrayNotHasKey('rating', $data);
        $this->assertArrayNotHasKey('orientation', $data);
        $this->assertSame(100, mb_strlen($data['camera_make']));
        $this->assertSame(100, mb_strlen($data['camera_model']));
        $this->assertSame(150, mb_strlen($data['lens_model']));
        $this->assertLessThanOrEqual(20, mb_strlen((string) $data['shutter_speed']));
        $this->assertSame(512, mb_strlen($data['display_title']));
        // Zbytek fotky přežil.
        $this->assertSame('2026-07-01 14:51:33', $data['taken_at']->format('Y-m-d H:i:s'));
        $this->assertSame(50.0755, $data['latitude']);
        $this->assertSame(14.4378, $data['longitude']);
    }

    public function test_platne_hodnoty_zustanou(): void
    {
        $data = $this->normalizuj(['ISO' => 3200, 'Rating' => 5, 'Orientation' => 6]);

        $this->assertSame(3200, $data['iso']);
        $this->assertSame(5, $data['rating']);
        $this->assertSame(6, $data['orientation']);
    }

    public function test_gps_undef_nebo_nula_neni_poloha(): void
    {
        $this->assertArrayNotHasKey('latitude', $this->normalizuj(['GPSLatitude' => 'undef', 'GPSLongitude' => 'undef']));
        $this->assertArrayNotHasKey('longitude', $this->normalizuj(['GPSLatitude' => 'undef', 'GPSLongitude' => 'undef']));
        $this->assertArrayNotHasKey('latitude', $this->normalizuj(['GPSLatitude' => 0, 'GPSLongitude' => 0]));
        $this->assertArrayNotHasKey('latitude', $this->normalizuj(['GPSLatitude' => 123.0, 'GPSLongitude' => 14.0]));
    }

    public function test_nulove_datum_z_kamery_se_neulozi(): void
    {
        $this->assertArrayNotHasKey('taken_at', $this->normalizuj(['DateTimeOriginal' => '0000:00:00 00:00:00']));
    }

    public function test_quicktime_datum_videa_je_v_utc(): void
    {
        $data = $this->normalizuj([
            'MIMEType' => 'video/quicktime',
            'CreateDate' => '2026:07:01 22:30:00',
            'MediaCreateDate' => '2026:07:01 22:30:00',
        ]);

        $this->assertSame('2026-07-02 00:30:00', $data['taken_at']->format('Y-m-d H:i:s'));
    }

    public function test_apple_creationdate_s_posunem_ma_prednost(): void
    {
        $data = $this->normalizuj([
            'MIMEType' => 'video/quicktime',
            'CreationDate' => '2026:07:02 00:30:00+02:00',
            'CreateDate' => '2026:07:01 22:30:07',
        ]);

        $this->assertSame('2026-07-02 00:30:00', $data['taken_at']->format('Y-m-d H:i:s'));
    }
}
