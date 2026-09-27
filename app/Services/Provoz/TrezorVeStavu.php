<?php

namespace App\Services\Provoz;

use App\Models\GallerySpace;
use App\Models\MediaItem;
use App\Services\Media\KopieTrezoru;
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

    public function __construct(private readonly KopieTrezoru $kopie) {}

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

        /*
         * Co v seznamu je, patří do trezoru.
         *
         * Hromadný `update()` nespouští události modelu, takže id položek, které
         * se opravdu mění, se berou **předem** — a jen jim se odeberou kopie
         * v cloudu („Fotky z trezoru nejdou na cloud"). Seznam chodí celý
         * pořád dokola; položka, která v trezoru už byla, se znovu nezpracuje.
         */
        if ($vTrezoru) {
            $nove = (clone $dotaz())->whereIn('uuid', $vTrezoru)->where('is_hidden', false)->pluck('id')->all();

            if ($nove) {
                (clone $dotaz())->whereIn('id', $nove)->update(['is_hidden' => true]);
                $this->kopie->vlozeno($nove);
            }
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
            $vracene = (clone $dotaz())->where('is_hidden', true)->whereIn('uuid', $vyjmout)->pluck('id')->all();

            if ($vracene) {
                (clone $dotaz())->whereIn('id', $vracene)->update(['is_hidden' => false]);
                // Mimo trezor se zrcadlí zase jako ostatní.
                $this->kopie->vyjmuto($vracene);
            }
        }
    }
}
