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
use Illuminate\Support\Facades\Storage;

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

        // Preview repair must also work for videos uploaded before upload
        // sessions started preserving assembled_path.
        if (!$path || !file_exists($path)) {
            $original = $media->variants()->where('type', 'original')->first();
            $candidate = $original ? Storage::disk($original->disk)->path($original->path) : null;
            $path = $candidate && file_exists($candidate) ? $candidate : null;
        }

        if (!$path || !file_exists($path)) {
            $videoService->generateFallbackPoster($media);
            $media->update(['processing_error' => 'Zdroj videa pro vytvoření náhledu nebyl nalezen.']);
            return;
        }

        $media->update(['processing_stage' => 'generating_variants']);

        try {
            // Extract video metadata
            $videoMeta = $videoService->extractMetadata($path);
            if (!empty($videoMeta)) {
                if (!empty($videoMeta['taken_at']) && !$media->display_title) {
                    $date = \Carbon\Carbon::parse($videoMeta['taken_at'])->locale('cs')->isoFormat('D. M. YYYY');
                    $videoMeta['display_title'] = "Video z {$date}";
                }
                $media->update(array_filter($videoMeta));
            }

            // Generate poster
            $poster = $videoService->generatePoster($media, $path);
            if (!$poster) {
                $videoService->generateFallbackPoster($media);
                if (!$videoService->isAvailable()) {
                    $media->update(['processing_error' => 'Video je uložené, ale server nemá dostupné FFmpeg/FFprobe pro náhled a technické údaje.']);
                }
            }

            // Generate compatibility variant
            GenerateVideoCompatibilityVariantJob::dispatch($media->id)->onQueue('media');

            // Continue to Drive upload
            InitiateDriveResumableUploadJob::dispatch($media->id)->onQueue('drive');

        } catch (\Throwable $e) {
            Log::error("Video processing failed for media #{$media->id}", ['error' => $e->getMessage()]);
            MediaItem::whereKey($media->id)->update(['processing_error' => $e->getMessage()]);
        }
    }
}
