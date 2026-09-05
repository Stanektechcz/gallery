<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        /*
         * Routy prototypu Galerie se přidávají bez prefixu — `api` si nesou samy.
         * Kdyby šly přes `api:`, vznikla by adresa `/api/api/state`; prototyp má
         * `/api/state` napevno v `galerie-api.js` a ten se podle zadání nemění.
         */
        then: fn () => Route::middleware('web')->group(base_path('routes/galerie.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\TrackLastSeen::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // The chat polls here, so presence stays current while someone reads it.
        $middleware->api(append: [
            \App\Http\Middleware\TrackLastSeen::class,
        ]);

        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        // The payment gateway posts server-to-server and carries no session token.
        $middleware->validateCsrfTokens(except: [
            'platby/comgate/notifikace',
        ]);

        $middleware->alias([
            'can:admin' => \App\Http\Middleware\RequireAdminRole::class,
            'module'    => \App\Http\Middleware\EnsureModuleEnabled::class,
            'feature'   => \App\Http\Middleware\EnsureModuleEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * `sanctum/*` je tu kvůli přihlášení prototypu.
         *
         * Klient volá `POST /sanctum/token` a čeká JSON — čte `b.message` z těla.
         * Bez téhle cesty by mu Laravel na chybnou validaci poslal přesměrování
         * s HTML a klient by hlásil neurčitou chybu místo „E-mail nebo heslo
         * nesouhlasí". Ostatní cesty zůstávají, jak byly.
         */
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*') || $request->is('sanctum/*'),
        );
    })->create();
