<?php

namespace App\Jobs\Media;

use App\Models\MediaItem;
use App\Models\UploadSession;
use App\Services\Media\VideoProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateVideoCompatibilityVariantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 1800; // 30 minutes for large videos

    public function __construct(private readonly int $mediaItemId) {}

    public function handle(VideoProcessingService $videoService): void
    {
        $media = MediaItem::find($this->mediaItemId);
        if (!$media) return;

        $session = UploadSession::where('resulting_media_id', $media->id)->first();
        $path    = $session?->assembled_path;

        if (!$path || !file_exists($path)) return;

        $videoService->generateCompatibilityVariant($media, $path);
    }
}
