<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Baseline browser protections that do not break Inertia, PWA or map providers. */
class SecurityHeaders
{
    /**
     * Odkud smí stránka cokoliv načíst.
     *
     * Pojistka pro případ, že by někde selhalo escapování: vzkaz od hosta,
     * název alba nebo popisek fotky se do stránky dostávají jako text, ale
     * jedna chyba v tom stačí. S touhle politikou by ani vložený `<script src>`
     * z cizí adresy nedoběhl.
     *
     * Co **nezakazuje** a proč:
     *
     *  - `'unsafe-inline'` u skriptů: prototyp má svůj běh v inline blocích
     *    přímo v dokumentu a nonce by musel projít i runtime, který si značky
     *    skládá sám.
     *  - `'unsafe-eval'`: JSX se překládá v prohlížeči Babelem. Bez `eval`
     *    se aplikace nespustí vůbec.
     *
     * Zbytek je zamčený: `object-src 'none'` vypíná pluginy, `base-uri 'self'`
     * brání přepsání adresy, ze které se stahují skripty, a `form-action 'self'`
     * tomu, aby přihlašovací formulář odeslal heslo jinam.
     *
     * @var array<string, list<string>>
     */
    private const POLITIKA = [
        'default-src' => ["'self'"],
        // unpkg: React, ReactDOM a Babel, které si běh prototypu stahuje sám.
        'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'", 'https://unpkg.com'],
        'style-src' => ["'self'", "'unsafe-inline'", 'https://unpkg.com', 'https://fonts.googleapis.com'],
        'font-src' => ["'self'", 'data:', 'https://fonts.gstatic.com', 'https://unpkg.com'],
        // `data:` kvůli náhledům skládaným v prohlížeči, `blob:` kvůli
        // souborům vybraným k nahrání. Mapové dlaždice chodí z Mapy.com.
        'img-src' => ["'self'", 'data:', 'blob:', 'https://api.mapy.com'],
        'media-src' => ["'self'", 'blob:'],
        'connect-src' => ["'self'", 'https://api.mapy.com'],
        'worker-src' => ["'self'", 'blob:'],
        'manifest-src' => ["'self'"],
        'object-src' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
        // Totéž, co říká `X-Frame-Options`, jen novější cestou.
        'frame-ancestors' => ["'self'"],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(self), geolocation=(self), payment=()');

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->politika($request));
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * Politika jako jeden řádek.
     *
     * `upgrade-insecure-requests` jen přes HTTPS: na vývojovém serveru běží
     * aplikace na `http://localhost` a s tou direktivou by si prohlížeč
     * přepsal všechny vlastní adresy na `https` a nenačetl nic.
     */
    private function politika(Request $request): string
    {
        $casti = [];

        foreach (self::POLITIKA as $direktiva => $zdroje) {
            $casti[] = $direktiva.' '.implode(' ', $zdroje);
        }

        if ($request->isSecure()) {
            $casti[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $casti);
    }
}
