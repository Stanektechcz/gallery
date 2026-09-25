<?php

namespace App\Support;

use App\Models\User;
use Closure;

/**
 * Adresy provozovatele celé instalace — jediné místo, které je čte.
 *
 * Provozovatele pozná `User::isOperator()` jen podle e-mailu z
 * `gallery.operator_emails` (bez nastavení `gallery.owner_email`). Že adresa
 * opravdu patří tomu, kdo ji zadal, nikdo neověřuje — změna v profilu chce
 * jen vlastní heslo, registrace a pozvánka vezmou jakoukoli adresu. Dokud
 * žádný účet provozovatelskou adresu nedrží, stačilo si ji nastavit a `/admin`
 * se všemi účty, klíči integrací a úlohami byl otevřený.
 *
 * Každé místo, které zapisuje `users.email` ze vstupu, se proto ptá tady.
 * Porovnává se bez mezer a bez ohledu na velikost písmen: SQLite `unique`
 * velikost rozlišuje, takže `OP@…` by vedle existujícího `op@…` prošlo.
 */
final class Provozovatel
{
    /** Hláška záměrně neprozrazuje, že jde o adresu provozovatele. */
    public const HLASKA = 'Tuhle adresu tu použít nejde.';

    /** @return list<string> */
    public static function adresy(): array
    {
        $seznam = (string) (config('gallery.operator_emails') ?: config('gallery.owner_email'));

        return array_values(array_filter(array_map(
            fn (string $e) => mb_strtolower(trim($e)),
            explode(',', $seznam),
        )));
    }

    public static function jeAdresa(?string $email): bool
    {
        $email = mb_strtolower(trim((string) $email));

        return $email !== '' && in_array($email, self::adresy(), true);
    }

    /**
     * Smí `$kdo` tuhle adresu nastavit (sobě nebo novému účtu)?
     *
     * Provozovatel ano — mění si vlastní adresu nebo zve dalšího provozovatele.
     * Kdokoli jiný (i nepřihlášený při registraci) adresu provozovatele nedostane.
     */
    public static function smiNastavit(?string $email, ?User $kdo): bool
    {
        return ! self::jeAdresa($email) || ($kdo !== null && $kdo->isOperator());
    }

    /** Validační pravidlo pro pole s e-mailem — viz `smiNastavit()`. */
    public static function pravidlo(?User $kdo): Closure
    {
        return function (string $pole, mixed $hodnota, Closure $chyba) use ($kdo): void {
            if (! self::smiNastavit(is_string($hodnota) ? $hodnota : null, $kdo)) {
                $chyba(self::HLASKA);
            }
        };
    }
}
