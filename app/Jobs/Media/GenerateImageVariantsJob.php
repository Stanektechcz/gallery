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
use Illuminate\Support\Facades\Storage;

class GenerateImageVariantsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 600;

    public function __construct(private readonly int $mediaItemId) {}

    public function handle(): void
    {
        $media = MediaItem::find($this->mediaItemId);
        if (!$media) return;

        $session = UploadSession::where('resulting_media_id', $media->id)->first();
        $path    = $session?->assembled_path;

        // Older uploads do not retain their temporary assembled upload. Their
        // local original is still enough to regenerate a missing preview.
        if (!$path || !file_exists($path)) {
            $original = $media->variants()->where('type', 'original')->first();
            $candidate = $original ? Storage::disk($original->disk)->path($original->path) : null;
            $path = $candidate && file_exists($candidate) ? $candidate : null;
        }

        if (!$path || !file_exists($path)) {
            $media->update(['processing_error' => 'Zdroj pro vytvoření variant nebyl nalezen.']);
            return;
        }

        $media->update(['processing_stage' => 'generating_variants', 'processing_progress' => 10]);

        try {
            // Služby závislé na GD/Imagick řešíme až uvnitř try. Pokud na
            // serveru nejsou, uložený originál zůstává bezchybný a dostupný.
            $variantService = app(ImageVariantService::class);
            $hashService = app(PerceptualHashService::class);
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
            InitiateDriveResumableUploadJob::dispatch($media->id)->onQueue('drive');

        } catch (\Throwable $e) {
            Log::error("Variant generation failed for media #{$media->id}", ['error' => $e->getMessage()]);
            // Původní soubor i základní náhled už jsou uložené. Varianty jsou
            // optimalizace, nikoliv podmínka pro zobrazení média v albu.
            $media->update(['processing_error' => $e->getMessage()]);
        }
    }
}
