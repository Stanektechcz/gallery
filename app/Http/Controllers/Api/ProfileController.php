<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The account itself: who you are, how you sign in.
 *
 * Separate from user preferences, which are about how the app looks. These change
 * identity or access, so each is confirmed with the current password — a stolen session
 * should not be enough to take the account over by moving the email address.
 */
class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'avatar_url' => $user->avatar_url,
            'avatar_fallback' => $user->avatar_fallback,
            'read_only_mode' => (bool) $user->read_only_mode,
            'created_at' => $user->created_at?->toIso8601String(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ]);
    }

    /** Name and email. Changing the address needs the password, changing a name does not. */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->read_only_mode, 403, 'V režimu pouze pro čtení nelze měnit profil.');

        $data = $request->validate([
            'name' => 'required|string|min:2|max:120',
            'email' => ['required', 'email', 'max:190', Rule::unique('users')->ignore($user->id)],
            'current_password' => 'nullable|string',
        ]);

        if ($data['email'] !== $user->email) {
            $this->confirmPassword($request, $data['current_password'] ?? null);
        }

        $user->update(['name' => $data['name'], 'email' => $data['email']]);

        return $this->show($request);
    }

    public function password(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->read_only_mode, 403, 'V režimu pouze pro čtení nelze měnit heslo.');

        $data = $request->validate([
            'current_password' => 'required|string',
            // Confirmed, because a typo in a new password locks you out of your own account.
            'password' => 'required|string|min:10|confirmed',
        ]);

        $this->confirmPassword($request, $data['current_password']);

        $user->update(['password' => $data['password']]);

        // Everything else stays signed in on purpose: revoking is a separate, deliberate
        // act on the same screen, and doing it silently here would surprise people.
        return response()->json(['changed' => true]);
    }

    private function confirmPassword(Request $request, ?string $password): void
    {
        if (! $password || ! Hash::check($password, $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Zadané heslo nesouhlasí.',
            ]);
        }
    }
}
