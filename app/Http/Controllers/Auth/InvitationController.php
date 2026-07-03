<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $user = User::where('invitation_token', $token)
            ->whereNull('invitation_accepted_at')
            ->first();

        if (!$user) {
            return redirect('/login')->with('error', 'Pozvánka je neplatná nebo již byla použita.');
        }

        return Inertia::render('Auth/Invitation', ['token' => $token, 'name' => $user->name]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $user = User::where('invitation_token', $token)
            ->whereNull('invitation_accepted_at')
            ->first();

        if (!$user) {
            return redirect('/login')->with('error', 'Pozvánka je neplatná.');
        }

        $validated = $request->validate([
            'password'              => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required|string',
        ]);

        $user->update([
            'password'               => Hash::make($validated['password']),
            'invitation_accepted_at' => now(),
            'invitation_token'       => null,
            'email_verified_at'      => now(),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        AuditLog::record('auth.invitation.accepted', $user);

        return redirect('/timeline')->with('success', 'Vítejte! Váš účet byl aktivován.');
    }
}
