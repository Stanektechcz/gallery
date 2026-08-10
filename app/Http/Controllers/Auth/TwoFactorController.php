<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setting up a second factor, and getting past it.
 *
 * Two halves that must not be confused. Setup happens while signed in and is protected by
 * the password. The challenge happens when nobody is signed in at all — the session holds
 * an id and nothing else — so everything it touches is looked up rather than trusted.
 */
class TwoFactorController extends Controller
{
    /** Six digits is 10^6; without a limit it is an afternoon's work. */
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly TotpService $totp)
    {
    }

    // ─── Setup, while signed in ─────────────────────────────────────

    /**
     * Produces a secret and shows it once.
     *
     * Nothing is switched on here. The secret is stored but unconfirmed, and the account
     * still signs in with a password alone until a correct code proves the authenticator
     * actually holds it — otherwise a mistyped scan locks somebody out of their own
     * account with no way back.
     */
    public function begin(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate(['current_password' => 'required|string']);

        if (! Hash::check($request->string('current_password')->toString(), $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Zadané heslo nesouhlasí.']);
        }

        abort_if((bool) $user->two_factor_confirmed_at, 422, 'Dvoufázové ověření už je zapnuté.');

        $secret = $this->totp->generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();

        return response()->json([
            'secret' => $secret,
            'uri' => $this->totp->provisioningUri($secret, $user->email),
        ]);
    }

    /** Switches it on, and hands over the way back in. */
    public function confirm(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate(['code' => 'required|string|max:10']);

        abort_unless($user->two_factor_secret, 422, 'Nejdřív si nechte vygenerovat kód.');

        if (! $this->totp->verify($user->two_factor_secret, $request->string('code')->toString())) {
            throw ValidationException::withMessages(['code' => 'Kód nesouhlasí. Zkontrolujte čas na telefonu.']);
        }

        $codes = $this->totp->recoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            // Hashed, like passwords: a recovery code is a password that works once.
            'two_factor_recovery_codes' => array_map(fn (string $code) => Hash::make($code), $codes),
        ])->save();

        AuditLog::record('auth.2fa.enabled', $user);

        // The only time these are ever readable. After this they exist only as hashes.
        return response()->json(['recovery_codes' => $codes]);
    }

    public function disable(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate(['current_password' => 'required|string']);

        if (! Hash::check($request->string('current_password')->toString(), $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Zadané heslo nesouhlasí.']);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        AuditLog::record('auth.2fa.disabled', $user);

        return response()->json(['enabled' => false]);
    }

    // ─── The challenge, while signed out ────────────────────────────

    public function challenge(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('two_factor.user_id')) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    /**
     * Accepts either the app's code or one recovery code.
     *
     * A used recovery code is removed, not marked: a one-time code that survives being
     * used is a password, and a list of them in a drawer is a password nobody rotates.
     */
    public function verify(Request $request): RedirectResponse
    {
        $id = $request->session()->get('two_factor.user_id');
        if (! $id) return redirect()->route('login');

        $request->validate(['code' => 'required|string|max:20']);

        $key = 'two-factor:' . $id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            return back()->withErrors(['code' => 'Příliš mnoho pokusů. Zkuste to za ' . $seconds . ' s.']);
        }

        $user = User::find($id);
        if (! $user || ! $user->two_factor_secret) {
            $request->session()->forget(['two_factor.user_id', 'two_factor.remember']);

            return redirect()->route('login');
        }

        $code = $request->string('code')->toString();

        if (! $this->totp->verify($user->two_factor_secret, $code) && ! $this->consumeRecoveryCode($user, $code)) {
            RateLimiter::hit($key, 300);
            AuditLog::record('auth.2fa.failed', $user);

            return back()->withErrors(['code' => 'Kód nesouhlasí.']);
        }

        RateLimiter::clear($key);

        $remember = (bool) $request->session()->pull('two_factor.remember', false);
        $request->session()->forget('two_factor.user_id');

        Auth::login($user, $remember);
        $request->session()->regenerate();

        $user->update(['last_login_at' => now(), 'last_login_ip' => $request->ip()]);
        AuditLog::record('auth.login', $user, ['second_factor' => true]);

        return redirect()->intended('/timeline');
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $hashes = (array) $user->two_factor_recovery_codes;

        foreach ($hashes as $index => $hash) {
            if (! Hash::check($code, $hash)) continue;

            unset($hashes[$index]);
            $user->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();
            AuditLog::record('auth.2fa.recovery_used', $user, ['remaining' => count($hashes)]);

            return true;
        }

        return false;
    }
}
