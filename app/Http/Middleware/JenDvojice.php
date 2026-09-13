<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Services\Auth\PristupDoGalerie;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Brána API galerie: kdo sem smí a co smí jeho klíč.
 *
 * Kontroluje se u **každého** požadavku, ne jen při přihlášení. Token vydaný
 * před odebráním přístupu, sezení z prohlížeče nebo klíč z administrace by
 * jinak fungovaly dál.
 *
 * Parametr `klic` zapne jen kontrolu klíče (starší API `v1`, které používá
 * i původní rozhraní s vlastním modelem rolí).
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

        return $next($request);
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
