<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Support\SpaceContext;

/**
 * Trezor, který přišel jako změna stavu.
 *
 * „Dát do trezoru" znamená schovat položku z mřížky, mapy i hledání. Aplikace
 * na to má sloupec `is_hidden` a knihovna ho už respektuje — takže tady nejde
 * o novou tabulku, ale o to, aby se fotka schovala **doopravdy**, ne jen
 * v prohlížeči toho, kdo na tlačítko klikl. Druhý z dvojice ji jinak dál vidí
 * v mřížce, což je u trezoru dost podstatný rozdíl.
 */
class TrezorVeStavu
{
    /** Klíče, které patří databázi. Do stavu se neukládají. */
    public const SERVEROVE = ['vaultAdded', 'vaultRemoved'];

    public function tykaSe(array $patch): bool
    {
        return array_key_exists('vaultAdded', $patch);
    }

    /**
     * `vaultRemoved` zůstává ve stavu.
     *
     * Jsou to identifikátory položek trezoru, které dvojice schovala z jeho
     * seznamu — ne fotek. S trezorem počítaným ze skutečně skrytých položek
     * nemá kam jít, ale zahodit ho by znamenalo, že se skrytá složka vrátí.
     *
     * @return array<string, mixed>
     */
    public function bezTrezoru(array $patch): array
    {
        return array_diff_key($patch, array_flip(['vaultAdded']));
    }

    public function zpracuj(array $patch, GallerySpace $prostor): void
    {
        $vTrezoru = array_values(array_filter(
            (array) ($patch['vaultAdded'] ?? []),
            fn ($id) => is_string($id) && $id !== '',
        ));

        $dotaz = fn () => MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id);

        // Co v seznamu je, patří do trezoru.
        if ($vTrezoru) {
            (clone $dotaz())->whereIn('uuid', $vTrezoru)->update(['is_hidden' => true]);
        }

        /*
         * A co v něm není, se vrací do knihovny.
         *
         * Prototyp posílá celý seznam, takže vyjmutí pozná jen tenhle rozdíl.
         * Týká se to jen položek, které se do trezoru dostaly tudy — skryté
         * odjinud (import, pravidlo) zůstávají skryté.
         */
        (clone $dotaz())
            ->where('is_hidden', true)
            ->when($vTrezoru !== [], fn ($q) => $q->whereNotIn('uuid', $vTrezoru))
            ->update(['is_hidden' => false]);
    }
}
