<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Support\SpaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Úklid knihovny, který přišel jako změna stavu.
 *
 * Karanténa i slučování duplicit už kreslí ze skutečné knihovny — jenže
 * rozhodnutí nad nimi končila v prohlížeči. „Pustit" znamenalo, že fotka zmizí
 * ze seznamu tomu, kdo klikl; druhý ji dál viděl v karanténě a originál ležel
 * na disku dál. Tady se to rozhodnutí provede: co se má nechat, se vrátí do
 * knihovny, co se pustí, jde do koše (odkud se dá vrátit), a sloučený nález se
 * uzavře.
 *
 * Zápis je záměrně **idempotentní** — klíče zůstávají ve stavu, takže tentýž
 * seznam přijde i s dalším patchem. Druhé provedení už nemá co změnit.
 */
class UklidVeStavu
{
    public function tykaSe(array $patch): bool
    {
        return array_key_exists('quarGone', $patch) || array_key_exists('dupDone', $patch);
    }

    /**
     * Provede rozhodnutí a vrátí patch se **skutečně** uvolněným místem.
     *
     * `clnFreed` si prototyp počítal sám z toho, co má na obrazovce. Po
     * načtení stránky ta čísla nemá z čeho složit, takže se dosadí to, co
     * z databáze opravdu zmizelo.
     *
     * @return array<string, mixed>
     */
    public function zpracuj(array $patch, GallerySpace $prostor): array
    {
        $this->karantena($patch, $prostor);

        if (array_key_exists('dupDone', $patch)) {
            $patch['clnFreed'] = $this->duplicity($patch, $prostor);
        }

        return $patch;
    }

    /**
     * Karanténa: `{ uuid: 'keep' | 'drop' }`.
     *
     * Ponechat vrací fotku do knihovny; pustit ji posílá do koše. Nemaže se
     * nic — koš má vlastní lhůtu a prototyp na ni má napsané „sedm dní na
     * vrácení".
     *
     * Obě rozhodnutí zároveň **končí karanténu**. Kdyby si puštěná fotka
     * `is_archived` nechala, vrácení z koše by ji vrátilo do fronty otázek,
     * kterou už dvojice zodpověděla — a schovaná by pak byla jen tím, co si
     * o ní pamatuje prohlížeč.
     */
    private function karantena(array $patch, GallerySpace $prostor): void
    {
        $rozhodnuti = (array) ($patch['quarGone'] ?? []);

        $vybrat = fn (string $jak) => array_values(array_filter(
            array_keys($rozhodnuti),
            fn ($id) => is_string($id) && $id !== '' && ($rozhodnuti[$id] ?? null) === $jak,
        ));

        $dotaz = fn () => MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id)
            ->where('is_archived', true);

        if ($nechat = $vybrat('keep')) {
            (clone $dotaz())
                ->whereIn('uuid', $nechat)
                ->update(['is_archived' => false, 'purge_after' => null]);
        }

        if ($pustit = $vybrat('drop')) {
            (clone $dotaz())
                ->whereIn('uuid', $pustit)
                ->update(['is_archived' => false, 'purge_after' => null, 'trashed_at' => now()]);
        }
    }

    /**
     * Sloučení duplicit: `dupDone` jsou uzavřené nálezy, `dupKeep` vítěz.
     *
     * Vítěz je **pořadí** v seznamu, který poslal server — proto se tu čte
     * přesně tímtéž řazením jako v `Knihovna::duplicity()`. Ostatní kopie jdou
     * do koše a nález se uzavře, aby se příště nenabízel znovu.
     *
     * @return float uvolněné místo v MB, spočítané z toho, co šlo do koše
     */
    private function duplicity(array $patch, GallerySpace $prostor): float
    {
        if (! Schema::hasTable('duplicate_groups')) {
            return 0.0;
        }

        $hotove = array_values(array_filter(
            (array) ($patch['dupDone'] ?? []),
            fn ($id) => is_string($id) && $id !== '',
        ));

        $vitezove = (array) ($patch['dupKeep'] ?? []);

        // Dřív uzavřené nálezy se počítají taky — jinak by číslo po načtení
        // stránky spadlo na to, co dvojice stihla v téhle relaci.
        $uvolneno = $this->uvolneno($prostor);

        if (! $hotove) {
            return $uvolneno;
        }

        $skupiny = DB::table('duplicate_groups')
            ->where('gallery_space_id', $prostor->id)
            ->whereNull('resolved_at')
            ->whereIn('uuid', $hotove)
            ->get(['id', 'uuid']);

        foreach ($skupiny as $skupina) {
            $uvolneno += $this->sluc($skupina, $vitezove[$skupina->uuid] ?? null);
        }

        return round($uvolneno, 1);
    }

    /**
     * @param  int|null  $vitez  pořadí vybrané kopie v seznamu ze serveru
     * @return float uvolněné MB
     */
    private function sluc(object $skupina, $vitez): float
    {
        $radky = DB::table('duplicate_group_items as p')
            ->join('media_items as m', 'm.id', '=', 'p.media_item_id')
            ->where('p.duplicate_group_id', $skupina->id)
            ->whereNull('m.trashed_at')
            ->orderByDesc('m.size_bytes')
            ->get(['p.id as vazba', 'm.id as media', 'm.size_bytes']);

        // Nález o jedné položce už nález není — druhá kopie mezitím zmizela.
        if ($radky->count() < 2) {
            return 0.0;
        }

        $nechat = is_int($vitez) && isset($radky[$vitez]) ? $radky[$vitez] : $radky->first();
        $doKose = $radky->reject(fn (object $r) => $r->media === $nechat->media);

        DB::table('duplicate_group_items')
            ->where('duplicate_group_id', $skupina->id)
            ->update(['is_kept' => false, 'updated_at' => now()]);

        DB::table('duplicate_group_items')
            ->where('id', $nechat->vazba)
            ->update(['is_kept' => true, 'updated_at' => now()]);

        DB::table('media_items')
            ->whereIn('id', $doKose->pluck('media'))
            ->update(['trashed_at' => now(), 'updated_at' => now()]);

        DB::table('duplicate_groups')
            ->where('id', $skupina->id)
            ->update(['resolution' => 'merged', 'resolved_at' => now(), 'updated_at' => now()]);

        return round((int) $doKose->sum('size_bytes') / 1_048_576, 1);
    }

    /** Kolik už dvojice uklizením duplicit uvolnila — z uzavřených nálezů. */
    private function uvolneno(GallerySpace $prostor): float
    {
        $bajtu = DB::table('duplicate_group_items as p')
            ->join('duplicate_groups as g', 'g.id', '=', 'p.duplicate_group_id')
            ->join('media_items as m', 'm.id', '=', 'p.media_item_id')
            ->where('g.gallery_space_id', $prostor->id)
            ->where('g.resolution', 'merged')
            ->where('p.is_kept', false)
            ->whereNotNull('m.trashed_at')
            ->sum('m.size_bytes');

        return round((int) $bajtu / 1_048_576, 1);
    }
}
