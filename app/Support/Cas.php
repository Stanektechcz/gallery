<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Čas pro člověka — v pásmu dvojice, ne serveru.
 *
 * Aplikace ukládá okamžiky (`created_at`, smazáno, doručeno…) v UTC, a obsah
 * pro obrazovky je tak i formátoval: zpráva odeslaná v 11:09 ukazovala 9:09,
 * položka v koši „dnes v 20:53" ve 22:53, a těsně po půlnoci patřilo „dnes"
 * ještě ke včerejšku.
 *
 * Jen pro **okamžiky**. Časy zadané podle hodin — začátek akce, pořízení
 * fotky z EXIF, datum zápisu — se ukládají tak, jak je člověk napsal, a
 * převod by je posunul o hodinu či dvě.
 */
final class Cas
{
    public static function pasmo(): string
    {
        return (string) config('app.display_timezone', 'Europe/Prague');
    }

    /** Okamžik v pásmu dvojice; `null` zůstává `null`. */
    public static function mistni(DateTimeInterface|string|int|null $kdy): ?CarbonImmutable
    {
        if ($kdy === null || $kdy === '') {
            return null;
        }

        $cas = is_int($kdy) ? CarbonImmutable::createFromTimestamp($kdy) : CarbonImmutable::parse($kdy);

        return $cas->setTimezone(self::pasmo());
    }

    /**
     * Čas podle hodin (pořízení fotky z EXIF, začátek akce) — jak je zapsaný.
     *
     * Hodnota se nepřevádí, jen se označí pásmem dvojice, aby šla řadit
     * a porovnávat s okamžiky z `mistni()`.
     */
    public static function zHodin(DateTimeInterface|string|null $kdy): ?CarbonImmutable
    {
        if ($kdy === null || $kdy === '') {
            return null;
        }

        return CarbonImmutable::parse($kdy instanceof DateTimeInterface ? $kdy->format('Y-m-d H:i:s') : (string) $kdy, self::pasmo());
    }

    /**
     * Dnešní datum dvojice jako půlnoc v pásmu aplikace.
     *
     * Pro porovnání s daty z databáze (začátek cesty, datum zápisu…), která se
     * čtou jako půlnoc v UTC. Půlnoc v Praze je 22:00 předchozího dne UTC —
     * s ní by počty dní vycházely o jeden menší a v den odjezdu by cesta ještě
     * „plánovala".
     */
    public static function dnes(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::ted()->toDateString());
    }

    /** Teď v pásmu dvojice — pro „dnes", „včera" a počty dní. */
    public static function ted(): CarbonImmutable
    {
        return CarbonImmutable::now(self::pasmo());
    }

    /**
     * Půlnoc dvojice jako okamžik v UTC — pro `WHERE created_at >= …` a další
     * porovnání s okamžiky, které databáze ukládá v UTC.
     *
     * `dnes()` dá jen datum (jako půlnoc UTC, kvůli řazení se sloupci typu
     * DATE); tahle metoda dá skutečný okamžik, kdy dnešek v Praze začal — v
     * létě 22:00 UTC předchozího dne, v zimě 23:00. Bez ní zůstávaly mimo
     * „dnešek" okamžiky z prvních dvou hodin po půlnoci v Praze.
     */
    public static function pulnocUtc(): CarbonImmutable
    {
        return self::ted()->startOfDay()->setTimezone('UTC');
    }

    /**
     * „Teď" na cestě — v pásmu cesty, jinak dvojice.
     *
     * Dny cesty (`trip_days.date`) i časy aktivit jsou místní hodiny; `now()`
     * v UTC by po 22:00 ukazoval ještě včerejší den a program o dvě hodiny posunutý.
     */
    public static function naCeste(?string $pasmo): CarbonImmutable
    {
        try {
            return CarbonImmutable::now($pasmo ?: self::pasmo());
        } catch (\Throwable) {
            return self::ted();
        }
    }
}
