<?php

namespace App\Http\Middleware;

use App\Support\PolitikaObsahu;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Baseline browser protections that do not break Inertia, PWA or map providers. */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(self), geolocation=(self), payment=()');

        // Odpověď s vlastní politikou (prototyp — otisky místo `'unsafe-inline'`)
        // si ji nechává; ostatní dostanou společnou. Viz `PolitikaObsahu`.
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', PolitikaObsahu::spolecna($request));
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        /*
         * Paměť prohlížeče rozlišuje, kdo se ptá.
         *
         * Obsah (`/api/data`, panel, mechanismy) jde s `private, max-age` a
         * middleware Inertie přepisuje `Vary` jen na `X-Inertia`. Prohlížeč tak
         * do minuty podal uloženou odpověď i požadavku s jiným tokenem — po
         * odhlášení jednoho a přihlášení druhého na témž počítači viděl druhý
         * obsah prvního, včetně soukromých zápisů deníku, a s neplatným tokenem
         * se data dál vydávala z paměti. `Cookie` se nepřidává: Laravel ji
         * šifruje při každé odpovědi znovu a paměť by nezasáhla nikdy.
         */
        if ($request->is('api/*') && $response->headers->hasCacheControlDirective('private')) {
            $response->setVary('Authorization', false);
        }

        return $response;
    }
}
