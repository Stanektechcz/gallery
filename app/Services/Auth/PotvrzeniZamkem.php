<?php

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Provoz\PokusyOvereni;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

/**
 * „Jste to vy?" — před změnou, která zámek aplikace obchází nebo nahrazuje.
 *
 * Kdo kód zámku má, prokáže se jím. Kdo ho ještě nemá, heslem do galerie.
 * Ptají se dvě místa: nastavení kódu (`ZamekController::nastav`) a zapnutí
 * otisku (`WebauthnController::registerOptions`). Otisk je druhý klíč od
 * téhož zámku — kdyby ho šlo připojit bez kódu, stačilo by k odemčení
 * projít ověřením samotného zařízení (Windows Hello na společném počítači,
 * PIN telefonu, který zná partner), a to i ve chvíli, kdy server pokusy
 * o kód blokuje.
 *
 * Pokusy obou míst počítá `PokusyOvereni` pod druhem `zamek`, stejně jako
 * odemykání (`ZamekController::over`). Jedno počítadlo pro všechno, čím se
 * dá kód hádat — jinak by se uzavření jedné cesty obešlo druhou.
 */
class PotvrzeniZamkem
{
    public const DRUH = 'zamek';

    /**
     * `null`, když důkaz sedí (a počítadlo se vynulovalo); jinak hotová odpověď
     * s chybou — 429 při uzavření, 422 při chybě.
     *
     * @param  string  $akce  záznam do protokolu při neúspěchu
     * @param  string  $spatnyKod  hláška, když nesedí kód (u nastavení je to „starý kód")
     */
    public static function over(User $kdo, ?string $kod, ?string $heslo, string $akce, string $spatnyKod): ?JsonResponse
    {
        if (($blok = PokusyOvereni::blokDo($kdo, self::DRUH)) > 0) {
            return response()->json(['chyba' => 'Přístup je uzavřený. Zkuste to za '.$blok.' s.'], 429);
        }

        $sedi = $kdo->app_lock_pin
            ? Hash::check((string) $kod, $kdo->app_lock_pin)
            : Hash::check((string) $heslo, (string) $kdo->password);

        if (! $sedi) {
            $chyba = PokusyOvereni::chyba($kdo, self::DRUH);
            AuditLog::record($akce, null, ['pokus' => $chyba['pokusu']]);

            if ($chyba['blok'] > 0) {
                return response()->json([
                    'chyba' => 'Třikrát to nesedělo. Zkuste to za '.$chyba['blok'].' s.',
                ], 429);
            }

            return response()->json([
                'chyba' => $kdo->app_lock_pin ? $spatnyKod : 'Heslo do galerie nesouhlasí.',
            ], 422);
        }

        PokusyOvereni::uspech($kdo, self::DRUH);

        return null;
    }
}
