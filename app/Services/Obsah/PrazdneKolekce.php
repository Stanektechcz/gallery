<?php

namespace App\Services\Obsah;

/**
 * Doplní k odpovědi poskytovatele prázdné kolekce, které neposlal.
 *
 * Tři druhy výchozí hodnoty:
 *
 * - **seznam** (`[]`) a **mapa** (`stdClass`) — co poskytovatel poslal, platí
 *   celé; když neposlal nic, pošle se prázdné a klient ukázku smaže,
 * - **objekt s částmi** (neprázdné asociativní pole, třeba `BUD`) — skutečná
 *   data se doplní o části, které poskytovatel nepočítá, aby na klientu
 *   nezůstaly ukázkové a obrazovka nečetla `undefined`.
 *
 * Kolekce, na kterých se skládá víc skupin (`AL`, `ABARS`), se jen doplní po
 * klíčích a nikdy se neoznačí jako úplné — smazaly by, co poslala jiná skupina.
 */
final class PrazdneKolekce
{
    private const SKLADANE = ['AL', 'ABARS', 'MOBIL'];

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: list<string>} data a klíče úplných kolekcí
     */
    public static function doplnit(PoskytovatelObsahu $poskytovatel, array $data): array
    {
        $uplne = $poskytovatel->uplne();

        if (! $poskytovatel instanceof MaPrazdneKolekce) {
            return [$data, $uplne];
        }

        foreach ($poskytovatel->prazdne() as $klic => $vychozi) {
            if (in_array($klic, self::SKLADANE, true)) {
                $data[$klic] = (is_array($data[$klic] ?? null) ? $data[$klic] : []) + (array) $vychozi;

                continue;
            }

            if (! array_key_exists($klic, $data)) {
                $data[$klic] = $vychozi;
            } elseif (is_array($vychozi) && $vychozi !== [] && ! array_is_list($vychozi) && is_array($data[$klic]) && ! array_is_list($data[$klic])) {
                // Objekt s částmi: chybějící části prázdné, ne ukázkové.
                $data[$klic] = $data[$klic] + $vychozi;
            }

            $uplne[] = $klic;
        }

        return [$data, array_values(array_unique($uplne))];
    }
}
