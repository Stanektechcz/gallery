<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Odkud smí stránka cokoliv načíst (`Content-Security-Policy`).
 *
 * Pojistka pro případ, že by někde selhalo escapování: vzkaz od hosta,
 * název alba nebo popisek fotky se do stránky dostávají jako text, ale
 * jedna chyba v tom stačí. S touhle politikou by ani vložený `<script src>`
 * z cizí adresy nedoběhl.
 *
 * Společná politika (`SecurityHeaders`) platí pro všechno kromě prototypu:
 *
 *  - `'unsafe-inline'` u skriptů: stránky Inertie a Blade mají vlastní
 *    inline bloky, které tahle úprava neprochází.
 *  - `'unsafe-eval'`: JSX se překládá v prohlížeči Babelem. Bez `eval`
 *    se aplikace nespustí vůbec.
 *
 * Prototyp dostává tutéž politiku, jen místo `'unsafe-inline'` vyjmenuje
 * otisky svých vlastních inline skriptů a místo celého unpkg tři soubory,
 * které opravdu stahuje (`proPrototyp()`). Tam je to
 * nejdůležitější: přihlašovací token leží v `localStorage`, takže jediný
 * vložený `<script>` by ho přečetl a poslal pryč.
 *
 * Zbytek je zamčený: `object-src 'none'` vypíná pluginy, `base-uri 'self'`
 * brání přepsání adresy, ze které se stahují skripty, a `form-action 'self'`
 * tomu, aby přihlašovací formulář odeslal heslo jinam.
 */
final class PolitikaObsahu
{
    /** @var array<string, list<string>> */
    private const POLITIKA = [
        'default-src' => ["'self'"],
        // unpkg: React, ReactDOM a Babel, které si běh prototypu stahuje sám.
        'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'", 'https://unpkg.com'],
        // Styly zůstávají s `'unsafe-inline'`: runtime prototypu skládá atomické
        // CSS do `<style>` a stylové atributy — bez nich by se nevykreslil.
        'style-src' => ["'self'", "'unsafe-inline'", 'https://unpkg.com', 'https://fonts.googleapis.com'],
        'font-src' => ["'self'", 'data:', 'https://fonts.gstatic.com', 'https://unpkg.com'],
        /*
         * `data:` kvůli náhledům skládaným v prohlížeči, `blob:` kvůli souborům
         * vybraným k nahrání.
         *
         * Dlaždice mapy chodí z OpenStreetMap — `mapa.html` je načítá jako
         * obrázky, takže rozhoduje `img-src`. Dokud tam ten původ nebyl, mapa
         * se otevřela prázdná: špendlíky nakreslené, pod nimi šedé plátno.
         */
        'img-src' => ["'self'", 'data:', 'blob:', 'https://api.mapy.com',
            'https://tile.openstreetmap.org', 'https://*.tile.openstreetmap.org'],
        'media-src' => ["'self'", 'blob:'],
        'connect-src' => ["'self'", 'https://api.mapy.com', 'https://tile.openstreetmap.org'],
        'worker-src' => ["'self'", 'blob:'],
        'manifest-src' => ["'self'"],
        'object-src' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
        // Totéž, co říká `X-Frame-Options`, jen novější cestou.
        'frame-ancestors' => ["'self'"],
    ];

    /**
     * Skripty, které si runtime prototypu stahuje z unpkg (`public/support.js`).
     *
     * Přesné soubory místo celého hostu: unpkg vydá jakýkoli balík z npm,
     * takže s `https://unpkg.com` by vložené `<script src="https://unpkg.com/…">`
     * doběhlo a přečetlo token z `localStorage` — přesně to, čemu mají otisky
     * bránit. Při změně verze v `support.js` je třeba upravit i tohle;
     * `SecurityHeadersTest` obojí porovnává.
     */
    private const SKRIPTY_Z_UNPKG = [
        'https://unpkg.com/react@18.3.1/umd/react.production.min.js',
        'https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js',
        'https://unpkg.com/@babel/standalone@7.29.0/babel.min.js',
    ];

    /** Společná politika — Inertia, sdílené stránky, API. */
    public static function spolecna(Request $request): string
    {
        return self::slozit(self::POLITIKA, $request);
    }

    /**
     * Politika prototypu: inline skripty jen podle otisku.
     *
     * Otisk místo nonce: dokument jde s `ETag` spočítaným z těla a prohlížeč
     * si ho ověřuje (`304`). Nonce by se měnil s každým požadavkem, takže by
     * se tělo nikdy neshodovalo a každé otevření by znovu stáhlo dva megabajty.
     * Otisk se mění jen se skriptem samotným.
     *
     * @param  list<string>  $otisky  zdroje ve tvaru `'sha256-…'`
     */
    public static function proPrototyp(Request $request, array $otisky): string
    {
        $politika = self::POLITIKA;
        $politika['script-src'] = ["'self'", ...array_values(array_unique($otisky)), "'unsafe-eval'", ...self::SKRIPTY_Z_UNPKG];

        return self::slozit($politika, $request);
    }

    /**
     * Politika jako jeden řádek.
     *
     * `upgrade-insecure-requests` jen přes HTTPS: na vývojovém serveru běží
     * aplikace na `http://localhost` a s tou direktivou by si prohlížeč
     * přepsal všechny vlastní adresy na `https` a nenačetl nic.
     *
     * @param  array<string, list<string>>  $politika
     */
    private static function slozit(array $politika, Request $request): string
    {
        $casti = [];

        foreach ($politika as $direktiva => $zdroje) {
            $casti[] = $direktiva.' '.implode(' ', $zdroje);
        }

        if ($request->isSecure()) {
            $casti[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $casti);
    }
}
