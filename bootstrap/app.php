<?php

use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\JenDvojice;
use App\Http\Middleware\PreventRequestForgery;
use App\Http\Middleware\RequireAdminRole;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrackLastSeen;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        /*
         * Routy prototypu Galerie se přidávají bez prefixu — `api` si nesou samy.
         * Kdyby šly přes `api:`, vznikla by adresa `/api/api/state`; prototyp má
         * `/api/state` napevno v `galerie-api.js` a ten se podle zadání nemění.
         */
        then: fn () => Route::middleware('web')->group(base_path('routes/galerie.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [
            HandleInertiaRequests::class,
            TrackLastSeen::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // The chat polls here, so presence stays current while someone reads it.
        $middleware->api(append: [
            TrackLastSeen::class,
        ]);

        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        /*
         * The payment gateway posts server-to-server and carries no session token.
         *
         * `sanctum/token` je tu proto, že vydává token proti heslu — kdo ho volá,
         * ještě žádný nemá. Prototyp ani nativní klient z README na něj token
         * proti CSRF neposílají a v prohlížeči to procházelo jen díky hlavičce
         * `Sec-Fetch-Site`; mimo prohlížeč se přihlásit nešlo vůbec.
         *
         * `webhooks/google-drive` posílá Google ze serveru, bez sezení i tokenu
         * proti CSRF — skutečná upozornění dostávala 419. Pravost hlídá token
         * kanálu v `GoogleDriveWebhookController`.
         */
        $middleware->validateCsrfTokens(except: [
            'platby/comgate/notifikace',
            'sanctum/token',
            'webhooks/google-drive',
        ]);

        // Zápisy s platným tokenem CSRF nepotřebují — viz třída.
        // `replaceInGroup`, ne `replace`: ochrana je ve skupině `web`, ne v globální řadě.
        $middleware->replaceInGroup(
            'web',
            Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            PreventRequestForgery::class,
        );

        $middleware->alias([
            // Ne `can:admin` — to Laravel čte jako bránu `admin` (users.role)
            // a tenhle alias by se nikdy nepoužil.
            'spravce' => RequireAdminRole::class,
            'module' => EnsureModuleEnabled::class,
            'feature' => EnsureModuleEnabled::class,
            'dvojice' => JenDvojice::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * `sanctum/*` je tu kvůli přihlášení prototypu.
         *
         * Klient volá `POST /sanctum/token` a čeká JSON — čte `b.message` z těla.
         * Bez téhle cesty by mu Laravel na chybnou validaci poslal přesměrování
         * s HTML a klient by hlásil neurčitou chybu místo „E-mail nebo heslo
         * nesouhlasí".
         *
         * Vlastní podmínka nahrazuje výchozí Laravelovu `expectsJson()`, proto ji
         * vracíme i pro webové cesty. Bez ní dostal `axios` na `PATCH /albums/{uuid}`
         * při chybě přesměrování, prohlížeč ho tiše následoval na 200 s HTML
         * a nastavení alba hlásilo uložení, které neproběhlo.
         *
         * Inertia chce přesměrování s chybami v sezení, ne JSON — vylučuje se
         * výslovně, i když dnes posílá `Accept: text/html`.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || $request->is('sanctum/*')
                || ($request->expectsJson() && ! $request->header('X-Inertia')),
        );
    })->create();
