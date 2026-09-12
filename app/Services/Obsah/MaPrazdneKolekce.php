<?php

namespace App\Services\Obsah;

/**
 * Poskytovatel, který umí říct, jak vypadají jeho kolekce **prázdné**.
 *
 * Původní dohoda zněla: prázdná kolekce se neposílá a klient si nechá ukázku
 * z prototypu. Pro přihlášenou dvojici to ale znamenalo cizí život na každé
 * obrazovce, kterou zatím nepoužila — cesty do Lisabonu, kamarádku Kláru,
 * rituály, sliby, dárky. Výchozí hodnoty se proto doplní ke všemu, co
 * poskytovatel neposlal, a klient ukázku smaže.
 *
 * Tvar musí sedět s tím, co obrazovka čte: seznam jako `[]`, mapa podle
 * identifikátorů jako `new \stdClass`, a objekt s pevnými částmi (`BUD`,
 * `FIN`) jako pole se všemi částmi prázdnými — obrazovka pak čte
 * `BUD.cats.map` z prázdného seznamu, ne z `undefined`.
 */
interface MaPrazdneKolekce
{
    /** @return array<string, mixed> */
    public function prazdne(): array;
}
