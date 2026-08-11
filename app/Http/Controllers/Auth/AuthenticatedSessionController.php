<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($credentials, $request->boolean('remember'))) {
            // Attached to the account when the address matches one, so the owner can see
            // that somebody tried — which is the single most useful line in a security
            // log, and was previously recorded against nobody. A miss stays anonymous:
            // an unknown address belongs to no account by definition.
            $target = \App\Models\User::where('email', $credentials['email'])->first();

            AuditLog::record('auth.login.failed', $target, ['email' => $credentials['email']]);
            return back()->withErrors(['email' => 'Nesprávné přihlašovací údaje.']);
        }

        $user = Auth::user();

        if (!$user->is_active) {
            Auth::logout();
            return back()->withErrors(['email' => 'Váš účet není aktivní.']);
        }

        // The password was right, but it is not the whole answer yet. Signing out again
        // and remembering only the id keeps the half-finished attempt out of the session
        // as an authenticated user — a second factor that leaves you logged in while it
        // asks is not a second factor.
        if ($user->two_factor_confirmed_at && $user->two_factor_secret) {
            $remember = $request->boolean('remember');
            Auth::logout();

            $request->session()->put('two_factor.user_id', $user->id);
            $request->session()->put('two_factor.remember', $remember);

            return redirect()->route('two-factor.challenge');
        }

        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        AuditLog::record('auth.login', $user);

        $request->session()->regenerate();

        return redirect()->intended('/timeline');
    }

    public function destroy(Request $request): RedirectResponse
    {
        AuditLog::record('auth.logout', $request->user());

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
