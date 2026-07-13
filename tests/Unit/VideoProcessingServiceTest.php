<?php

namespace Tests\Unit;

use App\Services\Media\VideoProcessingService;
use Tests\TestCase;

class VideoProcessingServiceTest extends TestCase
{
    public function test_it_uses_realistic_frame_rates_and_rejects_transport_time_bases(): void
    {
        $method = new \ReflectionMethod(VideoProcessingService::class, 'parseFrameRate');
        $service = new VideoProcessingService();

        $this->assertSame(29.97, $method->invoke($service, '30000/1001'));
        $this->assertSame(60.0, $method->invoke($service, '60/1'));
        $this->assertNull($method->invoke($service, '90000/1'));
        $this->assertNull($method->invoke($service, '0/0'));
    }
}
