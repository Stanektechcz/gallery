<?php

namespace App\Jobs\Media;

use App\Models\MediaItem;
use App\Models\UploadSession;
use App\Services\Media\ExifExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractMediaMetadataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(private readonly int $mediaItemId) {}

    public function handle(ExifExtractionService $exifService): void
    {
        $media = MediaItem::find($this->mediaItemId);
        if (!$media) return;

        $session = UploadSession::where('resulting_media_id', $media->id)->first();
        $path    = $session?->assembled_path;

        if (!$path || !file_exists($path)) {
            Log::warning("File not found for EXIF extraction, media #{$media->id}");
            if ($media->media_type === 'video') {
                GenerateVideoPosterJob::dispatch($media->id)->onQueue('media');
            } else {
                GenerateImageVariantsJob::dispatch($media->id)->onQueue('media');
            }
            return;
        }

        $media->update(['processing_stage' => 'extracting_metadata']);

        try {
            $exifData = $exifService->extract($path);

            $updateData = [];

            foreach (['taken_at', 'taken_at_timezone', 'latitude', 'longitude', 'altitude',
                      'camera_make', 'camera_model', 'lens_model', 'iso', 'aperture',
                      'shutter_speed', 'focal_length', 'orientation', 'rating',
                      'description', 'caption', 'display_title'] as $field) {
                if (isset($exifData[$field])) {
                    $updateData[$field] = $exifData[$field];
                }
            }

            // Fill in dimensions if missing
            if (empty($updateData['width']) && isset($exifData['width'])) {
                $updateData['width']  = $exifData['width'];
                $updateData['height'] = $exifData['height'] ?? null;
            }

            $media->update($updateData);

            // Handle XMP keywords → tags (queued separately)
            if (!empty($exifData['xmp_keywords'])) {
                ExtractXmpMetadataJob::dispatch($media->id, $exifData['xmp_keywords'])->onQueue('media');
            }

        } catch (\Throwable $e) {
            Log::warning("EXIF extraction failed for media #{$media->id}", ['error' => $e->getMessage()]);
        }

        // Continue pipeline regardless of EXIF success
        if ($media->media_type === 'photo') {
            GenerateImageVariantsJob::dispatch($media->id)->onQueue('media');
        } else {
            GenerateVideoPosterJob::dispatch($media->id)->onQueue('media');
        }
    }
}
