<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Zámek aplikace — kód, který patří jednomu člověku.
 *
 * Šestimístný kód se doteď porovnával v prohlížeči s konstantou `LOCKPIN`
 * z `galerie-data.js`. Ten soubor server podá komukoli, kdo zná jeho adresu,
 * takže kódy obou partnerů byly veřejné; obrazovka je navíc sama vypisovala
 * v nápovědě nad klávesnicí. A protože porovnání běželo na klientovi, dal se
 * zámek otevřít i bez kódu — stačilo v konzoli přepsat `appLocked`.
 *
 * Teď má kód každý svůj, uložený u svého účtu jako haš. Nevidí ho partner,
 * nevidí ho odpověď serveru a z databáze se přečíst nedá — dá se jen ověřit.
 *
 * Co ten zámek **je a co není**: aplikace už je v tu chvíli přihlášená, takže
 * kód nechrání data před někým, kdo umí otevřít nástroje pro vývojáře. Chrání
 * je před tím, kdo zvedne odemčený telefon. Proto ověřuje server: aby se
 * nedal obejít tím jednoduchým způsobem, a aby se pokusy daly spočítat.
 */
class ZamekController extends Controller
{
    /** Kolik pokusů po sobě, než se odemykání na chvíli uzavře. */
    private const POKUSU = 3;

    /** Jak dlouho pak. Půl minuty, jak to obrazovka slibuje. */
    private const BLOK = 30;

    private const KLIC_POKUSY = 'app_lock_failed_attempts';

    private const KLIC_BLOK = 'app_lock_blocked_until';

    /** @return array{nastaveno: bool, delka: int, blok: int, zmeneno: ?string} */
    private function odpoved(Request $request): array
    {
        $clovek = $request->user();

        return [
            'nastaveno' => (bool) $clovek->app_lock_pin,
            'delka' => 6,
            'blok' => $this->blokDo($request),
            'zmeneno' => $clovek->app_lock_set_at?->toDateString(),
        ];
    }

    public function stav(Request $request): JsonResponse
    {
        return response()->json($this->odpoved($request));
    }

