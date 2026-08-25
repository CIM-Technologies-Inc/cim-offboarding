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
