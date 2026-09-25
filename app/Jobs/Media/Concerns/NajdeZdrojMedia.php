<?php

namespace App\Jobs\Media\Concerns;

use App\Models\MediaItem;
use App\Models\UploadSession;
use Illuminate\Support\Facades\Storage;

/**
 * Soubor, ze kterého se médium zpracovává.
 *
 * Starší nahrávání (`UploadController`, import) nechává složený soubor
 * v `UploadSession::assembled_path`. Nahrávání přes prototyp (`/api/media`)
 * relaci nezakládá vůbec a jediným zdrojem je uložený originál — stejně jako
 * u starých nahrávek, jejichž dočasný soubor už uklidil `gallery:clean-temp`.
 */
trait NajdeZdrojMedia
{
    protected function zdrojovySoubor(MediaItem $media): ?string
    {
        $cesta = UploadSession::where('resulting_media_id', $media->id)->value('assembled_path');

        if (is_string($cesta) && is_file($cesta)) {
            return $cesta;
        }

        $original = $media->variants()->where('type', 'original')->first();

        if (! $original) {
            return null;
        }

        try {
            $cesta = Storage::disk($original->disk)->path($original->path);
        } catch (\Throwable) {
            // Vzdálený disk místní cestu nemá.
            return null;
        }

        return is_file($cesta) ? $cesta : null;
    }
}
