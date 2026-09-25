<?php

namespace Tests\Unit;

use App\Services\Media\VideoProcessingService;
use Tests\TestCase;

class VideoProcessingServiceTest extends TestCase
{
    public function test_it_uses_realistic_frame_rates_and_rejects_transport_time_bases(): void
    {
        $method = new \ReflectionMethod(VideoProcessingService::class, 'parseFrameRate');
        $service = new VideoProcessingService;

        $this->assertSame(29.97, $method->invoke($service, '30000/1001'));
        $this->assertSame(60.0, $method->invoke($service, '60/1'));
        $this->assertNull($method->invoke($service, '90000/1'));
        $this->assertNull($method->invoke($service, '0/0'));
    }

    private function zeSondy(array $ffprobe): array
    {
        return (new \ReflectionMethod(VideoProcessingService::class, 'metadataZeSondy'))
            ->invoke(new VideoProcessingService, $ffprobe);
    }

    /** `creation_time` je UTC — do knihovny patří pražské hodiny, jako u fotek. */
    public function test_cas_z_ffprobe_se_prevede_z_utc_na_hodiny_dvojice(): void
    {
        $meta = $this->zeSondy([
            'format' => ['duration' => '3.5', 'tags' => ['creation_time' => '2026-07-01T22:30:00.000000Z']],
            'streams' => [['codec_type' => 'video', 'width' => 1920, 'height' => 1080, 'codec_name' => 'hevc']],
        ]);

        $this->assertSame('2026-07-02 00:30:00', $meta['taken_at']->format('Y-m-d H:i:s'));
    }

    public function test_apple_creationdate_s_posunem_ma_prednost_pred_utc(): void
    {
        $meta = $this->zeSondy([
            'format' => ['tags' => [
                'creation_time' => '2026-07-01T22:30:07.000000Z',
                'com.apple.quicktime.creationdate' => '2026-07-02T00:30:00+0200',
            ]],
            'streams' => [['codec_type' => 'video', 'width' => 1920, 'height' => 1080]],
        ]);

        $this->assertSame('2026-07-02 00:30:00', $meta['taken_at']->format('Y-m-d H:i:s'));
    }

    /** Video z telefonu na výšku má stopu 1920×1080 a otočení −90°. */
    public function test_otocene_video_ma_prohozene_rozmery(): void
    {
        $meta = $this->zeSondy([
            'format' => [],
            'streams' => [[
                'codec_type' => 'video', 'width' => 1920, 'height' => 1080,
                'side_data_list' => [['side_data_type' => 'Display Matrix', 'rotation' => -90]],
            ]],
        ]);
        $this->assertSame([1080, 1920], [$meta['width'], $meta['height']]);

        $stary = $this->zeSondy([
            'format' => [],
            'streams' => [['codec_type' => 'video', 'width' => 1920, 'height' => 1080, 'tags' => ['rotate' => '270']]],
        ]);
        $this->assertSame([1080, 1920], [$stary['width'], $stary['height']]);
    }
}
