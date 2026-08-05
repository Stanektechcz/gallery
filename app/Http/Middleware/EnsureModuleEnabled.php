<?php

namespace App\Http\Middleware;

use App\Services\Billing\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for paid add-on modules. Applied as `module:burps` on a route group.
 *
 * A locked module answers 402 with the module code, so the frontend can offer the
 * upgrade instead of showing a bare error.
 */
class EnsureModuleEnabled
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function handle(Request $request, Closure $next, string $moduleCode): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        $space = $user->gallerySpaces()->orderByDesc('is_default')->first();
        abort_unless($space, 403, 'Účet zatím nemá vlastní prostor.');

        // Entitlement, not preference: a customer who has merely hidden a feature from
        // their menu must still reach it by URL, otherwise switching it off would look
        // like data loss. Only a genuine lack of entitlement returns 402.
        if (! $this->entitlements->isEntitled($space, $moduleCode)) {
            abort(402, 'Tenhle modul není ve vašem tarifu aktivní.');
        }

        return $next($request);
    }
}
