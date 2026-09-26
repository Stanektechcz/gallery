<?php

namespace App\Support;

use App\Models\User;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token, který dostane zařízení po přihlášení (heslem nebo otiskem).
 *
 * Klient ho drží v `localStorage`, takže ho přečte každý skript, který se do
 * stránky dostane. Dřív platil, dokud se zařízení nepřestalo používat na tři
 * měsíce (`gallery.token_idle_days`) — jednou ukradený token tak otevíral
 * galerii prakticky natrvalo. Teď má vlastní platnost, která se používáním
 * posouvá: dvojice otevírá aplikaci denně a znovu se přihlašovat nemusí,
 * zařízení nepoužité dva měsíce se odhlásí samo a `gallery:uklid-prihlaseni`
 * jeho řádek později smaže.
 *
 * Klíče k API z administrace sem nepatří a platnost nemají — skript zálohy,
 * který běží jednou za čas, se nesmí jednoho dne tiše přestat přihlašovat.
 */
final class PrihlaseniZarizeni
{
    /**
     * Kolik dní od posledního použití přihlášení platí.
     *
     * Šedesát: aplikace se otevírá denně, dva měsíce bez otevření znamenají
     * zapomenuté nebo ztracené zařízení. Kratší lhůta by odhlašovala i telefon
     * odložený na dovolenou.
     */
    public const PLATNOST_DNI = 60;

    /**
     * Značka přihlašovacího tokenu mezi jeho schopnostmi.
     *
     * Posouvat se smí jen platnost, kterou vydalo přihlášení. Jiný sloupec na
     * to tabulka nemá a jméno tokenu si volí klient; bez značky by se posouval
     * i budoucí dočasný klíč s pevnou platností. `*` zůstává, takže oprávnění
     * tokenu se nemění (`tokenCan()` i `JenDvojice` vidí totéž co dřív).
     */
    public const ZNACKA = 'prihlaseni';

    /**
     * Posun nejdřív po dni.
     *
     * Každý náhled i zápis stavu se hlásí týmž tokenem; zápis platnosti při
     * každém požadavku by byl další UPDATE navíc k `last_used_at`. Den přesnosti
     * u šedesátidenní lhůty nikomu nechybí.
     */
    private const POSUN_PO_HODINACH = 24;

    public static function vydej(User $user, string $zarizeni): NewAccessToken
    {
        return $user->createToken($zarizeni, ['*', self::ZNACKA], now()->addDays(self::PLATNOST_DNI));
    }

    /**
     * Použitý token platí znovu šedesát dní — zapíše se nejvýš jednou denně.
     *
     * Token bez platnosti (klíč k API, starší přihlášení) se nechává být: starší
     * přihlašovací token se od klíče skriptu spolehlivě rozeznat nedá, hlídá ho
     * dál limit nečinnosti a s dalším přihlášením ho nahradí token s platností.
     *
     * Zápis je podmíněný tím, co se načetlo. Model přišel od Sanctumu ještě
     * před kontrolou platnosti; kdyby mezitím někdo token zrušil (zrušení
     * nastaví `expires_at` na teď), obyčejné `save()` by zrušení přepsalo
     * na dalších šedesát dní. Model se pak jen srovná s tím, co je v tabulce —
     * Sanctum ho hned potom ukládá kvůli `last_used_at` a nová platnost nesmí
     * odejít podruhé, bez podmínky.
     */
    public static function prodluz(PersonalAccessToken $token): void
    {
        if ($token->expires_at === null || ! in_array(self::ZNACKA, (array) $token->abilities, true)) {
            return;
        }

        $nova = now()->addDays(self::PLATNOST_DNI);

        if ($token->expires_at->gt($nova->copy()->subHours(self::POSUN_PO_HODINACH))) {
            return;
        }

        $zapsano = $token->newQuery()
            ->whereKey($token->getKey())
            ->where('expires_at', $token->getRawOriginal('expires_at'))
            ->where('expires_at', '>', now())
            ->update(['expires_at' => $nova]);

        if ($zapsano === 1) {
            $token->forceFill(['expires_at' => $nova])->syncOriginalAttribute('expires_at');
        }
    }
}
