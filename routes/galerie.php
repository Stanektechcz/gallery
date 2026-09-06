<?php

use App\Http\Controllers\Api\CalendarPlanningController;
use App\Http\Controllers\Api\Galerie\AdminController;
use App\Http\Controllers\Api\Galerie\MechanismController;
use App\Http\Controllers\Api\Galerie\MediaController;
use App\Http\Controllers\Api\Galerie\StateController;
use App\Http\Controllers\Api\Galerie\StorageController;
use App\Http\Controllers\Api\Galerie\TokenController;
use App\Http\Controllers\Api\Galerie\WebauthnController;
use Illuminate\Support\Facades\Route;

/*
 * Routy prototypu Galerie.
 *
 * Registrují se BEZ prefixu — `api` si nesou samy. Přidat je pod `api` by
 * znamenalo `/api/api/state` a klient by nenašel nic; prototyp má adresu
 * napevno v `galerie-api.js` a ten se podle zadání nemění.
 *
 * Stav patří páru, ne uživateli: oba partneři čtou a píší tentýž záznam.
 */
// Přihlášení je vstupní cesta, proto tvrdší limit než na zbytek.
Route::middleware(['throttle:20,1'])->post('sanctum/token', [TokenController::class, 'store'])
    ->name('galerie.token.store');

/*
 * Otisk před přihlášením.
 *
 * Tyhle dvě cesty musí být přístupné bez tokenu — jde o zamčenou aplikaci,
 * ve které ještě nikdo přihlášený není. Challenge se drží v sezení a cache,
 * takže odpověď nejde přehrát; limit je stejně tvrdý jako u hesla.
 */
Route::middleware(['throttle:20,1'])->prefix('api/webauthn')->group(function () {
    Route::post('login/options', [WebauthnController::class, 'loginOptions'])->name('galerie.webauthn.login.options');
    Route::post('login', [WebauthnController::class, 'login'])->name('galerie.webauthn.login');
});

Route::middleware(['auth:sanctum', 'throttle:120,1'])->prefix('api')->group(function () {
    Route::get('state', [StateController::class, 'show'])->name('galerie.state.show');
    Route::patch('state', [StateController::class, 'update'])->name('galerie.state.update');
    Route::delete('state', [StateController::class, 'destroy'])->name('galerie.state.destroy');

    Route::post('logout', [TokenController::class, 'destroy'])->name('galerie.logout');

    Route::get('mechanisms', [MechanismController::class, 'index'])->name('galerie.mechanisms');

    // Čísla pro postranní panel — vidí je každý, na rozdíl od administrace.
    Route::get('storage', StorageController::class)->name('galerie.storage');

    /*
     * Odběr upozornění.
     *
     * Míří rovnou na kontroler, který tuhle tabulku obsluhuje pro zbytek
     * aplikace. Druhá implementace téhož by znamenala dvě místa, kde se dá
     * zapomenout smazat odběr odhlášeného zařízení — a tělo požadavku je
     * shodné, prototyp posílá `PushSubscription` z prohlížeče tak, jak je.
     */
    Route::post('push/subscribe', [CalendarPlanningController::class, 'storePushSubscription'])
        ->name('galerie.push.subscribe');
    Route::delete('push/subscribe', [CalendarPlanningController::class, 'destroyPushSubscription'])
        ->name('galerie.push.unsubscribe');

    /*
     * Administrace prostoru.
     *
     * Každá akce vrací celý přehled znovu — obrazovka se překresluje z databáze,
     * ne z toho, co si klient myslí, že se stalo.
     */
    Route::prefix('admin')->name('galerie.admin.')->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');

        Route::post('users', [AdminController::class, 'invite'])->name('users.invite');
        Route::post('users/{id}/resend', [AdminController::class, 'resend'])->name('users.resend');
        Route::patch('users/{id}/role', [AdminController::class, 'role'])->name('users.role');
        Route::post('users/{id}/transfer', [AdminController::class, 'transfer'])->name('users.transfer');
        Route::post('users/{id}/access', [AdminController::class, 'access'])->name('users.access');

        Route::post('keys', [AdminController::class, 'storeKey'])->name('keys.store');
        Route::post('keys/{id}/regenerate', [AdminController::class, 'regenerateKey'])->name('keys.regenerate');
        Route::delete('keys/{id}', [AdminController::class, 'destroyKey'])->name('keys.destroy');

        Route::post('jobs/{uloha}/run', [AdminController::class, 'runJob'])->name('jobs.run');
        Route::post('jobs/{uloha}/pause', [AdminController::class, 'pauseJob'])->name('jobs.pause');

        Route::post('health/check', [AdminController::class, 'healthCheck'])->name('health.check');
        Route::post('plan', [AdminController::class, 'plan'])->name('plan');
        Route::post('risks/{riziko}/fix', [AdminController::class, 'fixRisk'])->name('risks.fix');
    });

    // Klíč se registruje až přihlášenému člověku — jinak by si otisk k účtu
    // připojil kdokoli, kdo zná e-mail.
    Route::post('webauthn/register/options', [WebauthnController::class, 'registerOptions'])
        ->name('galerie.webauthn.register.options');
    Route::post('webauthn/register', [WebauthnController::class, 'register'])
        ->name('galerie.webauthn.register');
});

/*
 * Média.
 *
 * Mimo skupinu výš kvůli limitu: nahrávání velkého videa po osmimegabajtových
 * částech je klidně sto požadavků za sebou a do 120 za minutu se nevejde.
 * Adresy i hlavičky jsou dané prototypem (`galerie-api.js`) a nemění se.
 */
Route::middleware(['auth:sanctum', 'throttle:600,1'])->prefix('api')->group(function () {
    Route::post('media', [MediaController::class, 'store'])->name('galerie.media.store');
    Route::post('media/chunk', [MediaController::class, 'chunk'])->name('galerie.media.chunk');
    Route::get('media/{uuid}/raw', [MediaController::class, 'raw'])->name('galerie.media.raw');
    Route::delete('media/{uuid}', [MediaController::class, 'destroy'])->name('galerie.media.destroy');
});
