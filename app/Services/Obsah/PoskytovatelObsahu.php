<?php

namespace App\Services\Obsah;

use App\Models\GallerySpace;

/**
 * Jedna skupina kolekcí, které prototyp kreslí.
 *
 * Prototyp čte `window.GalerieData` synchronně při vykreslení — na server čekat
 * neumí. Poskytovatel proto dodá hotové řádky přesně v tom tvaru, ve kterém je
 * obrazovka čte; **přizpůsobuje se existující obsah prototypu, ne naopak.**
 */
interface PoskytovatelObsahu
{
    /** Jméno skupiny v adrese `/api/data/{skupina}`. */
    public function skupina(): string;

    /**
     * Kolekce ve tvaru `['TX' => [...], 'BUD' => [...]]`.
     *
     * Prázdná kolekce se **nevrací**: klient si pro ni nechá ukázková data, což
     * je u nezaložené oblasti čitelnější než prázdná obrazovka bez vysvětlení.
     * Vrací se jen to, co v databázi opravdu je.
     *
     * @return array<string, mixed>
     */
    public function kolekce(GallerySpace $prostor): array;
}
