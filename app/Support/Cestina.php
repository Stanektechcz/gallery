<?php

namespace App\Support;

/**
 * Číslo s podstatným jménem ve tvaru, který k němu patří.
 *
 * Čeština má tři tvary — 1 den, 2 až 4 dny, 5 a víc dní — a napsat do šablony natvrdo
 * jeden z nich znamená, že dva ze tří případů budou špatně. V rozhraní tuhle práci dělá
 * resources/js/lib/cestina.ts; tohle je její protějšek pro texty, které skládá server:
 * upozornění, zprávy pro partnera a výpisy příkazů.
 *
 * Pravidlo bylo v aplikaci třikrát zvlášť — v CycleService, v MoneyRequestRemindersCommand
 * a v BudgetAlertsCommand.
 */
final class Cestina
{
    /**
     * Jen tvar bez čísla. Hodí se tam, kde se s počtem mění i sloveso:
     * `tvar($n, 'položka byla', 'položky byly', 'položek bylo')`.
     *
     * @param string $jeden tvar k jedničce — „den", „položka"
     * @param string $dva   tvar ke dvěma až čtyřem — „dny", „položky"
     * @param string $pet   tvar k pěti a výš a k nule — „dní", „položek"
     */
    public static function tvar(int|float $pocet, string $jeden, string $dva, string $pet): string
    {
        // Desetinné číslo bere v češtině druhý pád jednotného čísla, což je shodou
        // okolností týž tvar jako pro dva až čtyři: „1,5 porce", „2,5 porce".
        if (! is_int($pocet) && floor($pocet) != $pocet) {
            return $dva;
        }

        $n = abs((int) $pocet);

        if ($n === 1) return $jeden;
        if ($n >= 2 && $n <= 4) return $dva;

        return $pet;
    }

    /** Číslo i s tvarem: `pocet(4, 'den', 'dny', 'dní')` → „4 dny". */
    public static function pocet(int|float $pocet, string $jeden, string $dva, string $pet): string
    {
        return $pocet.' '.self::tvar($pocet, $jeden, $dva, $pet);
    }

    /** Nejčastější případ v téhle aplikaci. */
    public static function dny(int $pocet): string
    {
        return self::pocet($pocet, 'den', 'dny', 'dní');
    }

    public static function minuty(int $pocet): string
    {
        return self::pocet($pocet, 'minuta', 'minuty', 'minut');
    }

    public static function polozky(int $pocet): string
    {
        return self::pocet($pocet, 'položka', 'položky', 'položek');
    }
}
