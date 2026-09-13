<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\MediaItem;
use App\Services\Media\ArchivMedii;
use App\Support\SpaceContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Album jako jeden archiv.
 *
 * Tlačítko „Stáhnout" v panelu alba nemělo obsluhu. Stahovat po jednom nejde —
 * u alba s dvěma sty fotkami by prohlížeč po pár souborech zbytek zablokoval.
 * Archiv skládá `ArchivMedii` (stejně jako pro výběr v mřížce).
 */
class AlbumArchivController extends Controller
{
    use UrcujePar;

    /** Víc fotek najednou archiv neponese — server by ho skládal minuty. */
    private const STROP = 500;

    public function __invoke(Request $request, string $album, ArchivMedii $archiv): BinaryFileResponse
    {
        $prostorId = $this->parId($request);

        $radek = Album::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostorId)
            ->where('uuid', $album)
            ->firstOrFail();

        $polozky = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->whereHas('albums', fn ($q) => $q->where('albums.id', $radek->id))
            ->where('gallery_space_id', $prostorId)
            ->whereNull('trashed_at')
            // Fotka v trezoru se do archivu nedostane: stažené album by ji
            // vyneslo mimo zámek, i když je trezor zamčený.
            ->where('is_hidden', false)
            ->with(['variants' => fn ($q) => $q->where('type', 'original')])
            ->limit(self::STROP)
            ->get();

        $odpoved = $archiv->stahnout($polozky, $radek->title ?: 'album');

        abort_if($odpoved === null, 404, 'V albu není ani jeden originál, který by aplikace měla u sebe.');

        return $odpoved;
    }
}
