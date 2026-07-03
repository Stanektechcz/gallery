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
use Illuminate\Support\Facades\Log;

class GenerateVideoPosterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 600;

    public function __construct(private readonly int $mediaItemId) {}

    public function handle(VideoProcessingService $videoService): void
    {
        $media = MediaItem::find($this->mediaItemId);
        if (!$media) return;

        $session = UploadSession::where('resulting_media_id', $media->id)->first();
        $path    = $session?->assembled_path;

        if (!$path || !file_exists($path)) {
            $media->update(['status' => 'failed', 'processing_error' => 'Source video missing']);
            return;
        }

        $media->update(['processing_stage' => 'generating_variants']);

        try {
            // Extract video metadata
            $videoMeta = $videoService->extractMetadata($path);
            if (!empty($videoMeta)) {
                $media->update(array_filter($videoMeta));
            }

            // Generate poster
            $videoService->generatePoster($media, $path);

            // Generate compatibility variant
            GenerateVideoCompatibilityVariantJob::dispatch($media)->onQueue('media');

            // Continue to Drive upload
            InitiateDriveResumableUploadJob::dispatch($media)->onQueue('drive');

        } catch (\Throwable $e) {
            Log::error("Video processing failed for media #{$media->id}", ['error' => $e->getMessage()]);
            $media->update(['status' => 'failed', 'processing_error' => $e->getMessage()]);
        }
    }
}
