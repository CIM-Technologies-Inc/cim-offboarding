<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\LoginLinkToken;
use App\Models\User;
use App\Services\AutoLoginLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * The secure, single-use, one-hour "log me in automatically" link embedded
 * in offboarding-related emails — see `LoginLinkToken`/`AutoLoginLinkService`'s
 * own docblocks for how this differs from the three existing scoped
 * one-click approval-token flows (`ApprovalController`/
 * `GeneralSignatoryApprovalController`/`FinalApprovalController`'s own
 * `showEmailApproval()`/`confirmEmailApproval()` pairs) — this controller
 * mirrors their exact GET-show/POST-confirm shape (GET never mutates, so an
 * email scanner/prefetcher that opens the link can never burn it — only an
 * explicit POST from the confirmation page can), but instead of acting on
 * one specific record, it establishes a REAL authenticated session via
 * `Auth::login()`, exactly like `ImpersonationController::start()` already
 * does elsewhere in this app.
 */
class AutoLoginController extends Controller
{
    private const EMAIL_APPROVAL_MESSAGES = [
        'invalid' => 'Your automatic login link has expired. For your security, this link is valid for one hour only. Please return to the original email or contact the system administrator to obtain a new access link.',
        'blocked' => User::BLOCKED_MESSAGE,
        'already_different_session' => "You're currently signed in as a different account. Please log out first, then use this link again.",
        'confirm' => 'Please confirm to sign in to CIM Offboarding.',
    ];

    public function __construct(private AutoLoginLinkService $autoLoginLinkService) {}

    /**
     * Never mutates — only resolves and displays the link's current state.
     */
    public function show(int $id, string $token): View
    {
        $state = $this->resolveDisplayState($id, $token);

        return view('pages.auto-login.confirm', [
            'title' => 'Automatic Sign-In',
            'state' => $state,
            'message' => self::EMAIL_APPROVAL_MESSAGES[$state],
            'confirmUrl' => route('auto-login.confirm', ['id' => $id, 'token' => $token]),
        ]);
    }

    /**
     * The actual authentication, triggered only once the visitor confirms
     * on the page above. Re-resolves and re-locks the token row from
     * scratch — never trusts whatever `show()` rendered a moment earlier —
     * so a double-click, two tabs on the same link, or the window between
     * page-load and submission can never authenticate twice or race past
     * the single-use guarantee.
     */
    public function confirm(Request $request, int $id, string $token): RedirectResponse
    {
        $state = $this->resolveDisplayState($id, $token);

        if ($state !== 'confirm') {
            ActivityLog::record('auto_login_failed', 'Authentication', "Automatic login link rejected at confirmation — state: {$state}.", [
                'user' => LoginLinkToken::find($id)?->user,
                'status' => 'failed',
                'failure_reason' => $state,
            ]);

            return redirect()->route('auto-login.show', ['id' => $id, 'token' => $token]);
        }

        $redirectRoute = DB::transaction(function () use ($request, $id, $token) {
            $locked = LoginLinkToken::whereKey($id)->whereNull('used_at')->lockForUpdate()->first();

            if (! $locked || $locked->isExpired() || ! Hash::check($token, $locked->token)) {
                return null;
            }

            $user = $locked->user;

            if ($user->isBlocked()) {
                return null;
            }

            $locked->update(['used_at' => now()]);

            Auth::login($user);
            // Session-fixation defense — same call, same place in the
            // sequence (immediately after Auth::login()), as
            // `AuthController::store()` and `ImpersonationController::start()`
            // already use elsewhere in this app.
            $request->session()->regenerate();

            ActivityLog::record('auto_login', 'Authentication', "{$user->name} signed in automatically via an emailed access link.");

            // Mirrors AuthController::store()'s own must-change-password
            // gate so an auto-login session is held to exactly the same
            // rule a manual sign-in already is.
            if ($user->must_change_password) {
                return 'password.change';
            }

            return $locked->redirect_route ?? $user->defaultHomeRouteName();
        });

        if ($redirectRoute === null) {
            ActivityLog::record('auto_login_failed', 'Authentication', 'Automatic login link failed at confirmation — expired, already used, or the account is blocked.', [
                'user' => LoginLinkToken::find($id)?->user,
                'status' => 'failed',
                'failure_reason' => 'Link became invalid between page load and confirmation.',
            ]);

            return redirect()->route('auto-login.show', ['id' => $id, 'token' => $token]);
        }

        return redirect()->route($redirectRoute);
    }

    /**
     * Resolves the token's state AND whether a different, already-
     * authenticated user is the one viewing it — the one piece of
     * visitor-specific context `AutoLoginLinkService::resolveState()`
     * itself can't know, since it has no access to the current request's
     * own session. Never silently swaps out someone's existing session for
     * another account — the ticket's own "never allow one user's link to
     * authenticate another" and "handle an existing session without
     * disrupting it" requirements.
     */
    private function resolveDisplayState(int $id, string $token): string
    {
        [$state, $loginLinkToken] = $this->autoLoginLinkService->resolveState($id, $token);

        if ($state === 'confirm' && Auth::check() && Auth::id() !== $loginLinkToken->user_id) {
            return 'already_different_session';
        }

        return $state;
    }
}
