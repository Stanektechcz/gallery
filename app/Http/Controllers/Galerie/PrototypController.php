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

    /**
     * Co si worker ukládá do skořápky (`SHELL_FILES` v `sw.js`).
     *
     * Drží se tu proto, že podle nich se počítá název paměti. Kdyby seznam
     * v `sw.js` narostl a tady ne, nová položka by se po nasazení neobnovila
     * — a přesně tak v paměti přežila náhradní obrazovka se starým jménem
     * aplikace.
     *
     * @var list<string>
     */
    private const SKORAPKA = [
        'offline.html',
        'manifest.webmanifest',
        'icons/icon.svg',
        'icons/icon-192.png',
        'icons/icon-512.png',
    ];

    public function __invoke(Request $request, ?string $rozvrzeni = null): Response
    {
        $soubor = $this->cesta($this->rozvrzeni($request, $rozvrzeni));

        abort_unless(File::exists($soubor), 503,
            'Prototyp není nasazený — chybí dokumenty v resources/galerie.');

        $telo = $this->sHlavickou(File::get($soubor), $request);

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

        return response($this->sVerzi($this->naServeru(File::get($soubor))))
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Service-Worker-Allowed', '/')
            ->header('Cache-Control', 'no-cache');
    }

    /**
     * Verze paměti, která se mění s nasazením.
     *
     * V souboru je napsané `v4` a zůstalo by tam napořád. Skořápka se přitom
     * uklízí právě podle názvu: co se jmenuje stejně, zůstane v paměti i po
     * nasazení. Většina souborů se bere „nejdřív ze sítě", takže to bylo vidět
     * jen na ikonách, písmech a balíku designového systému — ten se po změně
     * podával starý, dokud si někdo nevymazal paměť prohlížeče.
     *
     * Otisk se počítá z toho, co se nasazením mění: souborů prototypu
     * a manifestu sestavení. Když ani jedno není po ruce, zůstane napsaná
     * verze — horší než nic, ale ne chyba.
     */
    private function sVerzi(string $worker): string
    {
        $casy = [];

        foreach (['sw.js', self::SIROKE, self::TELEFON] as $soubor) {
            $cesta = $this->cesta($soubor);

            if (File::exists($cesta)) {
                $casy[] = File::lastModified($cesta);
            }
        }

        /*
         * Do otisku patří **všechno, co skořápka drží** — ne jen dokumenty.
         *
         * Skořápka se ukládá jednou při instalaci workera a pak se z ní čte,
         * dokud se nezmění název paměti. Když se otisk počítal jen z dokumentů
         * a manifestu sestavení, přežila v ní náhradní obrazovka `offline.html`
         * z doby, kdy se aplikace jmenovala jinak: nasazení, které měnilo jen
         * ji, nechalo název paměti stejný a prohlížeč podával starou kopii dál.
         *
         * Dvojice pak při každém výpadku spojení viděla „Maki čeká na
         * připojení" — jméno, které aplikace přestala nést 6. září, a obrazovku,
         * kterou nešlo nijak zahodit.
         */
        foreach (self::SKORAPKA as $soubor) {
            $cesta = public_path($soubor);

            if (File::exists($cesta)) {
                $casy[] = File::lastModified($cesta);
            }
        }

        $manifest = public_path('build/manifest.json');

        if (File::exists($manifest)) {
            $casy[] = File::lastModified($manifest);
        }

        if ($casy === []) {
            return $worker;
        }

        return str_replace(
            "const VERSION = 'v4';",
            "const VERSION = 'v4-".substr(sha1(implode('-', $casy)), 0, 10)."';",
            $worker,
        );
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
     *
     * Zbylé dvě náhrady řeší dvě věci, na kterých se aplikace umí zaseknout
     * natrvalo. Obě jsou u svého kódu popsané.
     */
    private function naServeru(string $worker): string
    {
        return strtr($worker, [
            "const target = 'Galerie%20mobil%20aplikace.dc.html' + (route ? '#' + route : '');" => "const target = '/' + (route ? '#' + route : '');",
            "if (c.url.indexOf('Galerie') >= 0) {" => 'if (c.url.indexOf(self.registration.scope) === 0) {',
            'data: { route: d.route || null },' => 'data: { route: d.route || d.url || null },',

            /*
             * Skořápka se obnoví při každém probuzení workera, ne jen při změně
             * názvu paměti.
             *
             * Ukládala se jednou při instalaci a pak se z ní jen četlo. Když
             * nasazení změnilo jen náhradní obrazovku, název paměti zůstal
             * stejný a v prohlížeči zůstala kopie z doby, kdy se aplikace
             * jmenovala jinak — „Maki čeká na připojení". Vymazat ji nešlo
             * odnikud z aplikace.
             */
            '    await self.clients.claim();' => <<<'JS'
    // Skořápka znovu ze sítě: soubor, který se mezitím změnil, by v paměti
    // zůstal až do přejmenování paměti — a náhradní obrazovka se starým jménem
    // aplikace tam takhle přežila přes nasazení, které ji přejmenovalo.
    const shell = await caches.open(SHELL);
    await Promise.all(SHELL_FILES.map(f => shell.add(new Request(f, { cache: 'reload' })).catch(() => {})));
    await self.clients.claim();
JS,

            /*
             * Náhradní obrazovka až po druhém pokusu.
             *
             * Navigace se stahuje jako `fetch(req, { cache: 'no-store' })`, což
             * je požadavek se změněným nastavením — a takový se v některých
             * prohlížečích chová jinak než ten původní. Když selže, není to
             * ještě důkaz, že aplikace nemá signál: prostý požadavek na tutéž
             * adresu klidně projde. Bez tohohle druhého pokusu se dvojice
             * dívala na „bez signálu" u zapnutého připojení a nemohla se dostat
             * dovnitř ani po obnovení stránky.
             */
            "        if (req.mode === 'navigate') {\n          const off = await caches.match('offline.html');" => <<<'JS'
        if (req.mode === 'navigate') {
          // Druhý pokus prostým požadavkem — teprve pak je „bez signálu" pravda.
          try {
            const znovu = await fetch(url.href, { credentials: 'same-origin' });
            if (znovu && znovu.ok) {
              const c = await caches.open(SHELL);
              c.put(bare, znovu.clone());
              return znovu;
            }
          } catch (e2) {}

          const off = await caches.match('offline.html');
JS,
        ]);
    }

    private function cesta(string $soubor): string
    {
        return rtrim((string) config('galerie.prototyp_path', resource_path('galerie')), '/\\')
            .DIRECTORY_SEPARATOR.$soubor;
    }

    /**
     * Které rozvržení poslat.
     *
     * Vynucená volba je **vlastní cesta**, ne parametr v dotazu. Service worker
     * prototypu si dokument ukládá pod adresu bez dotazu a čte ji s `ignoreSearch`,
     * takže jedno otevření `/?rozvrzeni=telefon` přepsalo uloženou kopii `/` —
     * a prohlížeč pak i na počítači nabízel telefonní verzi, dokud se paměť
     * nevymazala. Různé cesty se v paměti nepotkají.
     */
    private function rozvrzeni(Request $request, ?string $volba): string
    {
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
    private function sHlavickou(string $dokument, Request $request): string
    {
        /*
         * Kdo se dívá.
         *
         * Hlavička s tím počítala od začátku, jenže jí to nikdo nepředal —
         * `window.GALERIE_USER` bylo vždycky `null`. Bez toho nemá prototyp
         * jak poznat, které z těch dvou jmen je to jeho, a u pre-mortemu
         * ukazoval vlastní obavy ve sloupci toho druhého.
         */
        $uzivatel = $request->user();

        $hlavicka = view('galerie.hlavicka', [
            'ucet' => $uzivatel === null ? null : [
                'id' => $uzivatel->id,
                'name' => $uzivatel->name,
                'email' => $uzivatel->email,
            ],
        ])->render();

        $misto = stripos($dokument, '</head>');

        return $misto === false
            ? $hlavicka.$dokument
            : substr($dokument, 0, $misto).$hlavicka.substr($dokument, $misto);
    }
}