    /**
     * Nastavit nebo změnit kód.
     *
     * Kdo kód má, změní ho jen tím starým. Kdo ho nemá, prokáže se heslem do
     * galerie — jinak by si ho na cizím odemčeném telefonu nastavil kdokoli
     * a majitele z aplikace vyzamkl.
     *
     * Obnovovací kód se vrací **jednou a jen tady**. Podruhé už ho nikdo
     * nepřečte, protože v databázi je jen jeho haš; kdo si ho neopíše, musí
     * si nastavit nový.
     */
    public function nastav(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kod' => ['required', 'string', 'digits:6'],
            'stary' => ['nullable', 'string'],
            'heslo' => ['nullable', 'string'],
        ]);

        $clovek = $request->user();

        if ($clovek->app_lock_pin) {
            if (! Hash::check((string) ($data['stary'] ?? ''), $clovek->app_lock_pin)) {
                return response()->json(['chyba' => 'Starý kód nesouhlasí.'], 422);
            }
        } elseif (! Hash::check((string) ($data['heslo'] ?? ''), (string) $clovek->password)) {
            return response()->json(['chyba' => 'Heslo do galerie nesouhlasí.'], 422);
        }

        // Obnovovací kód se čte nahlas do telefonu a opisuje z papíru, takže
        // bez znaků, které se pletou: 0/O, 1/I/l.
        $obnovovaci = Str::upper(Str::password(12, symbols: false, numbers: true));
        $obnovovaci = strtr($obnovovaci, ['0' => 'X', 'O' => 'X', '1' => 'Y', 'I' => 'Y', 'L' => 'Y']);
        $obnovovaci = implode('-', str_split($obnovovaci, 4));

        $clovek->forceFill([
            'app_lock_pin' => $data['kod'],
            'app_lock_recovery' => $obnovovaci,
            'app_lock_set_at' => now(),
        ])->save();

        $request->session()->forget([self::KLIC_POKUSY, self::KLIC_BLOK]);
        AuditLog::record('app_lock.set');

        return response()->json($this->odpoved($request) + ['obnovovaci' => $obnovovaci]);
    }

    /** Ověřit kód. Tohle je to, co dřív dělal prohlížeč sám proti konstantě. */
    public function over(Request $request): JsonResponse
    {
        $data = $request->validate(['kod' => ['required', 'string']]);
        $clovek = $request->user();

        if (($blok = $this->blokDo($request)) > 0) {
            return response()->json($this->odpoved($request) + [
                'chyba' => 'Přístup je uzavřený. Zkuste to za '.$blok.' s.',
            ], 429);
        }

        if (! $clovek->app_lock_pin) {
            return response()->json($this->odpoved($request) + [
                'chyba' => 'Kód zámku ještě není nastavený.',
            ], 409);
        }

        if (! Hash::check($data['kod'], $clovek->app_lock_pin)) {
            $pokusu = (int) $request->session()->get(self::KLIC_POKUSY, 0) + 1;
            $request->session()->put(self::KLIC_POKUSY, $pokusu);
            AuditLog::record('app_lock.failed', null, ['pokus' => $pokusu]);

            if ($pokusu >= self::POKUSU) {
                $request->session()->put(self::KLIC_BLOK, now()->addSeconds(self::BLOK)->timestamp);
                $request->session()->forget(self::KLIC_POKUSY);

                return response()->json($this->odpoved($request) + [
                    'chyba' => 'Kód jsme třikrát nepřijali. Zkuste odemknutí dotykem, nebo obnovovací kód.',
                ], 429);
            }

            $zbyva = self::POKUSU - $pokusu;

            return response()->json($this->odpoved($request) + [
                'chyba' => 'Kód nesouhlasí — '.($zbyva === 1 ? 'zbývá poslední pokus.' : 'zbývají '.$zbyva.' pokusy.'),
            ], 422);
        }

        $request->session()->forget([self::KLIC_POKUSY, self::KLIC_BLOK]);
        AuditLog::record('app_lock.open');

        return response()->json($this->odpoved($request) + ['odemceno' => true]);
    }

    /**
     * Obnovovací kód.
     *
     * Otevře aplikaci a **zároveň zahodí zapomenutý kód**, takže si člověk
     * musí hned nastavit nový. Obnovovací kód se tím spotřebuje: kdyby platil
     * dál, byl by z jednorázové zálohy druhé, trvalé heslo — a to na papíře
     * v šuplíku.
     */
    public function obnov(Request $request): JsonResponse
    {
        $data = $request->validate(['kod' => ['required', 'string']]);
        $clovek = $request->user();

        $zadany = Str::upper(preg_replace('/[^A-Z0-9]/i', '', $data['kod']) ?? '');
        $ulozeny = (string) $clovek->app_lock_recovery;

        if ($ulozeny === '' || ! Hash::check(implode('-', str_split($zadany, 4)), $ulozeny)) {
            AuditLog::record('app_lock.recovery_failed');

            return response()->json(['chyba' => 'Obnovovací kód nesouhlasí.'], 422);
        }

        $clovek->forceFill([
            'app_lock_pin' => null,
            'app_lock_recovery' => null,
            'app_lock_set_at' => null,
        ])->save();

        $request->session()->forget([self::KLIC_POKUSY, self::KLIC_BLOK]);
        AuditLog::record('app_lock.recovered');

        return response()->json($this->odpoved($request) + ['odemceno' => true]);
    }

    /**
     * Odhlásit ostatní zařízení.
     *
     * Tlačítko v nastavení jen ukázalo hlášku „Ostatní zařízení odhlášena" —
     * a nic se nestalo. Je to přitom to jediné, co má člověk po ruce, když
     * zjistí, že se někdo přihlásil odjinud.
     *
     * Ruší se obojí, protože přihlásit se dá obojím: sezení v prohlížeči
     * (`sessions`) i vydané klíče (`personal_access_tokens`). Tohle zařízení
     * zůstává — jinak by se člověk odhlásil sám sobě a k ničemu se nedostal.
     */
    public function odhlasOstatni(Request $request): JsonResponse
    {
        $clovek = $request->user();
        $sezeni = 0;

        if (Schema::hasTable('sessions')) {
            $sezeni = DB::table('sessions')
                ->where('user_id', $clovek->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        $tohle = $clovek->currentAccessToken();

        $klice = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $clovek->id)
            ->when($tohle?->id, fn ($q, $id) => $q->where('id', '!=', $id))
            ->delete();

        AuditLog::record('app_lock.sign_out_others', null, ['sezeni' => $sezeni, 'klice' => $klice]);

        return response()->json([
            'sezeni' => $sezeni,
            'klice' => $klice,
            'zprava' => $sezeni + $klice > 0
                ? 'Odhlášeno jinde: '.($sezeni + $klice).'× · tady zůstáváte přihlášeni'
                : 'Nikde jinde jste přihlášení nebyli',
        ]);
    }

    private function blokDo(Request $request): int
    {
        return max(0, (int) $request->session()->get(self::KLIC_BLOK, 0) - now()->timestamp);
    }
}
