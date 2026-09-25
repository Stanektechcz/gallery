<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * Správce **vlastní** galerie (alias `spravce`).
 *
 * Rozhoduje role v prostoru, ne `users.role`. Tu (`owner`/`admin`) má každý
 * zaregistrovaný účet, takže účet bez galerie — bránou dvojice projde, protože
 * se bez prostoru přihlásit smí — zakládal přes pozvánku účty dalším lidem,
 * i když byla registrace zavřená. Provoz celé instalace hlídá `can:operator`.
 *
 * Prostor je týž, se kterým pak pracuje řadič: první prostor účtu (výchozí
 * napřed), stejně jako v `PristupDoGalerie::proc()`.
 */
class RequireAdminRole
{
    /** Role v prostoru, které smějí galerii spravovat (zvát další lidi). */
    private const ROLE_SPRAVCE = ['owner', 'admin'];

    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->spravujeGalerii($user)) {
            abort(403, 'Přístup zamítnut.');
        }

        return $next($request);
    }

    private function spravujeGalerii(User $user): bool
    {
        $prostor = $user->gallerySpaces()->first();

        if ($prostor === null) {
            return false;
        }

        // Vlastník prostoru je vlastník, i kdyby mu v členství zůstala výchozí role.
        return (int) $prostor->owner_id === (int) $user->id
            || in_array((string) $prostor->pivot->role, self::ROLE_SPRAVCE, true);
    }
}
