<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\Auth\PotvrzeniZamkem;
use App\Services\Notifications\OdberyPush;
use App\Services\Provoz\PokusyOvereni;
use App\Support\Tabulky;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
    /**
     * Pokusy o kód počítá `PokusyOvereni` u účtu, ne v sezení.
     *
     * V sezení je vynulovalo smazání cookies — a šest číslic je milion
     * možností, tedy s novým sezením po každých třech chybách otázka dnů.
     */
    private const DRUH = PotvrzeniZamkem::DRUH;

    /** @return array{nastaveno: bool, delka: int, blok: int, zmeneno: ?string} */
    private function odpoved(Request $request): array
    {
        $clovek = $request->user();

        return [
            'nastaveno' => (bool) $clovek->app_lock_pin,
            'delka' => 6,
            'blok' => PokusyOvereni::blokDo($clovek, self::DRUH),
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

        /*
         * Tytéž obrany jako u ověření kódu.
         *
         * `over()` má tři pokusy, blokaci i zápis do protokolu; tahle cesta
         * neměla nic z toho, jen plochý limit požadavků. Přitom se tu hádá
         * buď starý kód, nebo rovnou **heslo do galerie** — kdo zvedl
         * odemčený telefon, mohl zkoušet donekonečna a v protokolu, který
         * obrazovka zámku slibuje, po tom nezbyla stopa. Totéž se ptá
         * zapnutí otisku, viz `PotvrzeniZamkem`.
         */
        $odmitnuti = PotvrzeniZamkem::over(
            $clovek,
            $data['stary'] ?? null,
            $data['heslo'] ?? null,
            'app_lock.set.failed',
            'Starý kód nesouhlasí.',
        );

        if ($odmitnuti !== null) {
            return $odmitnuti;
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

        // Počítadlo pokusů vynulovalo už `PotvrzeniZamkem` — důkaz seděl.
        AuditLog::record('app_lock.set');

        return response()->json($this->odpoved($request) + ['obnovovaci' => $obnovovaci]);
    }

    /** Ověřit kód. Tohle je to, co dřív dělal prohlížeč sám proti konstantě. */
    public function over(Request $request): JsonResponse
    {
        $data = $request->validate(['kod' => ['required', 'string']]);
        $clovek = $request->user();

        if (($blok = PokusyOvereni::blokDo($clovek, self::DRUH)) > 0) {
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
            $chyba = PokusyOvereni::chyba($clovek, self::DRUH);
            AuditLog::record('app_lock.failed', null, ['pokus' => $chyba['pokusu']]);

            if ($chyba['blok'] > 0) {
                return response()->json($this->odpoved($request) + [
                    'chyba' => 'Kód jsme třikrát nepřijali. Zkuste odemknutí dotykem, nebo obnovovací kód.',
                ], 429);
            }

            $zbyva = $chyba['zbyva'];

            return response()->json($this->odpoved($request) + [
                'chyba' => 'Kód nesouhlasí — '.($zbyva === 1 ? 'zbývá poslední pokus.' : 'zbývají '.$zbyva.' pokusy.'),
            ], 422);
        }

        PokusyOvereni::uspech($clovek, self::DRUH);
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

        PokusyOvereni::uspech($clovek, self::DRUH);
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

        if (Tabulky::je('sessions')) {
            // Požadavek jen s tokenem sezení nemá — pak se ruší všechna.
            $sezeni = DB::table('sessions')
                ->where('user_id', $clovek->id)
                ->when($request->hasSession(), fn ($q) => $q->where('id', '!=', $request->session()->getId()))
                ->delete();
        }

        $tohle = WebauthnCredential::tokenPozadavku($clovek);

        $klice = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $clovek->id)
            ->when($tohle, fn ($q, $id) => $q->where('id', '!=', $id))
            ->delete();

        /*
         * Otisky ostatních zařízení a „zapamatovat si mě".
         *
         * Otisk vydá nový token sám, takže bez jeho zrušení se odhlášené
         * zařízení otiskem hned přihlásilo zpátky. Zůstává jen otisk tohohle
         * zařízení (patří tokenu, kterým přišel tenhle požadavek). Nový
         * `remember_token` zneplatní cookie „zapamatovat si mě" ze starého
         * rozhraní — i v tomhle prohlížeči, sezení tady ale běží dál.
         */
        $otisky = WebauthnCredential::zrusKromeTokenu($clovek, $tohle);
        $clovek->forceFill(['remember_token' => Str::random(60)])->save();

        // Odhlášené zařízení nesmí dál dostávat upozornění. Který odběr patří
        // tomuhle zařízení, ví jen klient: pošle-li jeho adresu, zůstane; jinak
        // se ruší všechny a tohle zařízení si odběr obnoví samo.
        $odbery = OdberyPush::zrusVse($clovek, $request->input('endpoint'));

        AuditLog::record('app_lock.sign_out_others', null, ['sezeni' => $sezeni, 'klice' => $klice, 'otisky' => $otisky, 'odbery' => $odbery]);

        return response()->json([
            'sezeni' => $sezeni,
            'klice' => $klice,
            'zprava' => $sezeni + $klice > 0
                ? 'Odhlášeno jinde: '.($sezeni + $klice).'× · tady zůstáváte přihlášeni'
                : 'Nikde jinde jste přihlášení nebyli',
        ]);
    }
}
