<?php

namespace App\Http\Controllers;

use App\Models\SecurityLog;
use App\Models\User;
use App\Notifications\AccountBlockedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
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

        // Looked up BEFORE Auth::attempt() purely to check blocked state —
        // never used to short-circuit a WRONG password with a different
        // message (that would leak whether the username exists). A
        // genuinely blocked account is the one deliberate exception to
        // that principle: the spec explicitly requires this distinct
        // message, and the account must reject even its own correct
        // password without ever touching the failed-attempt counter
        // again.
        $user = User::where('username', $credentials['username'])->first();

        if ($user?->isBlocked()) {
            SecurityLog::record('blocked_login_attempt', [
                'user_id' => $user->id,
                'attempted_username' => $credentials['username'],
            ]);

            throw ValidationException::withMessages([
                'username' => User::BLOCKED_MESSAGE,
            ]);
        }

        if (! Auth::attempt($credentials, $remember)) {
            if ($user) {
                $justBlocked = $user->recordFailedLoginAttempt();

                SecurityLog::record('failed_login', [
                    'user_id' => $user->id,
                    'attempted_username' => $credentials['username'],
                    'context' => ['attempt_number' => $user->failed_login_attempts],
                ]);

                if ($justBlocked) {
                    SecurityLog::record('account_blocked', ['user_id' => $user->id]);

                    // Never let a notification failure stop the lockout
                    // itself from taking effect — same safety net
                    // `ApprovalController::recordActivityAndNotify()`
                    // already uses around its own `Notification::send()`.
                    try {
                        Notification::send(User::role(User::ROLE_ADMIN)->get(), new AccountBlockedNotification($user));
                    } catch (\Throwable $e) {
                        Log::error('Failed to send account-blocked notification.', [
                            'user_id' => $user->id,
                            'exception' => $e->getMessage(),
                        ]);
                    }

                    throw ValidationException::withMessages([
                        'username' => User::BLOCKED_MESSAGE,
                    ]);
                }
            } else {
                // No account matches this username at all — logged for
                // monitoring only; the response below is byte-for-byte
                // identical to the "wrong password for a real account"
                // case, so this never discloses which usernames exist.
                SecurityLog::record('failed_login', ['attempted_username' => $credentials['username']]);
            }

            throw ValidationException::withMessages([
                'username' => __('These credentials do not match our records.'),
            ]);
        }

        $request->session()->regenerate();

        $user = Auth::user();
        $user->resetFailedLoginAttempts();
        SecurityLog::record('successful_login', ['user_id' => $user->id]);

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
