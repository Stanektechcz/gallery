<?php

namespace App\Http\Middleware;

use App\Models\MediaItem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProtectVaultMedia
{
    public function handle(Request $request, Closure $next): Response
    {
        $uuid = $request->route('uuid');
        if (! is_string($uuid) || ! $request->user()) {
            return $next($request);
        }

        /*
         * Podle samotné fotky, ne podle „prvního" prostoru.
         *
         * Hledalo se jen v prostoru, který databáze vrátila jako první. Fotka
         * z trezoru v druhém prostoru téhož účtu (rodinné album, do kterého
         * přijal pozvánku) tu tak nebyla vidět jako skrytá a vydala se bez
         * odemčení. Kdo k fotce vůbec smí, rozhoduje kontrolér; tady jde jen
         * o zámek trezoru.
         */
        $isHidden = MediaItem::withoutGlobalScopes()
            ->where('uuid', $uuid)
            ->where('is_hidden', true)
            ->exists();

        // Požadavek jen s tokenem (klíč k API, aplikace) sezení nemá, a tedy ani
        // odemčený trezor — dřív tu spadl na „Session store not set" s chybou 500.
        $odemceno = $request->hasSession()
            && (int) $request->session()->get('vault_unlocked_until', 0) > now()->timestamp;

        if ($isHidden && ! $odemceno) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Trezor je uzamčený.'], 423);
            }

            return redirect()->route('vault.index');
        }

        return $next($request);
    }
}
