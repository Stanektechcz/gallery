<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Zámek trezoru — ten skutečný.
 *
 * Obrazovka `x-trezor` porovnávala zadané heslo s konstantou `VAULT_PWD`
 * v `galerie-data.js`. Ten soubor je veřejný: heslo do trezoru šlo přečíst
 * bez přihlášení, stačilo otevřít adresu skriptu. Druhé ověření „kódem
 * z aplikace" se kontrolovalo jen na šest číslic, takže prošlo `000000`.
 * A odemčení nikam nedosáhlo — patnáctiminutové sezení bylo číslo v paměti
 * prohlížeče, takže ho stačilo přepsat v konzoli.
 *
 * Přitom skutečný zámek v aplikaci existuje: `vault_unlocked_until` v sezení,
 * `ProtectVaultMedia` na výdeji médií a `VaultController` na webu. Tenhle
 * kontrolér je jen jeho druhé okno — **tentýž klíč v sezení**, takže odemčení
 * z prototypu otevře i výdej souborů a zamčení platí na obou stranách.
 *
 * Heslo je heslo do galerie (`users.password`) přes `Hash::check`; jiné by
 * znamenalo druhé tajemství, které nikdo neumí změnit ani obnovit.
 */
class TrezorController extends Controller
{
    /** Jak dlouho odemčení platí. Stejných patnáct minut jako na webu. */
    private const MINUT = 15;

    /** Kolik pokusů po sobě, než se přístup na půl minuty uzavře. */
    private const POKUSU = 3;

    private const KLIC = 'vault_unlocked_until';

    private const KLIC_POKUSY = 'vault_failed_attempts';

    private const KLIC_BLOK = 'vault_blocked_until';

    public function stav(Request $request): JsonResponse
    {
        return response()->json($this->odpoved($request));
    }

    public function odemkni(Request $request): JsonResponse
    {
        $data = $request->validate(['heslo' => 'required|string']);

        if (($blok = $this->blokDo($request)) > 0) {
            return response()->json($this->odpoved($request) + [
                'chyba' => 'Přístup je uzavřený. Zkuste to za '.$blok.' s.',
            ], 429);
        }

        if (! Hash::check($data['heslo'], (string) $request->user()->password)) {
            $pokusu = (int) $request->session()->get(self::KLIC_POKUSY, 0) + 1;
            $request->session()->put(self::KLIC_POKUSY, $pokusu);

            /*
             * Neúspěšný pokus se zapisuje.
             *
             * Obrazovka to slibuje („Neúspěšné pokusy se zapisují do Aktivity
             * a do auditu") a dosud to nebyla pravda. U trezoru je to zároveň
             * jediná stopa, podle které se pozná, že se do něj někdo dobýval.
             */
            AuditLog::record('vault.unlock_failed', null, ['pokus' => $pokusu]);

            if ($pokusu >= self::POKUSU) {
                $request->session()->put(self::KLIC_BLOK, now()->addSeconds(30)->timestamp);
                $request->session()->forget(self::KLIC_POKUSY);

                return response()->json($this->odpoved($request) + [
                    'chyba' => 'Tři neúspěšné pokusy. Přístup je na půl minuty uzavřený a záznam šel do auditu.',
                ], 429);
            }

            $zbyva = self::POKUSU - $pokusu;

            return response()->json($this->odpoved($request) + [
                'chyba' => 'Heslo nesouhlasí. Zbývá '.$zbyva.' '.($zbyva === 1 ? 'pokus' : 'pokusy').'.',
            ], 422);
        }

        $request->session()->put(self::KLIC, now()->addMinutes(self::MINUT)->timestamp);
        $request->session()->forget(self::KLIC_POKUSY);
        $request->session()->forget(self::KLIC_BLOK);
        AuditLog::record('vault.unlock');

        return response()->json($this->odpoved($request));
    }

    public function zamkni(Request $request): JsonResponse
    {
        $request->session()->forget(self::KLIC);
        AuditLog::record('vault.lock');

        return response()->json($this->odpoved($request));
    }

    /**
     * Kolik zbývá — počítá server, ne prohlížeč.
     *
     * Odpočet v prohlížeči se dá zastavit, přetočit i obnovit načtením
     * stránky. Tohle číslo je jen obraz `vault_unlocked_until`, takže když
     * dojde, dojde i skutečný přístup k souborům.
     *
     * @return array{odemceno: bool, zbyva: int, blok: int}
     */
    private function odpoved(Request $request): array
    {
        $do = (int) $request->session()->get(self::KLIC, 0);
        $zbyva = max(0, $do - now()->timestamp);

        return [
            'odemceno' => $zbyva > 0,
            'zbyva' => $zbyva,
            'blok' => $this->blokDo($request),
        ];
    }

    private function blokDo(Request $request): int
    {
        return max(0, (int) $request->session()->get(self::KLIC_BLOK, 0) - now()->timestamp);
    }
}
