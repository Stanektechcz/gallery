<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WebauthnCredential;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetController extends Controller
{
    /** Stejné pravidlo jako při změně hesla v aplikaci (`ProfileController::password`). */
    public const NEJKRATSI_HESLO = 10;

    /** Důvody z brokeru česky — aplikace nemá překlady a `__()` vracelo angličtinu. */
    private const DUVODY = [
        Password::INVALID_TOKEN => 'Odkaz na nové heslo už neplatí — nechte si poslat nový.',
        Password::INVALID_USER => 'Odkaz na nové heslo už neplatí — nechte si poslat nový.',
        Password::RESET_THROTTLED => 'Chvíli počkejte a zkuste to znovu.',
    ];

    public function request(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'emailyChodi' => self::emailyChodi(),
        ]);
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => 'required|email']);

        try {
            Password::sendResetLink($request->only('email'));
        } catch (\Throwable $e) {
            // Nedoručitelný e-mail nesmí prozradit, jestli účet existuje.
            report($e);
        }

        // Stejná odpověď pro existující i neexistující adresu — jinak by šlo zjišťovat, kdo tu má účet.
        return back()->with('success', 'Pokud k té adrese účet existuje, poslali jsme na ni odkaz na nové heslo.');
    }

    public function reset(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->input('email'),
            'nejkratsi' => self::NEJKRATSI_HESLO,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|string|min:'.self::NEJKRATSI_HESLO.'|confirmed',
            'password_confirmation' => 'required',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                /*
                 * Zapomenuté heslo může být i heslo, které zná někdo jiný.
                 *
                 * Přihlášená zařízení zůstávala přihlášená i po obnovení hesla —
                 * kdo se k účtu dostal, měl ho dál. Odhlásí se všechno: klíče
                 * aplikace i sezení starého rozhraní. Aplikace na odhlášení
                 * reaguje přihlašovací obrazovkou.
                 */
                $user->tokens()->delete();
                // Otisky taky: otisk vydá nový token sám, takže kdo si k účtu
                // připojil vlastní, byl by po obnově hesla hned zpátky.
                WebauthnCredential::zrusKromeTokenu($user);
                if (Schema::hasTable('sessions')) {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }

                AuditLog::record('auth.password.reset', $user);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => self::DUVODY[$status] ?? 'Heslo se nepodařilo změnit — nechte si poslat nový odkaz.']);
        }

        // Do aplikace, ne do starého rozhraní: přihlašovací obrazovka řekne, že heslo je nové.
        return redirect('/?heslo=zmeneno');
    }

    /**
     * Chodí z aplikace e-maily?
     *
     * S ovladačem `log` nebo `array` formulář tvrdil „odkaz jsme poslali"
     * a nikdy nic nepřišlo.
     */
    public static function emailyChodi(): bool
    {
        return ! in_array((string) config('mail.default'), ['log', 'array'], true);
    }
}
