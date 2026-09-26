<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\MediaItem;
use App\Services\Provoz\PokusyOvereni;
use App\Support\Trezor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class VaultController extends Controller
{
    public function index(Request $request): Response
    {
        if (! $this->isUnlocked($request)) {
            return Inertia::render('Vault/Gate');
        }
        $space = $request->user()->gallerySpaces()->first();
        $media = MediaItem::where('gallery_space_id', $space->id)
            ->where('is_hidden', true)->whereNull('trashed_at')
            ->with(['variants' => fn ($query) => $query->whereIn('type', ['thumbnail', 'placeholder'])])
            ->orderByDesc('taken_at')->paginate(60);

        return Inertia::render('Vault/Index', ['media' => $media]);
    }

    public function unlock(Request $request): RedirectResponse
    {
        $data = $request->validate(['password' => 'required|string']);
        $kdo = $request->user();

        // Tytéž pokusy jako v galerii — jinak by se její uzavření obešlo tudy.
        if (($blok = PokusyOvereni::blokDo($kdo, 'trezor')) > 0) {
            return back()->withErrors(['password' => 'Přístup je uzavřený. Zkuste to za '.$blok.' s.']);
        }

        if (! Hash::check($data['password'], $kdo->password)) {
            $chyba = PokusyOvereni::chyba($kdo, 'trezor');
            AuditLog::record('vault.unlock_failed', null, ['pokus' => $chyba['pokusu']]);

            return back()->withErrors(['password' => $chyba['blok'] > 0
                ? 'Tři neúspěšné pokusy. Přístup je '.PokusyOvereni::naJakDlouho($chyba['blok']).' uzavřený.'
                : 'Heslo není správné.']);
        }
        Trezor::odemkni($request, 15 * 60);
        PokusyOvereni::uspech($kdo, 'trezor');
        AuditLog::record('vault.unlock');

        return redirect()->route('vault.index');
    }

    public function lock(Request $request): RedirectResponse
    {
        // Výslovné zamčení — stejně jako v galerii zavře trezor všude (viz `Trezor`).
        Trezor::zamkniVsude($request);

        return redirect()->route('vault.index');
    }

    public function toggle(Request $request, string $uuid): JsonResponse
    {
        $space = $request->user()->gallerySpaces()->first();
        $media = MediaItem::where('uuid', $uuid)->where('gallery_space_id', $space->id)->firstOrFail();
        if ($media->is_hidden && ! $this->isUnlocked($request)) {
            return response()->json(['message' => 'Trezor je uzamčený.'], 423);
        }
        $media->update(['is_hidden' => ! $media->is_hidden]);
        // Bez jména souboru: přehled „Dnes" jména z protokolu vypisuje i se
        // zamčeným trezorem, takže by prozradil, co se právě schovalo. Předmět
        // (id položky) zůstává — jako u trvalého smazání.
        AuditLog::record($media->is_hidden ? 'vault.add' : 'vault.remove', $media);

        return response()->json(['is_hidden' => $media->is_hidden]);
    }

    /** Odemčení patří člověku, ne prohlížeči — viz `Trezor`. */
    private function isUnlocked(Request $request): bool
    {
        return Trezor::odemcen($request);
    }
}
