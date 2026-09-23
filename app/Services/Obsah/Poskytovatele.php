<?php

namespace App\Services\Obsah;

/**
 * Seznam poskytovatelů obsahu — jednou, pro server i pro prototyp.
 *
 * Kontroler dat si je nechává vstříknout a prototyp od nich potřebuje ještě
 * jednu věc: **jak vypadají prázdné**. Dokud to věděl jen server, měla
 * přihlášená dvojice na obrazovce ukázku všude, kam data zrovna nedorazila —
 * Chorvatsko 2026, Zadar, cizí sdílené odkazy. Klient si proto tentýž tvar
 * bere odsud, ne z ručně psaného seznamu, který by se rozešel při prvním
 * přidaném klíči.
 */
final class Poskytovatele
{
    /**
     * Pořadí je pořadí odpovědi — kontroler je prochází takhle.
     *
     * @var list<class-string<PoskytovatelObsahu>>
     */
    public const TRIDY = [
        Finance::class,
        Knihovna::class,
        Planovani::class,
        Domacnost::class,
        Cesty::class,
        Vztah::class,
        Zdravi::class,
        Sdileni::class,
        Zpravy::class,
        Kucharka::class,
        Darky::class,
        Denik::class,
        Pravidla::class,
        FinanceRozbory::class,
        Uklid::class,
        System::class,
        Klid::class,
        Pribeh::class,
        Mechanismy::class,
        Rozhodovani::class,
        Tyden::class,
        Dnes::class,
    ];

    /**
     * @return list<PoskytovatelObsahu>
     */
    public static function vsichni(): array
    {
        return array_map(fn (string $trida) => app($trida), self::TRIDY);
    }

    /**
     * Prázdné tvary všech kolekcí pro prototyp.
     *
     * Skládané kolekce (`AL`, `ABARS`, `MOBIL`) dodává víc poskytovatelů
     * najednou, takže se jejich části slučují — jinak by poslední zapsaný
     * přebil ty předchozí a část obrazovek by zůstala na ukázce.
     *
     * @return array<string, mixed>
     */
    public static function prazdneKolekce(): array
    {
        $vse = [];

        foreach (self::vsichni() as $poskytovatel) {
            if (! $poskytovatel instanceof MaPrazdneKolekce) {
                continue;
            }

            foreach ($poskytovatel->prazdne() as $klic => $tvar) {
                $vse[$klic] = isset($vse[$klic]) && is_array($vse[$klic]) && is_array($tvar)
                    ? $vse[$klic] + $tvar
                    : $tvar;
            }
        }

        ksort($vse);

        return $vse;
    }
}
