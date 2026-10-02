<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountNotBlocked
{
    /**
     * Covers "blocked users cannot access protected pages through direct
     * URLs" — including the edge case of an already-open session on one
     * device while the SAME account gets blocked via failed attempts on
     * another: the moment this blocked session tries to go anywhere, it's
     * force-logged-out and sent back to sign in with the same blocked
     * message `AuthController::store()` already shows. Applied to the
     * whole `auth` middleware group in routes/web.php (same pattern as
     * `EnsurePasswordChanged`), so every route inside it is covered
     * automatically. `logout` is exempt so a blocked user can still
     * explicitly sign out without this middleware fighting that redirect.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isBlocked() && ! $request->routeIs('logout')) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'username' => User::BLOCKED_MESSAGE,
            ]);
        }

        return $next($request);
    }
}
