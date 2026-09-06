<?php

namespace App\Http\Controllers\Api\Galerie\Concerns;

use App\Models\GallerySpace;
use App\Services\Obsah\PoskytovatelObsahu;

/**
 * Odpověď na akci nese obsah — včetně toho, co po ní zbylo prázdné.
 *
 * Poskytovatelé prázdné kolekce **neposílají**: při načtení stránky to je
 * správně, protože prázdná obrazovka a rozbitá aplikace vypadají z pohledu
 * člověka stejně a ukázka je čitelnější. U odpovědi na akci je to ale naopak:
 * když dvojice vrátí poslední položku z koše, mlčení o `TRASH` znamená
 * „nezměnilo se nic" a na obrazovce zůstane řádek, který už neexistuje.
 *
 * Klíče, které poskytovatel dodává **celé** (`uplne()`) a přesto nepřišly, se
 * proto vypíšou zvlášť. Klient je vyprázdní místo toho, aby si nechal staré.
 */
trait VraciObsah
{
    /** @return array{data: array<string, mixed>, prazdne: list<string>} */
    protected function obsahPoAkci(PoskytovatelObsahu $poskytovatel, GallerySpace $prostor): array
    {
        $data = $poskytovatel->kolekce($prostor);

        return [
            'data' => $data,
            'prazdne' => array_values(array_diff($poskytovatel->uplne(), array_keys($data))),
        ];
    }
}
