<?php

namespace Tests\Unit;

use App\Services\Media\FilenameMetadataService;
use Tests\TestCase;

class FilenameMetadataServiceTest extends TestCase
{
    public function test_it_derives_czech_video_title_and_date_from_compact_camera_filename(): void
    {
        $metadata = (new FilenameMetadataService())->infer('20260627.mp4', 'video');

        $this->assertSame('2026-06-27', $metadata['taken_at']->toDateString());
        $this->assertSame('Video z 27. 6. 2026', $metadata['display_title']);
    }

    public function test_it_accepts_delimited_dates_but_rejects_invalid_calendar_dates(): void
    {
        $service = new FilenameMetadataService();

        $this->assertSame('2026-06-27', $service->infer('VID_2026-06-27_153012.mov', 'video')['taken_at']->toDateString());
        $this->assertSame([], $service->infer('20260231.mp4', 'video'));
        $this->assertSame([], $service->infer('holiday.mp4', 'video'));
    }
}
