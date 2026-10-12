<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Login As User" — lets an admin holding the `users.login-as` permission
 * (route-gated in routes/web.php, same `permission:` middleware convention
 * as every other sensitive action in this app, e.g. UserController's own
 * role/status routes) authenticate directly as an employee's account for
 * troubleshooting/verification, without ever knowing or resetting that
 * employee's password.
 *
 * Deliberately a plain session-swap (`Auth::login()` + a same-browser-wide
 * `impersonator_id` marker in the session), the same architecture every
 * common Laravel impersonation package uses — NOT two independently live
 * sessions. A browser's session cookie is shared across every tab/window
 * of that browser, so "two simultaneous sessions" isn't actually possible
 * here; what IS possible, and what this delivers, is: the admin's own tab
 * stays on the Users page until it next navigates, the impersonated
 * session opens in a brand-new tab (the `target="_blank"` login-as form in
 * users/index.blade.php), and `<x-common.impersonation-banner>` (included
 * in every page via layouts/app.blade.php whenever `impersonator_id` is
 * present) is reachable from EITHER tab to swap back to the admin's own
 * account — see `stop()` below.
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, Employee $employee): RedirectResponse
    {
        $admin = $request->user();
        $targetUser = $employee->user;

        abort_if(! $targetUser, 404, 'This employee does not have a user account yet.');

        if ($targetUser->id === $admin->id) {
            return back()->with('error', 'You cannot start an impersonation session on your own account.');
        }

        if ($targetUser->isBlocked()) {
            return back()->with('error', "{$employee->name}'s account is blocked and cannot be logged into.");
        }

        // Blocks chaining ("login as A, then from A's session login as B")
        // — the banner's "Return to My Account" is the only way out of an
        // impersonation session, and it always restores the ORIGINAL admin,
        // never an intermediate one.
        if ($request->session()->has('impersonator_id')) {
            return back()->with('error', 'You are already impersonating a user. Return to your own account first before starting another impersonation session.');
        }

        // Logged while `auth()->user()` is still the admin themselves —
        // ActivityLog::record()'s default actor snapshot (name, employee
        // number, roles) must reflect the ADMIN who initiated this, not the
        // employee about to be impersonated.
        ActivityLog::record('impersonation_started', 'Users', "{$admin->name} started an impersonation session as {$targetUser->name}.", [
            'subject_type' => 'User',
            'subject_id' => $targetUser->id,
        ]);

        Auth::login($targetUser);
        // Rotates the session ID on this privilege change (same reasoning
        // AuthController::store() already applies after a real login) while
        // keeping the session's data — including the impersonator_id this
        // sets right after — intact, since regenerate() only migrates the
        // ID by default, never clears attributes.
        $request->session()->regenerate();

        $request->session()->put('impersonator_id', $admin->id);
        $request->session()->put('impersonator_name', $admin->name);
        $request->session()->put('impersonation_started_at', now()->toDateTimeString());

        return redirect()->route($targetUser->defaultHomeRouteName());
    }

    /**
     * The only way out of an impersonation session — restores the ORIGINAL
     * admin captured in `impersonator_id` at `start()` time, regardless of
     * what the impersonated account's own permissions are (an impersonated
     * user never independently gains access to this route's effect; they
     * can only ever restore the admin who put them there).
     */
    public function stop(Request $request): RedirectResponse
    {
        $adminId = $request->session()->get('impersonator_id');

        abort_unless($adminId, 403, 'You are not currently impersonating anyone.');

        $admin = User::find($adminId);
        $impersonatedUser = $request->user();

        abort_if(! $admin, 403, 'The original admin account could no longer be found.');

        ActivityLog::record('impersonation_ended', 'Users', "{$admin->name} ended the impersonation session as {$impersonatedUser->name}.", [
            'user' => $admin,
            'subject_type' => 'User',
            'subject_id' => $impersonatedUser->id,
        ]);

        Auth::login($admin);
        $request->session()->regenerate();
        $request->session()->forget(['impersonator_id', 'impersonator_name', 'impersonation_started_at']);

        return redirect()->route($admin->defaultHomeRouteName());
    }
}
