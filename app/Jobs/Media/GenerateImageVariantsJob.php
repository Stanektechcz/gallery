<?php

namespace App\Jobs\Media;

use App\Models\MediaItem;
use App\Models\UploadSession;
use App\Services\Media\ImageVariantService;
use App\Services\Media\PerceptualHashService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateImageVariantsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 600;

    public function __construct(private readonly int $mediaItemId) {}

    public function handle(ImageVariantService $variantService, PerceptualHashService $hashService): void
    {
        $media = MediaItem::find($this->mediaItemId);
        if (!$media) return;

        $session = UploadSession::where('resulting_media_id', $media->id)->first();
        $path    = $session?->assembled_path;

        if (!$path || !file_exists($path)) {
            $media->update(['status' => 'failed', 'processing_error' => 'Source file missing for variant generation']);
            return;
        }

        $media->update(['processing_stage' => 'generating_variants', 'processing_progress' => 10]);

        try {
            $variantService->generateAll($media, $path);

            // Get dimensions from image if not set
            if (!$media->width) {
                [$w, $h] = @getimagesize($path) ?: [null, null];
                if ($w) $media->update(['width' => $w, 'height' => $h]);
            }

            $media->update(['processing_progress' => 60]);

            // Perceptual hash
            $pHash = $hashService->calculateDHash($path);
            if ($pHash) {
                $media->update(['perceptual_hash' => $pHash]);
            }

            $media->update(['processing_progress' => 80]);

            // Build search text
            $media->load(['tags', 'people', 'places', 'primaryAlbum']);
            $media->rebuildSearchText();

            $media->update([
                'processing_stage'    => 'uploading_to_drive',
                'processing_progress' => 90,
            ]);

            // Queue Drive upload
            InitiateDriveResumableUploadJob::dispatch($media)->onQueue('drive');

        } catch (\Throwable $e) {
            Log::error("Variant generation failed for media #{$media->id}", ['error' => $e->getMessage()]);
            $media->update(['status' => 'failed', 'processing_error' => $e->getMessage()]);
            throw $e;
        }
    }
}
