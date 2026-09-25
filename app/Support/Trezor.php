<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Odemčený trezor — patří člověku, ne prohlížeči.
 *
 * Odemčení bylo jen číslo `vault_unlocked_until` v sezení. Přihlášení sezení
 * nečistí (`regenerate()` data nechává, token a otisk na sezení nesahají
 * vůbec), takže kdo se přihlásil do prohlížeče, kde si druhý před chvílí
 * trezor odemkl, měl ho odemčený taky — až patnáct minut, klidně z jiného páru.
 *
 * Vedle času se proto ukládá i kdo odemkl (`vault_unlocked_by`) a trezor je
 * otevřený jen tomu, kdo je právě přihlášený a odemykal. Samotný čas bez
 * člověka (sezení z doby před touhle změnou) neotevře nic. Tohle je jediné
 * místo, které klíče čte a zapisuje: kontroléry, výdej souborů
 * (`ProtectVaultMedia`, `MediaFileController`) i obsah obrazovek (`System`,
 * `Knihovna`) se ptají tady.
 *
 * Požadavek jen s tokenem sezení nemá, a tedy ani odemčený trezor.
 */
final class Trezor
{
    /** Do kdy odemčení platí (unixový čas). */
    public const DO = 'vault_unlocked_until';

    /** Kdo trezor odemkl (id účtu). */
    public const KDO = 'vault_unlocked_by';

    /**
     * Je trezor v tomhle požadavku odemčený pro toho, kdo je přihlášený?
     *
     * `$kdo` jen tam, kde se přihlášený nepozná z výchozího strážce požadavku
     * (výdej souborů bere i token Sanctumu).
     */
    public static function odemcen(?Request $request = null, ?Authenticatable $kdo = null): bool
    {
        return self::zbyva($request, $kdo) > 0;
    }

    /** Kolik sekund odemčení ještě platí; 0 = zamčeno. */
    public static function zbyva(?Request $request = null, ?Authenticatable $kdo = null): int
    {
        $request ??= request();

        if (! $request->hasSession()) {
            return 0;
        }

        $kdo ??= $request->user();
        $sezeni = $request->session();
        $odemkl = (int) $sezeni->get(self::KDO, 0);

        if ($kdo === null || $odemkl === 0 || $odemkl !== (int) $kdo->getAuthIdentifier()) {
            return 0;
        }

        return max(0, (int) $sezeni->get(self::DO, 0) - now()->getTimestamp());
    }

    /** Odemkne na `$sekund` pro právě přihlášeného. */
    public static function odemkni(Request $request, int $sekund): void
    {
        $kdo = $request->user();

        if ($kdo === null || ! $request->hasSession()) {
            return;
        }

        $request->session()->put([
            self::DO => now()->addSeconds($sekund)->getTimestamp(),
            self::KDO => (int) $kdo->getAuthIdentifier(),
        ]);
    }

    /**
     * Zamkne — i při každém přihlášení (heslo, druhý faktor, token, otisk).
     *
     * Kontrola `KDO` by cizí odemčení nepustila i tak; zapomenout ho při
     * přihlášení je druhá pojistka, aby v sezení nezůstalo vůbec.
     */
    public static function zamkni(?Request $request = null): void
    {
        $request ??= request();

        if ($request->hasSession()) {
            $request->session()->forget([self::DO, self::KDO]);
        }
    }
}
