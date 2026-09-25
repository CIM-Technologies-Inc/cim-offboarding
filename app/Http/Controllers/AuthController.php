<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function create(): RedirectResponse|\Illuminate\View\View
    {
        if (Auth::check()) {
            $user = Auth::user();

            return redirect()->route($user->must_change_password ? 'password.change' : $this->homeRouteFor($user));
        }

        return view('pages.auth.signin', ['title' => 'Sign In']);
    }

    /**
     * Reached only via the "{{offboarding_link}}" token in the "Offboarding
     * Details Notification – Employee" email, and only when that email was
     * sent for a brand-new account (see
     * `ChecklistApprovalNotifier::notifyOffboardee()`) — pre-fills the
     * Username/Password fields on the sign-in page so a first-time
     * offboardee doesn't have to retype what the same email already showed
     * them in plain text.
     *
     * `$employee` is `employee_code_digits` — this app's own established
     * username value (`User::findOrCreateEmployee()` etc.), never a
     * separate secret minted for this feature. No dedicated token/expiry
     * table backs this route: the account's own `must_change_password`
     * flag is already the single authoritative signal for "is the emailed
     * temporary password (== username, by this app's convention) still
     * valid" — reusing it here keeps the link's own validity in perfect,
     * automatic lockstep with reality instead of a second, independently-
     * drifting expiry. The instant the real password is set,
     * `must_change_password` flips to `false` and this link silently stops
     * pre-filling anything, behaving exactly like a plain visit to
     * `/signin` — no separate revocation step needed.
     */
    public function autoFill(string $employee): RedirectResponse|\Illuminate\View\View
    {
        if (Auth::check()) {
            return $this->create();
        }

        $user = User::where('username', $employee)->first();

        if (! $user || ! $user->must_change_password) {
            return view('pages.auth.signin', ['title' => 'Sign In']);
        }

        return view('pages.auth.signin', [
            'title' => 'Sign In',
            // Password === username by this app's own first-login
            // convention (see `User::findOrCreateEmployee()`) — never
            // stored/looked up separately.
            'prefillUsername' => $user->username,
            'prefillPassword' => $user->username,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $remember = $request->boolean('remember');

        if (! Auth::attempt($credentials, $remember)) {
            throw ValidationException::withMessages([
                'username' => __('These credentials do not match our records.'),
            ]);
        }

        $request->session()->regenerate();

        $user = Auth::user();

        // This app's established convention (see User::findOrCreateApprover())
        // creates accounts with password === username as a temporary
        // first-login password. Logging in with that still-unchanged
        // password IS what "first-time login" means here — flagged every
        // time it's detected (not just once at account creation) so it
        // also covers an admin manually resetting someone's password back
        // to their username later.
        if (! $user->must_change_password && strcasecmp($credentials['password'], $credentials['username']) === 0) {
            $user->update(['must_change_password' => true]);
        }

        if ($user->must_change_password) {
            return redirect()->route('password.change');
        }

        return redirect()->intended(route($this->homeRouteFor($user)));
    }

    /**
     * The approver role can't see the admin dashboard, so it lands on
     * Approvals instead; the Employee role can't see either, so it lands on
     * its own dashboard.
     */
    private function homeRouteFor(User $user): string
    {
        return match (true) {
            $user->isAdmin() => 'dashboard',
            $user->isEmployee() => 'employee.dashboard',
            default => 'approvals.index',
        };
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
