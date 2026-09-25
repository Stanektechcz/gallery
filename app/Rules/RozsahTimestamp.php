<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;

/**
 * Datum v rozsahu sloupce MySQL `TIMESTAMP`.
 *
 * MySQL `TIMESTAMP` zvládá jen 1970-01-01 00:00:01 až 2038-01-19 03:14:07 UTC —
 * mimo rozsah vrátí chybu při zápisu (500), zatímco SQLite (testy) přijme
 * cokoli. Hranice dne od data i do data schválně necháváme o den užší, aby
 * převod mezi časovými pásmy nepřehodil datum přes okraj rozsahu.
 */
class RozsahTimestamp implements ValidationRule
{
    private const OD = '1970-01-02 00:00:00';

    private const DO = '2038-01-18 00:00:00';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $kdy = Carbon::parse((string) $value);
        } catch (\Throwable) {
            // Formát řeší pravidlo `date` vedle tohohle — sem se nevalidní
            // řetězec dostane, jen když `date` v pravidlech chybí.
            $fail('Datum musí být mezi rokem 1970 a 2038.');

            return;
        }

        if (! self::vRozsahu($kdy)) {
            $fail('Datum musí být mezi rokem 1970 a 2038.');
        }
    }

    /**
     * Stejná hranice pro místa, která hodnotu mimo rozsah nemají odmítnout,
     * ale tiše zahodit (např. `UploadController` u data poslední úpravy souboru).
     */
    public static function vRozsahu(Carbon $kdy): bool
    {
        return $kdy->gte(Carbon::parse(self::OD)) && $kdy->lte(Carbon::parse(self::DO));
    }
}
