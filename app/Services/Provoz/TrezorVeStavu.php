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
    public const SERVEROVE = ['vaultAdded', 'vaultRemoved', 'vaultVyjmout'];

    public function tykaSe(array $patch): bool
    {
        return array_key_exists('vaultAdded', $patch) || array_key_exists('vaultVyjmout', $patch);
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
        return array_diff_key($patch, array_flip(['vaultAdded', 'vaultVyjmout']));
    }

    public function zpracuj(array $patch, GallerySpace $prostor, bool $odemceno = false): void
    {
        $idcka = fn (string $klic) => array_values(array_filter(
            array_slice((array) ($patch[$klic] ?? []), 0, 5000),
            fn ($id) => is_string($id) && $id !== '',
        ));

        $vTrezoru = $idcka('vaultAdded');
        $vyjmout = array_values(array_diff($idcka('vaultVyjmout'), $vTrezoru));

        $dotaz = fn () => MediaItem::withoutGlobalScope(SpaceContext::SCOPE)
            ->where('gallery_space_id', $prostor->id);

        // Co v seznamu je, patří do trezoru.
        if ($vTrezoru) {
            (clone $dotaz())->whereIn('uuid', $vTrezoru)->update(['is_hidden' => true]);
        }

        /*
         * Vrací se jen to, co prohlížeč výslovně vyjmul.
         *
         * Dřív se vrátilo všechno skryté, co v odeslaném seznamu chybělo.
         * Jenže obsah trezoru chodí jen s odemčeným trezorem a nejvýš
         * dvě stě položek: „Do trezoru" u zamčeného trezoru nebo z druhého
         * zařízení tak vrátilo celý trezor do mřížky, hledání i sdílených
         * odkazů.
         */
        if ($vyjmout && $odemceno) {
            (clone $dotaz())->where('is_hidden', true)->whereIn('uuid', $vyjmout)->update(['is_hidden' => false]);
        }
    }
}
