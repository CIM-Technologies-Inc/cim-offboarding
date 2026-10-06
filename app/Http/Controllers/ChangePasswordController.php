<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ChangePasswordController extends Controller
{
    public function edit(): View
    {
        return view('pages.auth.change-password', ['title' => 'Change Password']);
    }

    /**
     * Sets the user's real password, hashed automatically via the model's
     * `'password' => 'hashed'` cast, and clears `must_change_password` so
     * `EnsurePasswordChanged` stops redirecting them here — that flag is
     * the ONLY thing gating access to the rest of the app for this user,
     * so this is what actually restores normal navigation.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

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

        $user->update([
            'password' => $validated['password'],
            'must_change_password' => false,
        ]);

        ActivityLog::record('password_change', 'Authentication', "{$user->name} set their password.", ['user' => $user]);

        $homeRoute = match (true) {
            $user->isAdmin() => 'dashboard',
            $user->isEmployee() => 'employee.dashboard',
            default => 'approvals.index',
        };

        return redirect()
            ->route($homeRoute)
            ->with('success', 'Your password has been changed successfully.');
    }
}
