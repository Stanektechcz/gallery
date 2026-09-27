<?php

namespace App\Services\Hledani;

use App\Models\MediaItem;
use App\Support\SpaceContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Přepočet `search_text` pro dávku fotek — konstantním počtem dotazů.
 *
 * `MediaItem::rebuildSearchText()` bere jednu fotku a zapisuje ji zvlášť;
 * po přejmenování štítku na tisíci fotkách by to byly tisíce dotazů na
 * vazby a tisíce zápisů. Tady se vazby načtou naráz pro celou dávku a změněné
 * texty se zapíšou jedním `UPDATE … CASE id WHEN … END`.
 *
 * Zápis jde mimo model: žádné události (nic dalšího se nespustí) a `updated_at`
 * se nemění — hledaný text je odvozený údaj, ne úprava fotky. Podle
 * `updated_at` se přitom pozná zaseknuté nahrávání na Disk.
 */
class ObnovaHledani
{
    /** Kolik fotek se načte a zapíše najednou. */
    public const DAVKA = 500;

    /** Kolik řádků nese jeden `UPDATE` (dvě vazební hodnoty na řádek). */
    private const RADKU_NA_ZAPIS = 200;

    /** Vazby, ze kterých se text skládá (`MediaItem::hledanyText()`). */
    public const VAZBY = ['primaryAlbum', 'albums', 'tags', 'people', 'places'];

    /**
     * @param  iterable<int|string>  $idFotek
     * @return int kolik fotek dostalo nový text
     */
    public function obnov(iterable $idFotek): int
    {
        $id = collect($idFotek)->map(fn ($i) => (int) $i)->filter()->unique()->values();
        $zmeneno = 0;

        foreach ($id->chunk(self::DAVKA) as $davka) {
            $media = MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
                ->whereIn('id', $davka->all())
                ->with(self::VAZBY)
                ->get();

            $zmeneno += $this->zapis($media);
        }

        return $zmeneno;
    }

    /**
     * Jedna fotka, kterou volající drží v paměti (úlohy zpracování nahrané fotky).
     *
     * Vazby se načtou znovu (`load`, ne `loadMissing`): úloha mohla mezitím
     * přidat štítky a načtená vazba by byla stará. Model v paměti dostane nový
     * text bez příznaku změny, takže pozdější `update()` ho nepřepíše ničím
     * starým — a `updated_at` zůstává, jak byl (viz popis třídy).
     */
    public function obnovJednu(MediaItem $media): void
    {
        $media->load(self::VAZBY);
        $text = $media->hledanyText();

        if ($text !== (string) $media->search_text) {
            $this->zapisDavku([(int) $media->id => $text]);
        }

        $media->setAttribute('search_text', $text);
        $media->syncOriginalAttribute('search_text');
    }

    /**
     * Texty pro už načtené fotky (vazby z `VAZBY` načtené dopředu).
     *
     * @param  Collection<int, MediaItem>  $media
     */
    public function zapis(Collection $media): int
    {
        $nove = [];

        foreach ($media as $m) {
            $text = $m->hledanyText();

            if ($text !== (string) $m->search_text) {
                $nove[(int) $m->id] = $text;
            }
        }

        foreach (array_chunk($nove, self::RADKU_NA_ZAPIS, true) as $cast) {
            $this->zapisDavku($cast);
        }

        return count($nove);
    }

    /** @param  array<int, string>  $texty  `id => text` */
    private function zapisDavku(array $texty): void
    {
        $gramatika = DB::connection()->getQueryGrammar();
        $tabulka = $gramatika->wrapTable((new MediaItem)->getTable());
        $idSloupec = $gramatika->wrap('id');
        $vazby = [];

        foreach ($texty as $id => $text) {
            array_push($vazby, $id, $text);
        }

        $sql = "UPDATE {$tabulka} SET ".$gramatika->wrap('search_text')
            ." = CASE {$idSloupec} ".str_repeat('WHEN ? THEN ? ', count($texty)).'END'
            ." WHERE {$idSloupec} IN (".implode(', ', array_fill(0, count($texty), '?')).')';

        DB::update($sql, array_merge($vazby, array_keys($texty)));
    }
}
