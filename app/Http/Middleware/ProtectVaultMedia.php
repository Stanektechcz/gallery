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

        $spaceId = $request->user()->gallerySpaces()->first()?->id;
        $isHidden = MediaItem::where('uuid', $uuid)
            ->where('gallery_space_id', $spaceId)
            ->where('is_hidden', true)
            ->exists();

        if ($isHidden && (int) $request->session()->get('vault_unlocked_until', 0) <= now()->timestamp) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Trezor je uzamčený.'], 423);
            }
            return redirect()->route('vault.index');
        }

        return $next($request);
    }
}

