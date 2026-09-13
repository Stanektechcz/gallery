<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Services\Auth\PristupDoGalerie;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Brána API galerie: kdo sem smí a co smí jeho klíč.
 *
 * Kontroluje se u **každého** požadavku, ne jen při přihlášení. Token vydaný
 * před odebráním přístupu, sezení z prohlížeče nebo klíč z administrace by
 * jinak fungovaly dál.
 *
 * Parametr `klic` je pro starší API `v1`: klíč a u hosta jen cesty k vlastnímu
 * účtu. Parametr `web` hlídá stránky starého rozhraní.
 */
class JenDvojice
{
    /** Metody, které nic nemění. */
    private const CTENI = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly PristupDoGalerie $pristup) {}

    public function handle(Request $request, Closure $next, string $rozsah = 'vse'): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        /*
         * Staré webové rozhraní: host se odhlásí a dozví se proč.
         *
         * Stránky jako alba, koš, trezor nebo export vydávaly data přímo
         * ze serveru, bez API. Chybová stránka by ho nechala přihlášeného
         * a bez tlačítka ven; přihlašovací formulář důvod ukáže.
         */
        if ($rozsah === 'web') {
            if (($duvod = $this->pristup->proc($user)) === null) {
                return $next($request);
            }

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $duvod], 403);
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => $duvod]);
        }

        /*
         * Klíč „jen čtení" z administrace.
         *
         * Vydával se se schopností `read`, ale žádná cesta ji nekontrolovala —
         * klíč určený pro zálohovací skript mohl mazat fotky. Klíč zařízení
         * i sezení z prohlížeče mají `*`, takže je tohle nezasáhne.
         */
        if (! in_array($request->method(), self::CTENI, true) && $this->jenProCteni($user->currentAccessToken())) {
            return response()->json(['message' => 'Tenhle klíč k API je jen pro čtení.'], 403);
        }

        if ($user->is_active === false) {
            return response()->json(['message' => 'Tenhle účet do galerie přístup nemá.'], 403);
        }

        if ($rozsah === 'vse' && ($duvod = $this->pristup->proc($user)) !== null) {
            return response()->json(['message' => $duvod], 403);
        }

        /*
         * Starší API `v1`: host jen ke svému účtu.
         *
         * Kontrolovalo se tu jen klíč. Host (členství `viewer`/`contributor`)
         * přihlášený do starého rozhraní si tak přes `v1` přečetl deník,
         * finance, chat i celou knihovnu dvojice — přitom podle administrace
         * vidí jen odkazy, které dostane. Zůstává mu jeho profil, fotka
         * a upozornění. Předplatné a faktury patří galerii, ne jemu; účet
         * bez vlastní galerie (`proc()` vrací null) se zablokovaný nepozná.
         */
        if ($rozsah === 'klic' && ! $this->uctovaCesta($request) && ($duvod = $this->pristup->proc($user)) !== null) {
            return response()->json(['message' => $duvod], 403);
        }

        return $next($request);
    }

    /** Cesty `v1`, které patří účtu, ne datům dvojice. */
    private function uctovaCesta(Request $request): bool
    {
        return $request->is(
            'api/v1/profil', 'api/v1/profil/*',
            'api/v1/avatar', 'api/v1/avatar/moznosti',
            'api/v1/notifications', 'api/v1/notifications/*',
            'api/v1/public/*',
        );
    }

    /**
     * Uložený klíč bez práva zápisu.
     *
     * Rozhoduje se podle schopností zapsaných u klíče, ne přes `tokenCan()`:
     * sezení z prohlížeče nese `TransientToken` a ten o schopnostech nic neví.
     */
    private function jenProCteni(mixed $token): bool
    {
        if (! $token instanceof PersonalAccessToken || ! $token->exists) {
            return false;
        }

        $schopnosti = (array) $token->abilities;

        return ! in_array('*', $schopnosti, true) && ! in_array('write', $schopnosti, true);
    }
}
