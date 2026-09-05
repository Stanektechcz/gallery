<?php

namespace App\Http\Controllers\Galerie;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

/**
 * Doručení prototypu.
 *
 * Dokumenty v `resources/galerie` jsou **beze změny** takové, jaké přišly ze
 * ZIPu — ani řádek. Backend do nich při odeslání vloží jednu hlavičku: adresu
 * API, token proti CSRF, veřejný klíč pro upozornění a registraci service
 * workera. Kdyby se to zapisovalo do souborů, byla by každá další verze
 * prototypu ruční sloučení; takhle se soubor jen přepíše.
 *
 * Rozvržení se vybírá podle šířky zařízení, ne podle cesty: prototyp má
 * telefonní a širokou verzi jako dva dokumenty a adresa je pro obě stejná.
 */
class PrototypController extends Controller
{
    private const SIROKE = 'galerie-desktop.dc.html';

    private const TELEFON = 'galerie-mobil.dc.html';

    public function __invoke(Request $request): Response
    {
        $soubor = $this->cesta($this->rozvrzeni($request));

        abort_unless(File::exists($soubor), 503,
            'Prototyp není nasazený — chybí dokumenty v resources/galerie.');

        $telo = $this->sHlavickou(File::get($soubor));

        $odpoved = response('')
            ->header('Content-Type', 'text/html; charset=utf-8')
            // Dokument se mění s každým nasazením a nese v sobě token proti CSRF.
            // Uložit ho do sdílené cache by znamenalo poslat cizí token cizímu
            // člověku; service worker si ho drží sám a jen jako zálohu pro offline.
            ->header('Cache-Control', 'private, no-cache, must-revalidate')
            ->header('Vary', 'Accept-Encoding, User-Agent');

        return $this->zabaleno($request, $odpoved, $telo);
    }

    /**
     * Dokument má dva megabajty a jde po drátě při každém načtení.
     *
     * Zabalený má kolem dvou set kilobajtů — na mobilní síti je to rozdíl mezi
     * vteřinou a deseti. Balí se tady, ne až ve webovém serveru: prototyp má
     * běžet stejně rychle i tam, kde je před Laravelem jen `artisan serve`
     * nebo server bez zapnuté komprese.
     */
    private function zabaleno(Request $request, Response $odpoved, string $telo): Response
    {
        $umi = str_contains(strtolower((string) $request->header('Accept-Encoding')), 'gzip');

        if (! $umi || ! function_exists('gzencode')) {
            return $odpoved->setContent($telo);
        }

        // Stupeň 6: nad ním se poměr zlepšuje o jednotky procent a čas roste
        // znatelně, a tenhle výpočet je v každém načtení stránky.
        $zabalene = gzencode($telo, 6);

        if ($zabalene === false) {
            return $odpoved->setContent($telo);
        }

        return $odpoved
            ->header('Content-Encoding', 'gzip')
            ->header('Content-Length', (string) strlen($zabalene))
            ->setContent($zabalene);
    }

    /**
     * Service worker musí mít dosah na celý web, jinak neuvidí `/api/`.
     *
     * Soubor je schválně mimo `public/`: statický soubor by web server vydal
     * dřív, než požadavek dojde do PHP, a hlavička `Service-Worker-Allowed`
     * by chyběla. Prohlížeč by pak worker zaregistroval jen pro adresář, ve
     * kterém leží, a fronta offline zápisů by tiše nefungovala.
     */
    public function serviceWorker(): Response
    {
        $soubor = $this->cesta('sw.js');

        abort_unless(File::exists($soubor), 404);

        return response($this->naServeru(File::get($soubor)))
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Service-Worker-Allowed', '/')
            ->header('Cache-Control', 'no-cache');
    }

    /**
     * Worker ze ZIPu počítá se statickým hostem, kde je aplikace soubor.
     *
     * Po kliknutí na upozornění otevírá `Galerie mobil aplikace.dc.html` a
     * otevřené okno hledá podle „Galerie" v adrese. Na serveru je aplikace na
     * `/`, takže by upozornění otevřelo neexistující adresu a už otevřené okno
     * by nenašlo nikdy. Opravuje se to **při odeslání**, aby soubor v repozitáři
     * zůstal takový, jaký přišel, a další verze prototypu se dala jen přepsat.
     *
     * `d.route || d.url` je tu proto, že odesílání upozornění v aplikaci posílá
     * cíl pod klíčem `url`; worker prototypu čte `route`.
     */
    private function naServeru(string $worker): string
    {
        return strtr($worker, [
            "const target = 'Galerie%20mobil%20aplikace.dc.html' + (route ? '#' + route : '');"
                => "const target = '/' + (route ? '#' + route : '');",
            "if (c.url.indexOf('Galerie') >= 0) {"
                => 'if (c.url.indexOf(self.registration.scope) === 0) {',
            'data: { route: d.route || null },'
                => 'data: { route: d.route || d.url || null },',
        ]);
    }

    private function cesta(string $soubor): string
    {
        return rtrim((string) config('galerie.prototyp_path', resource_path('galerie')), '/\\')
            .DIRECTORY_SEPARATOR.$soubor;
    }

    private function rozvrzeni(Request $request): string
    {
        // Explicitní volba vyhrává — jinak by se telefonní rozvržení nedalo
        // vyzkoušet na počítači a naopak.
        $volba = $request->query('rozvrzeni');

        if ($volba === 'telefon') {
            return self::TELEFON;
        }

        if ($volba === 'siroke') {
            return self::SIROKE;
        }

        return $this->telefon($request) ? self::TELEFON : self::SIROKE;
    }

    private function telefon(Request $request): bool
    {
        $agent = (string) $request->userAgent();

        // Tablet dostane širokou verzi: prototyp ji na deseti palcích unese
        // a telefonní by na nich byla zbytečně úsporná.
        if (str_contains($agent, 'iPad') || (str_contains($agent, 'Android') && ! str_contains($agent, 'Mobile'))) {
            return false;
        }

        return (bool) preg_match('/Mobile|Android|iPhone|iPod|Windows Phone/i', $agent);
    }

    /**
     * Hlavička, kterou prototyp k backendu potřebuje.
     *
     * Vkládá se před `</head>`, aby `galerie-api.js` (načtený až v těle
     * dokumentu) našel `GALERIE_API_BASE` už nastavené — ten si podle něj hned
     * při načtení volí mezi režimem „http" a „local".
     */
    private function sHlavickou(string $dokument): string
    {
        $hlavicka = view('galerie.hlavicka')->render();

        $misto = stripos($dokument, '</head>');

        return $misto === false
            ? $hlavicka.$dokument
            : substr($dokument, 0, $misto).$hlavicka.substr($dokument, $misto);
    }
}
