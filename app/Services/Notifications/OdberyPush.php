<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Support\Tabulky;
use Illuminate\Support\Facades\DB;

/**
 * Rušení odběrů upozornění do telefonu (`push_subscriptions`).
 *
 * Odhlášení, „odhlásit ostatní" i odebraný přístup rušily sezení a klíče,
 * odběr ale zůstal — a telefon, který už do aplikace nesmí, dál dostával
 * upozornění, jejichž text je vidět i na zamčené obrazovce.
 *
 * Odběr se hledá podle otisku adresy (`endpoint_hash`), stejně jako při
 * uložení v `CalendarPlanningController::storePushSubscription`, a vždy jen
 * mezi odběry toho, koho se zásah týká: adresou cizího odběru ho nikdo zrušit
 * nesmí.
 */
final class OdberyPush
{
    /** Nejdelší adresa, jakou odběr při uložení přijme. */
    private const DELKA_ADRESY = 2048;

    /** Všechny odběry člověka, volitelně kromě odběru tohoto zařízení. Vrací počet zrušených. */
    public static function zrusVse(User $kdo, mixed $kromeAdresy = null): int
    {
        if (! Tabulky::je('push_subscriptions')) {
            return 0;
        }

        $otisk = self::otisk($kromeAdresy);

        return DB::table('push_subscriptions')
            ->where('user_id', $kdo->id)
            ->when($otisk, fn ($q, $o) => $q->where('endpoint_hash', '!=', $o))
            ->delete();
    }

    /** Odběr jednoho zařízení podle jeho adresy. Nesmyslná adresa nezruší nic. */
    public static function zrus(User $kdo, mixed $adresa): int
    {
        $otisk = self::otisk($adresa);

        if ($otisk === null || ! Tabulky::je('push_subscriptions')) {
            return 0;
        }

        return DB::table('push_subscriptions')
            ->where('user_id', $kdo->id)
            ->where('endpoint_hash', $otisk)
            ->delete();
    }

    /**
     * Otisk adresy z požadavku, nebo `null`.
     *
     * Adresa se jen hašuje, nikam nejde, takže se neověřuje přísně — odhlášení
     * nesmí spadnout na 422 kvůli tomu, co klient přidal navíc.
     */
    private static function otisk(mixed $adresa): ?string
    {
        if (! is_string($adresa) || $adresa === '' || strlen($adresa) > self::DELKA_ADRESY) {
            return null;
        }

        return hash('sha256', $adresa);
    }
}
