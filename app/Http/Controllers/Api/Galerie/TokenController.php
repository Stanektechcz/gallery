<?php

namespace App\Http\Controllers\Api\Galerie;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\DruhyFaktor;
use App\Services\Auth\PristupDoGalerie;
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
    public function __construct(
        private readonly DruhyFaktor $druhyFaktor,
        private readonly PristupDoGalerie $pristup,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:64'],
            'code' => ['nullable', 'string', 'max:20'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            // Stejný zápis jako u přihlašovací stránky: pokus o cizí účet je
            // nejužitečnější řádek v protokolu, neznámá adresa zůstává anonymní.
            AuditLog::record('auth.login.failed', $user, ['email' => $data['email'], 'cesta' => 'aplikace']);

            // Stejná hláška pro neznámý e-mail i špatné heslo — endpoint
            // neprozradí, kdo v aplikaci je.
            throw ValidationException::withMessages(['email' => 'E-mail nebo heslo nesouhlasí.']);
        }

        /*
         * Heslo sedí, ale to ještě neznamená vstup.
         *
         * Token se vydával každému se správným heslem — i účtu, kterému správce
         * odebral přístup (stačilo se přihlásit znovu), a i s vypnutým druhým
         * faktorem, takže dvoufázové ověření platilo jen na stránce `/login`.
         */
        if (($duvod = $this->pristup->proc($user)) !== null) {
            throw ValidationException::withMessages(['email' => $duvod]);
        }

        if ($this->druhyFaktor->zapnuty($user)) {
            if (($sekund = $this->druhyFaktor->blokovano($user)) > 0) {
                throw ValidationException::withMessages(['code' => 'Příliš mnoho pokusů o kód. Zkuste to za '.$sekund.' s.'])
                    ->status(429);
            }

            if (($data['code'] ?? '') === '') {
                // Klient podle `two_factor` pozná, že se má zeptat na kód.
                return response()->json([
                    'message' => 'Zadejte kód z ověřovací aplikace.',
                    'two_factor' => true,
                    'errors' => ['code' => ['Zadejte kód z ověřovací aplikace.']],
                ], 422);
            }

            if (! $this->druhyFaktor->over($user, $data['code'])) {
                return response()->json([
                    'message' => 'Kód nesouhlasí.',
                    'two_factor' => true,
                    'errors' => ['code' => ['Kód nesouhlasí.']],
                ], 422);
            }
        }

        // Jedno zařízení = jeden token. Nové přihlášení to staré zneplatní.
        $user->tokens()->where('name', $data['device_name'])->delete();

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        AuditLog::record('auth.login', $user, ['cesta' => 'aplikace', 'zarizeni' => $data['device_name']]);

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
