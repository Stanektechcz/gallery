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

        if ($kod !== '' && ($this->prijmiKodAplikace($user, $kod) || $this->spotrebujObnovovaci($user, $kod))) {
            RateLimiter::clear($this->klic($user));

            return true;
        }

        RateLimiter::hit($this->klic($user), 300);
        AuditLog::record('auth.2fa.failed', $user);

        return false;
    }

    /**
     * Kód z aplikace platí jednou.
     *
     * Okno ±30 s by jinak pustilo tentýž kód znovu (a po novějším i ten
     * předchozí). Pamatuje se poslední přijatý časový krok a projde jen
     * novější. Zápis je podmíněný, takže ze dvou souběžných požadavků se
     * stejným kódem projde jen jeden.
     */
    private function prijmiKodAplikace(User $user, string $kod): bool
    {
        $krok = $this->totp->matchingStep((string) $user->two_factor_secret, $kod);

        if ($krok === null) {
            return false;
        }

        $prijato = User::query()
            ->whereKey($user->getKey())
            ->where(fn ($q) => $q->whereNull('two_factor_last_step')->orWhere('two_factor_last_step', '<', $krok))
            ->update(['two_factor_last_step' => $krok]);

        if ($prijato !== 1) {
            AuditLog::record('auth.2fa.replayed', $user);

            return false;
        }

        $user->forceFill(['two_factor_last_step' => $krok])->syncOriginalAttribute('two_factor_last_step');

        return true;
    }

    /**
     * Obnovovací kód ve tvaru, ve kterém vznikl (`ABCDE-12345`).
     *
     * Opisuje se z papíru: malými písmeny, s mezerou místo pomlčky nebo bez ní.
     * Uloženy jsou jen haše, takže se porovnat dá jen jeden přesný tvar.
     */
    private function tvarObnovovaciho(string $kod): string
    {
        $kod = strtoupper(preg_replace('/\s+/u', '', strtr($kod, ['–' => '-', '—' => '-'])) ?? '');

        if (! str_contains($kod, '-') && strlen($kod) === 10) {
            $kod = substr($kod, 0, 5).'-'.substr($kod, 5);
        }

        return $kod;
    }

    /**
     * Použitý obnovovací kód se odstraní, ne označí: jednorázový kód, který
     * použití přežije, je heslo.
     */
    private function spotrebujObnovovaci(User $user, string $kod): bool
    {
        $otisky = (array) $user->two_factor_recovery_codes;
        $kod = $this->tvarObnovovaciho($kod);

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
