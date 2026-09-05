<?php

use App\Http\Controllers\Api\Galerie\StateController;
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

    // Klíč se registruje až přihlášenému člověku — jinak by si otisk k účtu
    // připojil kdokoli, kdo zná e-mail.
    Route::post('webauthn/register/options', [WebauthnController::class, 'registerOptions'])
        ->name('galerie.webauthn.register.options');
    Route::post('webauthn/register', [WebauthnController::class, 'register'])
        ->name('galerie.webauthn.register');
});
