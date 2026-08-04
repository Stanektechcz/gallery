<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BillingPlan;
use App\Models\GallerySpace;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use App\Support\SpaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public sign-up. Each new customer gets their own gallery space on the default plan.
 *
 * Registration is off unless `gallery.registration_open` is enabled, so the instance can
 * stay invitation-only. Existing invitations are unaffected — those add a member to an
 * existing space, this creates a new one.
 */
class RegistrationController extends Controller
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function show(): Response|RedirectResponse
    {
        if (! config('gallery.registration_open')) {
            return redirect('/login')->with('error', 'Registrace je zavřená. Do galerie se vstupuje na pozvánku.');
        }

        return Inertia::render('Auth/Register');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(config('gallery.registration_open'), 403, 'Registrace je zavřená.');

        $data = $request->validate([
            'name'       => 'required|string|max:100',
            'email'      => 'required|email|max:190|unique:users,email',
            'space_name' => 'required|string|max:120',
            'password'   => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'uuid'                   => (string) Str::uuid(),
                'name'                   => $data['name'],
                'email'                  => $data['email'],
                'password'               => Hash::make($data['password']),
                // Owner of their own space. Instance administration stays separate.
                'role'                   => 'owner',
                'is_active'              => true,
                'invitation_accepted_at' => now(),
            ]);

            $space = GallerySpace::create([
                'uuid'      => (string) Str::uuid(),
                'name'      => $data['space_name'],
                'slug'      => $this->uniqueSlug($data['space_name']),
                'owner_id'  => $user->id,
                'is_default' => true,
            ]);
            $space->members()->attach($user->id, ['role' => 'owner', 'can_delete' => true, 'can_share' => true, 'joined_at' => now()]);

            $plan = BillingPlan::where('is_default', true)->first();
            if ($plan) $this->entitlements->assignPlan($space, $plan, $user);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();
        // Membership changed inside this request; the cached space ids must not survive it.
        SpaceContext::forget();

        AuditLog::record('auth.registered', $user, ['email' => $user->email]);

        return redirect('/')->with('success', 'Vítejte! Váš prostor je připravený.');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'prostor';
        $slug = $base;
        $suffix = 2;
        while (GallerySpace::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }
}
