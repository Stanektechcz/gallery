<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records that the signed-in person is still around.
 *
 * Throttled to one write a minute, because otherwise a page with a polling chat would
 * write to the users table several times a second for no extra information. The check is
 * against the value already loaded on the model, so the throttle itself costs no query.
 *
 * The update deliberately bypasses Eloquent: touching the model would bump updated_at and
 * fire events, and "was here a moment ago" is not a change to the user's record.
 */
class TrackLastSeen
{
    private const EVERY_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && Schema::hasColumn('users', 'last_seen_at')) {
            $last = $user->last_seen_at;

            if (! $last || $last->diffInSeconds(now()) >= self::EVERY_SECONDS) {
                DB::table('users')->where('id', $user->id)->update(['last_seen_at' => now()]);
                $user->last_seen_at = now();
            }
        }

        return $next($request);
    }
}
