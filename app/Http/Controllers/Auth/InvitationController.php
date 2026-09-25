<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Trezor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    /**
     * Pozvánka platí týden od odeslání.
     *
     * Odkaz bez data platil navždy: starý e-mail v cizí schránce (nebo předaný
     * odkaz, který nikdo nepoužil) byl pořád cesta k nastavení hesla.
     */
    public const PLATNOST_DNI = 7;

    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $user = $this->cekajici($token);

        if (! $user) {
            return redirect('/login')->with('error', 'Pozvánka je neplatná, vypršela, nebo již byla použita.');
        }

        return Inertia::render('Auth/Invitation', ['token' => $token, 'name' => $user->name]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $user = $this->cekajici($token);

        if (! $user) {
            return redirect('/login')->with('error', 'Pozvánka je neplatná nebo vypršela.');
        }

        $validated = $request->validate([
            // Stejné minimum jako obnova a změna hesla (`PasswordResetController::NEJKRATSI_HESLO`).
            'password' => 'required|string|min:'.PasswordResetController::NEJKRATSI_HESLO.'|confirmed',
            'password_confirmation' => 'required|string',
        ]);

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'invitation_accepted_at' => now(),
            'invitation_token' => null,
            'invitation_sent_at' => null,
            'email_verified_at' => now(),
        ])->save();

        Auth::login($user);
        $request->session()->regenerate();
        // Odemčený trezor předchozího člověka v prohlížeči nezůstane — viz `Trezor`.
        Trezor::zamkni($request);

        AuditLog::record('auth.invitation.accepted', $user);

        return redirect('/timeline')->with('success', 'Vítejte! Váš účet byl aktivován.');
    }

    /** Pozvánka, která ještě čeká a nevypršela. Bez data odeslání neplatí. */
    private function cekajici(string $token): ?User
    {
        return User::where('invitation_token', $token)
            ->whereNull('invitation_accepted_at')
            ->whereNotNull('invitation_sent_at')
            ->where('invitation_sent_at', '>', now()->subDays(self::PLATNOST_DNI))
            ->first();
    }
}
