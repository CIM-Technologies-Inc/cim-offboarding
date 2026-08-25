<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request. Backed by Spatie's `hasAnyRole()` instead
     * of a raw string comparison against the legacy `role` column — every
     * `Route::middleware('role:...')` group across the app keeps working
     * verbatim, since the route syntax (comma-separated role names) is
     * unchanged, only what backs the check.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! $request->user()?->hasAnyRole($roles)) {
            abort(403);
        }

        return $next($request);
    }
}
