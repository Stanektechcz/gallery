<?php

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Ověření druhého faktoru — kód z aplikace, nebo jednorázový obnovovací kód.
 *
 * Jedna implementace pro obě přihlášení: stránku `/login` i token pro
 * aplikaci (`/sanctum/token`). Token se dřív vydal jen proti heslu, takže
 * zapnuté dvoufázové ověření platilo jen ve starém rozhraní a aplikace ho
 * obcházela.
 */
class DruhyFaktor
{
    /** Šest číslic je milion možností; bez limitu je to otázka odpoledne. */
    public const MAX_POKUSU = 5;

    public function __construct(private readonly TotpService $totp) {}

    public function zapnuty(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && (bool) $user->two_factor_secret;
    }

    /** Sekundy do dalšího pokusu, nebo 0. */
    public function blokovano(User $user): int
    {
        $klic = $this->klic($user);

        return RateLimiter::tooManyAttempts($klic, self::MAX_POKUSU) ? RateLimiter::availableIn($klic) : 0;
    }

    public function over(User $user, string $kod): bool
    {
        $kod = trim($kod);

        if ($kod !== '' && ($this->totp->verify((string) $user->two_factor_secret, $kod) || $this->spotrebujObnovovaci($user, $kod))) {
            RateLimiter::clear($this->klic($user));

            return true;
        }

        RateLimiter::hit($this->klic($user), 300);
        AuditLog::record('auth.2fa.failed', $user);

        return false;
    }

    /**
     * Použitý obnovovací kód se odstraní, ne označí: jednorázový kód, který
     * použití přežije, je heslo.
     */
    private function spotrebujObnovovaci(User $user, string $kod): bool
    {
        $otisky = (array) $user->two_factor_recovery_codes;

        foreach ($otisky as $index => $otisk) {
            if (! Hash::check($kod, $otisk)) {
                continue;
            }

            unset($otisky[$index]);
            $user->forceFill(['two_factor_recovery_codes' => array_values($otisky)])->save();
            AuditLog::record('auth.2fa.recovery_used', $user, ['remaining' => count($otisky)]);

            return true;
        }

        return false;
    }

    private function klic(User $user): string
    {
        return 'two-factor:'.$user->id;
    }
}
