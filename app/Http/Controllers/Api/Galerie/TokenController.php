<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Přihlášení e-mailem a heslem → osobní token (Sanctum). Klient si ho drží
 * a posílá v hlavičce; kód aplikace (PIN) je druhý faktor na zařízení,
 * ne autentizace proti serveru.
 */
class TokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:64'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            // Stejná hláška pro neznámý e-mail i špatné heslo — endpoint
            // neprozradí, kdo v aplikaci je.
            throw ValidationException::withMessages(['email' => 'E-mail nebo heslo nesouhlasí.']);
        }

        // Jedno zařízení = jeden token. Nové přihlášení to staré zneplatní.
        $user->tokens()->where('name', $data['device_name'])->delete();

        return response()->json([
            'token' => $user->createToken($data['device_name'])->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    /**
     * Odhlášení.
     *
     * Ruší se token **i sezení.** Routy prototypu běží ve skupině `web`, takže
     * vedle tokenu může existovat i přihlášené sezení — a to token nezneplatní.
     * Bez druhého kroku by se člověk odhlásil, dostal by potvrzení a aplikace by
     * ho dál pouštěla dovnitř.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        if ($request->hasSession()) {
            auth('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['status' => 'signed-out']);
    }
}
