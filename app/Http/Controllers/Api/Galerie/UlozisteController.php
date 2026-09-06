<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Api\Galerie\Concerns\VraciObsah;
use App\Http\Controllers\Controller;
use App\Jobs\Media\EnqueueDriveMediaSyncJob;
use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Services\Obsah\System;
use App\Services\Storage\DriveConnectionResolver;
use App\Support\SpaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * „Zkusit znovu" u nepřenesených originálů.
 *
 * Tlačítko na obrazovce úložiště dosud nemělo obsluhu vůbec — dalo se na ně
 * klikat a nedělo se nic. U zálohy je to nejhorší možné chování: dvojice
 * klikne, nic se nestane, a ona si myslí, že se přenos rozjel.
 */
class UlozisteController extends Controller
{
    use UrcujePar;
    use VraciObsah;

    public function __construct(
        private readonly DriveConnectionResolver $disky,
        private readonly System $obsah,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $prostor = GallerySpace::findOrFail($this->parId($request));

        // Bez připojeného Disku se nemá kam kopírovat. Zařadit to do fronty by
        // znamenalo slíbit přenos, který nikdy neproběhne.
        if ($this->disky->forSpace($prostor->id) === null) {
            return response()->json([
                'ok' => false,
                'zprava' => 'Google Disk není připojený — originály se nemají kam zkopírovat.',
            ], 422);
        }

        $ceka = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->whereNull('drive_file_id')
            ->count();

        if ($ceka === 0) {
            return response()->json([
                'ok' => true,
                'zprava' => 'Všechny originály už na Disku jsou.',
                'data' => $this->obsah->kolekce($prostor),
            ]);
        }

        // Chyba zpracování se maže: jinak by položka zůstala „nepodařilo se"
        // i po povedeném přenosu a varovný pruh by nezmizel nikdy.
        MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('trashed_at')
            ->whereNull('drive_file_id')
            ->whereNotNull('processing_error')
            ->update(['processing_error' => null]);

        EnqueueDriveMediaSyncJob::dispatch($prostor->id)->onQueue('drive');

        return response()->json([
            'ok' => true,
            'zprava' => $ceka.' '.$this->sklonuj($ceka, 'originál je ve frontě', 'originály jsou ve frontě', 'originálů je ve frontě')
                .' — přenos běží na pozadí',
        ] + $this->obsahPoAkci($this->obsah, $prostor));
    }

    private function sklonuj(int $kolik, string $jeden, string $dva, string $pet): string
    {
        return match (true) {
            $kolik === 1 => $jeden,
            $kolik >= 2 && $kolik <= 4 => $dva,
            default => $pet,
        };
    }
}
