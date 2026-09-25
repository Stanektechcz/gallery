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
        // `tempnam()` soubor rovnou založí a drží tím jméno; archiv se píše
        // vedle (ZipArchive chce příponu), takže rezervace se na konci smaže —
        // dřív po každém stažení zůstal v dočasné složce prázdný soubor.
        $rezervace = tempnam(sys_get_temp_dir(), 'arch_');
        $soubor = $rezervace.'.zip';

        try {
            return $this->slozit($polozky, $nazev, $soubor);
        } finally {
            @unlink($rezervace);
        }
    }

    /** @param  Collection<int, MediaItem>  $polozky */
    private function slozit(Collection $polozky, string $nazev, string $soubor): ?BinaryFileResponse
    {
        $zip = new ZipArchive;
        $zip->open($soubor, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $vlozeno = 0;
        $chybi = [];
        // Jména, která už v archivu jsou — ta výsledná, ne původní. Malými
        // písmeny: rozbalení na Windows / macOS velikost písmen nerozliší.
        // `CHYBI.txt` je rezervované pro soupis.
        $pouzita = ['chybi.txt' => true];

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

            // Pamatovala se jen původní jména: „a.jpg", „a.jpg", „a (2).jpg"
            // daly dvakrát „a (2).jpg" a jedna fotka se tiše přepsala.
            $jmeno = $this->volneJmeno($jmeno, $pouzita);
            $pouzita[mb_strtolower($jmeno)] = true;

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

        // `download()` odpověď sám přepne na `public` — archiv fotek dvojice
        // ale nesmí uložit sdílená mezipaměť (proxy, firemní síť).
        return response()
            ->download($soubor, Str::slug($nazev ?: 'fotky').'.zip')
            ->setPrivate()
            ->deleteFileAfterSend();
    }

    /**
     * Jméno, které v archivu ještě není: „a.jpg", pak „a (2).jpg", „a (3).jpg"…
     *
     * @param  array<string, true>  $pouzita  obsazená jména malými písmeny
     */
    private function volneJmeno(string $jmeno, array $pouzita): string
    {
        if (! isset($pouzita[mb_strtolower($jmeno)])) {
            return $jmeno;
        }

        $pripona = pathinfo($jmeno, PATHINFO_EXTENSION);
        $zaklad = pathinfo($jmeno, PATHINFO_FILENAME);

        for ($poradi = 2; ; $poradi++) {
            $kandidat = $zaklad.' ('.$poradi.')'.($pripona !== '' ? '.'.$pripona : '');

            if (! isset($pouzita[mb_strtolower($kandidat)])) {
                return $kandidat;
            }
        }
    }
}
