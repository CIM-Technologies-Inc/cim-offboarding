<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetMail;
use App\Models\PasswordResetRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ForgotPasswordController extends Controller
{
    private const TOKEN_LIFETIME_MINUTES = 5;

    /**
     * Sends a reset link for every account matching the given email —
     * `users.email` isn't unique in this app (several employees share one
     * on record), so a submitted email can legitimately match more than one
     * account; each gets its own token/link rather than arbitrarily picking
     * one. Always shows the same generic confirmation regardless of whether
     * anything actually matched, so this endpoint can't be used to probe
     * which emails are registered.
     */
    public function sendResetLink(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $users = User::where('email', $validated['email'])->get();

        foreach ($users as $user) {
            $this->issueResetLink($user);
        }

        return back()->with('success', 'If an account with that email exists, a password reset link has been sent to it.');
    }

    /**
     * Invalidates this user's own previously-issued, still-unused links
     * before creating a new one — only the most recently requested link
     * should ever be honored. The raw token is emailed and never persisted;
     * only its hash is stored, mirroring Laravel's own password-broker
     * convention (see DatabaseTokenRepository).
     */
    private function issueResetLink(User $user): void
    {
        if (! $user->email || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        PasswordResetRequest::where('user_id', $user->id)->whereNull('used_at')->delete();

        $rawToken = Str::random(64);

        $resetRequest = PasswordResetRequest::create([
            'user_id' => $user->id,
            'token' => Hash::make($rawToken),
            'expires_at' => now()->addMinutes(self::TOKEN_LIFETIME_MINUTES),
        ]);

        $resetUrl = route('password.reset', ['id' => $resetRequest->id, 'token' => $rawToken]);

        try {
            Mail::to($user->email)->send(new PasswordResetMail($user->name, $resetUrl));
        } catch (\Throwable $e) {
            Log::error('Failed to send password reset email.', [
                'user_id' => $user->id,
                'recipient' => $user->email,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function showResetForm(int $id, string $token): View
    {
        $resetRequest = PasswordResetRequest::find($id);

        return view('pages.auth.reset-password', [
            'title' => 'Reset Password',
            'valid' => $resetRequest !== null && $resetRequest->isValid() && Hash::check($token, $resetRequest->token),
            'id' => $id,
            'token' => $token,
        ]);
    }

    /**
     * Re-validates the link (expiry/used-state can lapse between the page
     * loading and this submission) before honoring it. On success, the
     * token is immediately marked used — and every other still-outstanding
     * link for this same user is invalidated too, so only ever one reset
     * request can succeed per "forgot password" episode.
     */
    public function reset(Request $request, int $id, string $token): RedirectResponse
    {
        $resetRequest = PasswordResetRequest::find($id);

        if (! $resetRequest || ! $resetRequest->isValid() || ! Hash::check($token, $resetRequest->token)) {
            return redirect()
                ->route('password.reset', ['id' => $id, 'token' => $token])
                ->with('error', 'This password reset link has expired. Please request a new password reset link.');
        }

        $user = $resetRequest->user;

        $validated = $request->validate([
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                function ($attribute, $value, $fail) use ($user) {
                    if (strcasecmp($value, $user->username) === 0) {
                        $fail('Your new password cannot be the same as your username.');
                    }
                },
            ],
        ]);

        DB::transaction(function () use ($user, $validated) {
            $user->update([
                'password' => $validated['password'],
                'must_change_password' => false,
            ]);

            PasswordResetRequest::where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
        });

        return redirect()->route('login')->with('success', 'Your password has been reset successfully. Please sign in with your new password.');
    }
}
