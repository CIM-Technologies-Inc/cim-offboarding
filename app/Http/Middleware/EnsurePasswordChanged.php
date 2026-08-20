<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    /**
     * Redirects any authenticated user still on their temporary password
     * (see AuthController::store()'s first-time-login detection, and
     * User::findOrCreateApprover()) to the forced Change Password page,
     * before they can reach any other protected route. Applied to the
     * whole `auth` middleware group in routes/web.php, so every route
     * inside it is covered automatically — including one entered directly
     * by URL — with nothing to remember per-route. The change-password
     * routes themselves (`password.change`, `password.change.update`) are
     * exempt so the user can actually reach and submit the form, and
     * `logout` is exempt so they can still sign out instead of being stuck.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->must_change_password
            && ! $request->routeIs('password.change*')
            && ! $request->routeIs('logout')) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
