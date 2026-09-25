<?php

namespace App\Jobs\Media;

use App\Jobs\Media\Concerns\NajdeZdrojMedia;
use App\Models\MediaItem;
use App\Services\Media\ExifExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Druhý krok zpracování (viz CalculateMediaHashesJob): EXIF, pak náhledy.
 *
 * Datum pořízení z EXIFu přepíše `taken_at`, které přišlo z prohlížeče —
 * to je jen čas poslední změny souboru. Úloha běží hned po nahrání, zpravidla
 * dřív, než by datum mohl kdokoli upravit ručně; příznak „datum zadal člověk"
 * knihovna nemá, takže ruční úpravu z té krátké chvíle by EXIF přepsal.
 */
class ExtractMediaMetadataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NajdeZdrojMedia, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(private readonly int $mediaItemId) {}

    public function handle(ExifExtractionService $exifService): void
    {
        $media = MediaItem::find($this->mediaItemId);
        if (! $media) {
            return;
        }

        $path = $this->zdrojovySoubor($media);

        if (! $path) {
            Log::warning("File not found for EXIF extraction, media #{$media->id}");
            if ($media->media_type === 'video') {
                GenerateVideoPosterJob::dispatch($media->id)->onQueue('media');
            } else {
                GenerateImageVariantsJob::dispatch($media->id)->onQueue('media');
            }

            return;
        }

        $media->update(['processing_stage' => 'extracting_metadata']);
        $datumZExif = false;

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

            /*
             * Rozměry jen tam, kde chybí — a otočené.
             *
             * Podmínka se dřív ptala na `$updateData['width']`, které nikdy
             * nebylo nastavené, takže EXIF přepsal rozměry vždy. A EXIF
             * `ImageWidth/Height` jsou rozměry senzoru před otočením: iPhone na
             * výšku (Orientation 6) skončil jako 4032×3024 a mřížka mu dala
             * dlaždici na šířku. Orientace 5–8 a video otočené o ±90° prohodí
             * šířku s výškou.
             */
            if (! $media->width && isset($exifData['width'])) {
                $sirka = $exifData['width'];
                $vyska = $exifData['height'] ?? null;
                $orientace = (int) ($exifData['orientation'] ?? 1);
                $otoceni = abs((int) ($exifData['rotation'] ?? 0)) % 180;
                if ($vyska && (($orientace >= 5 && $orientace <= 8) || $otoceni === 90)) {
                    [$sirka, $vyska] = [$vyska, $sirka];
                }
                $updateData['width'] = $sirka;
                $updateData['height'] = $vyska;
            }

            $media->update($updateData);
            $datumZExif = isset($updateData['taken_at']);

            // Handle XMP keywords → tags (queued separately)
            if (! empty($exifData['xmp_keywords'])) {
                ExtractXmpMetadataJob::dispatch($media->id, $exifData['xmp_keywords'])->onQueue('media');
            }

        } catch (\Throwable $e) {
            Log::warning("EXIF extraction failed for media #{$media->id}", ['error' => $e->getMessage()]);
        }

        // Continue pipeline regardless of EXIF success
        if ($media->media_type === 'photo') {
            GenerateImageVariantsJob::dispatch($media->id)->onQueue('media');
        } else {
            // Bez data z EXIF smí čas z ffprobe nahradit čas z prohlížeče.
            GenerateVideoPosterJob::dispatch($media->id, ! $datumZExif)->onQueue('media');
        }
    }
}
