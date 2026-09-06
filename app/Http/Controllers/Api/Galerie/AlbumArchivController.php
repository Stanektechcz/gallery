<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Api\Galerie\Concerns\UrcujePar;
use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\MediaItem;
use App\Support\SpaceContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Album jako jeden archiv.
 *
 * Tlačítko „Stáhnout" v panelu alba nemělo obsluhu vůbec. Stahovat po jednom
 * nejde: u alba s dvěma sty fotkami by prohlížeč otevřel dvě stě dotazů na
 * uložení a po pár z nich by zbytek zablokoval.
 *
 * Archiv se skládá z **místních originálů**. Co leží jen na Disku a u sebe to
 * aplikace nemá, se do archivu nedostane — místo tichého vynechání je v něm
 * soupis, aby dvojice věděla, co jí chybí a proč.
 */
class AlbumArchivController extends Controller
{
    use UrcujePar;

    /** Nad tímhle počtem se archiv nesestavuje najednou. */
    private const STROP = 500;

    public function __invoke(Request $request, string $album): BinaryFileResponse
    {
        $prostorId = $this->parId($request);

        $radek = Album::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostorId)
            ->where('uuid', $album)
            ->firstOrFail();

        $polozky = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->whereHas('albums', fn ($q) => $q->where('albums.id', $radek->id))
            ->whereNull('trashed_at')
            ->with(['variants' => fn ($q) => $q->where('type', 'original')])
            ->limit(self::STROP)
            ->get();

        $soubor = tempnam(sys_get_temp_dir(), 'alb_').'.zip';
        $zip = new ZipArchive;
        $zip->open($soubor, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $vlozeno = 0;
        $chybi = [];
        $pouzita = [];

        foreach ($polozky as $m) {
            $original = $m->variants->first();
            $cesta = $original ? Storage::disk($original->disk)->path($original->path) : null;

            if ($cesta === null || ! is_file($cesta)) {
                $chybi[] = $m->original_filename;

                continue;
            }

            // Dvě fotky téhož jména by se v archivu přepsaly.
            $jmeno = $m->original_filename;

            if (isset($pouzita[$jmeno])) {
                $pouzita[$jmeno]++;
                $pripona = pathinfo($jmeno, PATHINFO_EXTENSION);
                $zaklad = pathinfo($jmeno, PATHINFO_FILENAME);
                $jmeno = $zaklad.' ('.$pouzita[$jmeno].')'.($pripona ? '.'.$pripona : '');
            } else {
                $pouzita[$jmeno] = 1;
            }

            $zip->addFile($cesta, $jmeno);
            $vlozeno++;
        }

        if ($chybi !== []) {
            $zip->addFromString('CHYBI.txt',
                "Tyhle soubory se do archivu nedostaly — originál u sebe aplikace nemá.\n"
                ."Leží jen na připojeném Disku, nebo se přenos ještě nedokončil.\n\n"
                .implode("\n", $chybi)."\n");
        }

        $zip->close();

        if ($vlozeno === 0) {
            @unlink($soubor);
            abort(404, 'V albu není ani jeden originál, který by aplikace měla u sebe.');
        }

        return response()
            ->download($soubor, Str::slug($radek->title ?: 'album').'.zip')
            ->deleteFileAfterSend();
    }
}
