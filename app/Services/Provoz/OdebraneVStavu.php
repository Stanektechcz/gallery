<?php

namespace App\Services\Provoz;

/**
 * Co prohlížeč ze seznamu výslovně odebral.
 *
 * Seznamy, které se zapisují přes stav (přání, sliby, kapitoly, pravidla…),
 * posílá prohlížeč celé a server z nich mazal, co v nich chybělo. Jenže
 * seznam v prohlížeči je kopie z doby načtení nebo posledního vlastního
 * zápisu: co mezitím přidal ten druhý, v něm chybí — a karta nechaná
 * otevřená přes víkend to při první úpravě smazala.
 *
 * Prohlížeč proto posílá vedle seznamů i `__odebrane`: pro každý klíč
 * identifikátory, které v jeho seznamu byly a už nejsou. Maže se jen to.
 * Starší klient `__odebrane` neposílá a platí pro něj dosavadní chování.
 */
final class OdebraneVStavu
{
    public const KLIC = '__odebrane';

    /**
     * Odebrané identifikátory pro jeden klíč.
     *
     * @return list<string>|null null = prohlížeč rozdíl neposlal (starší klient)
     */
    public static function pro(array $patch, string $klic): ?array
    {
        $vse = $patch[self::KLIC] ?? null;

        if (! is_array($vse)) {
            return null;
        }

        $seznam = $vse[$klic] ?? [];

        if (! is_array($seznam)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($id) => is_scalar($id) ? mb_substr((string) $id, 0, 80) : '', array_slice($seznam, 0, 2000)),
            fn (string $id) => $id !== '',
        ));
    }

    /**
     * Smí se řádek, který v seznamu chybí, vzít za odebraný?
     *
     * Řádek se pozná podle kteréhokoli identifikátoru, pod kterým ho
     * prohlížeč mohl znát: uuid ze serveru, vlastní `client_id`, číslo řádku.
     */
    public static function smi(?array $odebrane, mixed ...$identifikatory): bool
    {
        if ($odebrane === null) {
            return true;
        }

        foreach ($identifikatory as $id) {
            if ($id !== null && $id !== '' && in_array((string) $id, $odebrane, true)) {
                return true;
            }
        }

        return false;
    }
}
