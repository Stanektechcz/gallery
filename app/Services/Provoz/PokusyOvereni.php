<?php

namespace App\Services\Provoz;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Hádání hesla k trezoru a kódu zámku aplikace.
 *
 * Pokusy se počítaly v sezení, takže je vynulovalo smazání cookies: kdo hádal,
 * dostal po třech chybách nové sezení a tři pokusy znovu. Teď patří účtu
 * a kdo po uzavření hádá dál, čeká pokaždé dvakrát déle.
 *
 * `druh` odděluje, co se hádá: `trezor` (heslo do galerie — z galerie i ze
 * starého rozhraní `/vault/unlock`, obojí stejné počítadlo, jinak by se
 * uzavření jednoho obešlo druhým) a `zamek` (šestimístný kód zámku).
 */
class PokusyOvereni
{
    /** Kolik chyb po sobě, než se přístup uzavře. */
    public const POKUSU = 3;

    /** První uzavření trvá půl minuty, každé další v řadě dvakrát déle… */
    private const BLOK_SEKUND = 30;

    /** …nejvýš čtvrt hodiny. */
    private const BLOK_NEJVIC = 900;

    /** Za kolik sekund se dá zkusit znovu; nula znamená hned. */
    public static function blokDo(User $kdo, string $druh): int
    {
        return max(0, (int) Cache::get(self::klic($kdo, $druh, 'blok'), 0) - now()->timestamp);
    }

    /**
     * Zapíše chybu.
     *
     * @return array{pokusu: int, zbyva: int, blok: int} `blok` je délka právě
     *                                                   začatého uzavření (jinak 0)
     */
    public static function chyba(User $kdo, string $druh): array
    {
        $pokusu = (int) Cache::get(self::klic($kdo, $druh, 'pokusy'), 0) + 1;

        if ($pokusu < self::POKUSU) {
            Cache::put(self::klic($kdo, $druh, 'pokusy'), $pokusu, now()->addMinutes(15));

            return ['pokusu' => $pokusu, 'zbyva' => self::POKUSU - $pokusu, 'blok' => 0];
        }

        // Kolikáté uzavření v řadě — pamatuje se hodinu od posledního.
        $poradi = (int) Cache::get(self::klic($kdo, $druh, 'uzavreni'), 0);
        $sekund = min(self::BLOK_NEJVIC, self::BLOK_SEKUND * 2 ** min($poradi, 10));

        Cache::put(self::klic($kdo, $druh, 'blok'), now()->addSeconds($sekund)->timestamp, now()->addSeconds($sekund));
        Cache::put(self::klic($kdo, $druh, 'uzavreni'), $poradi + 1, now()->addHour());
        Cache::forget(self::klic($kdo, $druh, 'pokusy'));

        return ['pokusu' => $pokusu, 'zbyva' => 0, 'blok' => $sekund];
    }

    /** Správné heslo nebo kód počítadla vynuluje. */
    public static function uspech(User $kdo, string $druh): void
    {
        Cache::forget(self::klic($kdo, $druh, 'pokusy'));
        Cache::forget(self::klic($kdo, $druh, 'uzavreni'));
        Cache::forget(self::klic($kdo, $druh, 'blok'));
    }

    public static function naJakDlouho(int $sekund): string
    {
        return match (true) {
            $sekund <= 30 => 'na půl minuty',
            $sekund < 120 => 'na minutu',
            $sekund < 300 => 'na '.intdiv($sekund, 60).' minuty',
            default => 'na '.intdiv($sekund, 60).' minut',
        };
    }

    private static function klic(User $kdo, string $druh, string $co): string
    {
        return 'pokusy:'.$druh.':'.$co.':'.$kdo->getKey();
    }
}
