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

    /** Klíč se změněnými položkami (viz zmeneno()). */
    public const ZMENENE = '__zmenene';

    /**
     * Položky seznamu, které prohlížeč změnil proti tomu, co naposledy dostal ze serveru.
     *
     * Převodníky přepisovaly každý řádek, který přišel — starší opis v kartě
     * tak přepsal úpravu, kterou mezitím udělal ten druhý u jiné položky
     * téhož seznamu. Pořadí se počítá do změny (řazení kapitol je úprava).
     *
     * @return list<string>|null null = prohlížeč změny neposlal (přepisuje se vše)
     */
    public static function zmenene(array $patch, string $klic): ?array
    {
        $vse = $patch[self::ZMENENE] ?? null;

        if (! is_array($vse) || ! array_key_exists($klic, $vse) || ! is_array($vse[$klic])) {
            return null;
        }

        return array_values(array_map(fn ($id) => (string) $id, array_filter(array_slice($vse[$klic], 0, 2000), 'is_scalar')));
    }

    /**
     * Smí se vůbec mazat?
     *
     * Převodníky mažou dotazem `->when($zustavaji !== [], whereNotIn)
     * ->when($odebrane !== null, whereIn)`. Když prohlížeč pošle prázdný
     * seznam **a** neřekne, co odebral, vypadnou obě podmínky — a ze `delete()`
     * zbude „smaž všechno v tomhle prostoru". Stačilo by, aby některý seznam
     * jednou přišel prázdný (nenačtená data, chyba v klientovi, starší verze),
     * a dvojice by přišla o celou papírovou zálohu nebo nouzové kontakty.
     *
     * Prázdný seznam se od „nic jsem neodebral" nepozná, takže se v tom případě
     * nemaže nic. Skutečné vyprázdnění prohlížeč pošle s `__odebrane`.
     *
     * @param  list<string>|null  $odebrane
     * @param  list<mixed>  $zustavaji
     */
    public static function smiMazat(?array $odebrane, array $zustavaji): bool
    {
        return $zustavaji !== [] || $odebrane !== null;
    }

    /** Přepsat řádek? Bez seznamu změn ano (starší klient), jinak jen změněný. */
    public static function zmeneno(?array $zmenene, mixed ...$identifikatory): bool
    {
        if ($zmenene === null) {
            return true;
        }

        foreach ($identifikatory as $id) {
            if ($id !== null && $id !== '' && in_array((string) $id, $zmenene, true)) {
                return true;
            }
        }

        return false;
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
