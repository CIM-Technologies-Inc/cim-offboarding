<?php

namespace App\Services;

use App\Models\LoginLinkToken;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Generates and resolves the secure, single-use, one-hour "log me in"
 * links embedded in offboarding-related emails — see `LoginLinkToken`'s
 * own docblock for how this differs from the three existing scoped
 * one-click approval tokens. The only two things any caller needs: mint a
 * link for a specific recipient (`generate()`), and resolve whichever
 * state a visiting id/token pair is currently in (`resolveState()`) —
 * every other detail (hashing, expiry, single-use enforcement) lives here
 * so no second implementation of this logic can drift from it.
 */
class AutoLoginLinkService
{
    private const LINK_LIFETIME_MINUTES = 60;

    /**
     * Mints a fresh link for `$user` and returns the full URL to embed in
     * an email. Deliberately does NOT invalidate this user's other
     * outstanding links (unlike `ForgotPasswordController::issueResetLink()`)
     * — several independent notifications can legitimately be in flight
     * for the same person at once (e.g. a checklist reminder and a General
     * Signatory notice), and each is single-use entirely on its own.
     *
     * `$redirectRoute` is a route NAME (e.g. `'approvals.index'`), never a
     * raw URL — left null to fall back to `$user->defaultHomeRouteName()`
     * at confirm time, which already resolves to the correct landing page
     * for every recipient type this feature needs (admin/offboardee/every
     * other role, same mapping normal sign-in already uses).
     */
    public function generate(User $user, ?string $redirectRoute = null): string
    {
        $rawToken = Str::random(64);

        $loginLinkToken = LoginLinkToken::create([
            'user_id' => $user->id,
            'token' => Hash::make($rawToken),
            'redirect_route' => $redirectRoute,
            'expires_at' => now()->addMinutes(self::LINK_LIFETIME_MINUTES),
        ]);

        return route('auto-login.show', ['id' => $loginLinkToken->id, 'token' => $rawToken]);
    }

    /**
     * Resolves the given id/token pair to one of: `'confirm'` (valid,
     * ready to authenticate), `'invalid'` (not found, expired, OR already
     * used — deliberately collapsed into one state/message, same
     * convention the three existing approval-token flows already use via
     * their own `EMAIL_APPROVAL_MESSAGES['invalid']`, so a failed attempt
     * never discloses *why* it failed), or `'blocked'` (a real, unexpired,
     * unused link for an account `User::isBlocked()`).
     *
     * @return array{0: string, 1: ?LoginLinkToken}
     */
    public function resolveState(int $id, string $rawToken): array
    {
        $loginLinkToken = LoginLinkToken::find($id);

        if (! $loginLinkToken || ! $loginLinkToken->isValid() || ! Hash::check($rawToken, $loginLinkToken->token)) {
            return ['invalid', null];
        }

        if ($loginLinkToken->user->isBlocked()) {
            return ['blocked', $loginLinkToken];
        }

        return ['confirm', $loginLinkToken];
    }
}
