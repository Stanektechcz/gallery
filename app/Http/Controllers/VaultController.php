<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\MediaItem;
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
        if (! Hash::check($data['password'], $request->user()->password)) {
            return back()->withErrors(['password' => 'Heslo není správné.']);
        }
        $request->session()->put('vault_unlocked_until', now()->addMinutes(15)->timestamp);
        AuditLog::record('vault.unlock');
        return redirect()->route('vault.index');
    }

    public function lock(Request $request): RedirectResponse
    {
        $request->session()->forget('vault_unlocked_until');
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
        AuditLog::record($media->is_hidden ? 'vault.add' : 'vault.remove', $media, ['filename' => $media->original_filename]);

        return response()->json(['is_hidden' => $media->is_hidden]);
    }

    private function isUnlocked(Request $request): bool
    {
        return (int) $request->session()->get('vault_unlocked_until', 0) > now()->timestamp;
    }
}
