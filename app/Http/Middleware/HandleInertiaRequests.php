<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? [
                    'id'         => $request->user()->id,
                    'uuid'       => $request->user()->uuid,
                    'name'       => $request->user()->name,
                    'email'      => $request->user()->email,
                    'role'       => $request->user()->role,
                    'is_active'  => $request->user()->is_active,
                    'interface_density' => (is_array($request->user()->preferences) ? ($request->user()->preferences['interface_density'] ?? null) : null),
                    'theme' => (is_array($request->user()->preferences) ? ($request->user()->preferences['theme'] ?? null) : null),
                ] : null,
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error'   => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
            ],
            // Which features this space may see. Lazily resolved so it costs nothing on
            // pages that never read it, and degrades to null before the migrations run.
            'features' => fn () => $this->activeFeatures($request),
            'ziggy' => fn() => [
                ...(new \Tighten\Ziggy\Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ];
    }

    /**
     * Feature codes the current space has switched on, used by the navigation to hide
     * what the customer does not want or is not entitled to.
     *
     * Returns null when there is nobody signed in or the catalogue has not been migrated
     * yet; the frontend treats null as "show everything" so nothing disappears mid-upgrade.
     *
     * @return list<string>|null
     */
    private function activeFeatures(Request $request): ?array
    {
        $user = $request->user();
        if (! $user || ! \Illuminate\Support\Facades\Schema::hasTable('features')) return null;

        $space = $user->gallerySpaces()->orderByDesc('is_default')->first();
        if (! $space) return null;

        $entitlements = app(\App\Services\Billing\EntitlementService::class);
        $entitled = $entitlements->entitledFeatures($space);

        return collect($entitled)
            ->filter(fn (string $code) => $entitlements->hasFeature($space, $code))
            ->values()->all();
    }
}
