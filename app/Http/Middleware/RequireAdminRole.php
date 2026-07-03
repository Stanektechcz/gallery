<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireAdminRole
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (!$request->user() || !$request->user()->isAdmin()) {
            abort(403, 'Přístup zamítnut.');
        }

        return $next($request);
    }
}
