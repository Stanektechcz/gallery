<?php

namespace App\Http\Middleware;

use App\Support\TrasyPrototypu;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stránky starého rozhraní vedou do nové aplikace.
 *
 * Rozhodnutí 27. 9. 2026: „Stránky /prehled a starého rozhraní přesměrují
 * na /. API zůstane pro sdílené odkazy a staré klienty." Aplikací je od té
 * doby jen prototyp na `/`; staré stránky (React přes Inertia) se už nikde
 * nerozvíjely, a kdo se na ně dostal z uložené záložky, z upozornění nebo
 * z odkazu v e-mailu, viděl jinou aplikaci s jinými čísly.
 *
 * Tohle je **jediné** místo, které o přesměrování rozhoduje. Cesty ve
 * `routes/web.php` zůstávají zapsané, jak byly — jejich jména z nich čte
 * `route()` ve starých kontrolerech, upozorněních a testech, a přesměrování
 * na ně navázané (`route('budgets')` a spol.) tak dál vede sem a odsud
 * do aplikace. Kontroler starých stránek se už nespustí.
 *
 * Seznam je podle **jména cesty**, ne podle adresy: jméno patří jedné metodě,
 * takže `albums.index` je jen `GET /albums`, zatímco `POST /albums`
 * (`albums.store`) jde dál do kontroleru. Proto se nemusí nic rozlišovat
 * podle metody — kontrola `isMethodSafe()` je jen pojistka pro případ,
 * že by někdo jméno omylem dal i zápisu.
 *
 * Co zůstává, jak je (a proto tu **není**):
 * - `/s/{token}` a vše kolem sdílených odkazů — posílají se lidem bez účtu;
 * - `/forgot-password`, `/reset-password/{token}` — odkaz na ně vede
 *   z e-mailu a z přihlášení v aplikaci; `/invite/{token}` z pozvánky;
 * - `/login/overeni` — druhý krok přihlášení přes `POST /login`, které
 *   zůstává pro staré klienty; kdo je v půlce přihlášení, nesmí skončit jinde;
 * - `/registrace`, `/sluzba`, `/cenik` — veřejné stránky pro nepřihlášené,
 *   které aplikace nemá;
 * - `/app` — instalační centrum Androidu (odkazuje na ně
 *   `docs/ANDROID_DIRECT_DISTRIBUTION.md` a stažení APK se na ně vrací);
 * - `GET /share-target` — výběr alba po sdílení do PWA (`manifest.webmanifest`
 *   posílá `POST /share-target` a ten sem přesměruje);
 * - všechno kromě GET: zápisy starých klientů, `/api/*`, OAuth návraty,
 *   stažení a soubory (`/media/{uuid}/download|full|stream`, `/files/…`).
 *
 * Cíl je obrazovka prototypu, pokud ji má (`TrasyPrototypu`), jinak `/`.
 * Detail (jedno album, jedna fotka, jedna událost) vlastní adresu v aplikaci
 * nemá, takže vede na seznam, ze kterého se otevírá.
 */
