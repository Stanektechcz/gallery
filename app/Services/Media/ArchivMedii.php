<?php

namespace App\Services\Media;

use App\Models\MediaItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Originály jako jeden ZIP — pro album i pro výběr v mřížce.
 *
 * Co aplikace u sebe nemá (originál leží jen na Disku), se do archivu
 * nedostane, ale soupis `CHYBI.txt` to řekne. Jména souborů bez cesty,
 * duplicitní jména s pořadím.
 */
class ArchivMedii
{
    /**
     * @param  Collection<int, MediaItem>  $polozky  s načtenými variantami typu `original`
     * @return BinaryFileResponse|null null, když není ani jeden originál
     */
    public function stahnout(Collection $polozky, string $nazev): ?BinaryFileResponse
    {
        $soubor = tempnam(sys_get_temp_dir(), 'arch_').'.zip';
        $zip = new ZipArchive;
        $zip->open($soubor, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $vlozeno = 0;
        $chybi = [];
        $pouzita = [];

        foreach ($polozky as $m) {
            $original = $m->variants->firstWhere('type', 'original');
            $cesta = $original ? rescue(fn () => Storage::disk($original->disk)->path($original->path), null, false) : null;

            if ($cesta === null || ! is_file($cesta)) {
                $chybi[] = $m->original_filename;

                continue;
            }

            // Jméno bez cesty: „../../x.jpg" z nahrávání by při rozbalení zapsalo
            // mimo složku. Dvě fotky téhož jména by se v archivu přepsaly.
            $jmeno = basename(str_replace('\\', '/', (string) $m->original_filename)) ?: 'soubor';

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

            return null;
        }

        return response()
            ->download($soubor, Str::slug($nazev ?: 'fotky').'.zip')
            ->deleteFileAfterSend();
    }
}
