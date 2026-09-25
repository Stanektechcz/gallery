<?php

namespace App\Jobs\Media;

use App\Jobs\Media\Concerns\NajdeZdrojMedia;
use App\Models\MediaItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * První krok zpracování: otisky → metadata (EXIF) → náhledy / plakát videa.
 *
 * Každý krok zařazuje až ten další, takže celý řetěz stačí spustit jednou
 * odsud — nahrávání přes prototyp i starší `UploadController` to tak dělají.
 */
class CalculateMediaHashesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, NajdeZdrojMedia, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(private readonly int $mediaItemId) {}

    public function handle(): void
    {
        $media = MediaItem::find($this->mediaItemId);
        if (! $media) {
            return;
        }

        $path = $this->zdrojovySoubor($media);

        if (! $path) {
            Log::warning("Assembled file not found for media #{$media->id}");
            // Originál už je úspěšně uložený a zobrazitelný. Selhání doplňkového
            // zpracování proto nesmí skrýt či zneplatnit celé médium.
            $media->update(['processing_error' => 'Zdroj pro doplňkové zpracování nebyl nalezen.']);

            // Řetěz ale jde dál: metadata bez souboru rovnou zařadí náhledy,
            // které si originál umí najít samy. Jinak by fotka zůstala bez náhledu.
            $this->dalsiKrok($media->id);

            return;
        }

        $media->update([
            'processing_stage' => 'hashing',
            'sha256' => hash_file('sha256', $path),
            'md5' => hash_file('md5', $path),
        ]);

        $this->dalsiKrok($media->id);
    }

    /**
     * Ani poslední nepovedený pokus nesmí nechat fotku bez náhledu.
     *
     * Otisky jsou první článek řetězu; dřív se náhledy zařazovaly samostatně,
     * teď visí na něm.
     */
    public function failed(?\Throwable $e): void
    {
        $this->dalsiKrok($this->mediaItemId);
    }

    private function dalsiKrok(int $mediaId): void
    {
        ExtractMediaMetadataJob::dispatch($mediaId)->onQueue('media');
    }
}