class PresmerujStareRozhrani
{
    /**
     * Jméno cesty staré stránky => trasa prototypu (`TrasyPrototypu::ADRESY`),
     * `null` = kořen aplikace.
     *
     * @var array<string, string|null>
     */
    public const PRESMEROVANI = [
        // Vchod a rozcestník
        'login' => null,
        'dashboard' => null,
        'home' => null,

        // Fotky a alba
        'timeline.index' => 'timeline',
        'albums.index' => 'albums',
        'albums.create' => 'albums',
        'albums.show' => 'albums',
        'media.show' => 'all',
        'favorites' => 'favorites',
        'archive' => null,
        'trash' => 'trash',
        'recovery' => null,
        'inbox' => 'x-inbox',
        'memories' => 'x-vzpominky',
        'shared-memories' => 'x-vzpominky',
        'anniversary-album' => 'x-vyrocni',
        'people' => 'x-lide',
        'people.show-page' => 'x-lide',
        'tags' => 'x-tagy',
        'stats' => 'x-statistiky',
        'journey' => 'x-pribeh',
        'compare' => null,
        'curation' => null,
        'duplicates' => 'x-uklid',
        'tv' => 'x-promitani',
        'print' => 'x-tisk',
        'books.print' => 'x-tisk',
        'map' => 'map',
        'search' => null,
        'vault.index' => 'x-trezor',
        'shares.index' => 'shared',
        'activity' => 'activity',

        // Kalendář, plánování a společný život
        'calendar' => 'calendar',
        'calendar.events.show' => 'calendar',
        'planning' => 'x-plan',
        'weekly' => 'x-tyden',
        'journal' => 'x-denik',
        'chat' => 'x-zpravy',
        'voice-notes' => null,
        'together-now' => null,
        'milestones' => 'x-milniky',
        'date-ideas' => 'x-randicka',
        'gifts-anniversaries' => 'x-darky',
        'recipes.index' => 'x-kucharka',
        'recipes.show' => 'x-kucharka',
        'watchlist' => 'x-filmy',
        'watchlist.movies' => 'x-filmy',
        'watchlist.series' => 'x-filmy',
        'watchlist.movies.tierlist' => 'x-filmy',
        'watchlist.series.tierlist' => 'x-filmy',
        'cycle' => 'x-cyklus',
        'burps' => null,
        'farts' => null,

        // Cestování
        'trips' => 'x-cesty',
        'trips.plan' => 'x-cesty',
        'trips.now' => 'x-teď',
        'tickets' => 'x-cesty',
        'tickets.cs' => 'x-cesty',
        'travel-inbox' => 'x-inbox-cesty',
        'places' => 'x-mista',
        'places.show-page' => 'x-mista',
        'itinerary' => 'x-svet',

        // Finance. `rozpocet` a `kniha` dřív vedly na `/rozpocty`; tady rovnou,
        // bez dvou přesměrování za sebou.
        'budgets' => 'x-rozpocty',
        'rozpocet' => 'x-rozpocty',
        'ledger' => 'x-transakce',
        'finances' => 'x-finance',
        'finance' => 'x-finance',

        // Nastavení
        'navigation.settings' => 'settings',
        'connections' => 'settings',
        // Tarif a fakturace jsou v aplikaci v administraci prostoru.
        'invoices' => 'x-admin',
        'settings.appearance' => 'settings',
        'settings.subscription' => 'x-admin',
        'settings.security' => 'settings',
        'settings.automations' => 'x-pravidla',
        'settings.storage.google' => 'storage',
        'privacy' => 'settings',

        // Provoz instalace
        'admin.dashboard' => 'x-admin',
        'admin.storage-risk' => 'x-admin',
        'admin.plan-matrix' => 'x-admin',
        'admin.users' => 'x-admin',
        'admin.jobs' => 'x-admin',
        'admin.audit' => 'x-admin',
        'admin.health' => 'x-admin',
        'admin.integrations.index' => 'x-admin',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $jmeno = $request->route()?->getName();

        if ($jmeno === null || ! $request->isMethodSafe() || ! array_key_exists($jmeno, self::PRESMEROVANI)) {
            return $next($request);
        }

        $cil = self::cil($jmeno);

        /*
         * Otevřená záložka starého rozhraní klepe přes Inertia (XHR). Obyčejné
         * přesměrování by klient následoval a dostal HTML aplikace místo JSON
         * stránky — ukázal by ho v chybovém okně. `location` ho přiměje
         * načíst novou adresu celou.
         */
        if ($request->header('X-Inertia')) {
            return Inertia::location($cil);
        }

        return redirect($cil);
    }

    /** Kam vede stará stránka daného jména. */
    public static function cil(string $jmeno): string
    {
        return TrasyPrototypu::url(self::PRESMEROVANI[$jmeno] ?? 'home');
    }
}
