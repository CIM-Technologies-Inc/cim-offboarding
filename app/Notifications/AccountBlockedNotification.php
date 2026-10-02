<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Fired once, at the moment an account hits its 3rd failed login attempt
 * (see `AuthController::store()`, right after the `account_blocked`
 * `SecurityLog` entry) — fanned out to every admin via
 * `Notification::send(User::role(User::ROLE_ADMIN)->get(), ...)`, the
 * same "notify all admins" pattern `OffboardingApprovalUpdated` already
 * uses. Links straight to the Users page, where the new "Reactivate"
 * action lives.
 */
class AccountBlockedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public User $blockedUser,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'account_blocked';
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $employee = $this->blockedUser->employee;
        $employeeName = $employee?->name ?? $this->blockedUser->name;

        return [
            'blocked_user_id' => $this->blockedUser->id,
            'blocked_employee_id' => $employee?->id,
            'blocked_username' => $this->blockedUser->username,
            'blocked_at' => $this->blockedUser->blocked_at?->format('M d, Y g:i A'),
            'message' => "{$employeeName}'s account has been blocked after 3 failed login attempts.",
            // Deep-links straight to this ONE employee's row (not merely
            // "show every blocked account") — every account in this app is
            // always created from an Employee (see
            // `User::findOrCreateEmployee()` etc.), so `$employee` is only
            // ever null for a non-Employee-backed account (e.g. a seeded
            // super-admin); that edge case falls back to the general
            // "blocked accounts" filter instead of a dead link.
            //
            // Relative (absolute: false), unlike this app's other
            // notification URLs — those are only ever generated from a
            // console command's scheduler run (no "current host" to speak
            // of, so they fall back to APP_URL by necessity), while this
            // one fires from `AuthController::store()`, a real HTTP
            // request. Using an absolute URL here would bake in whatever
            // APP_URL happens to be configured, which can silently diverge
            // from the host the admin is actually browsing on (e.g. local
            // dev reached via 127.0.0.1:8000 while APP_URL says
            // "localhost") — landing the click on a different origin,
            // where the session cookie isn't sent, bouncing them to login.
            'url' => $employee
                ? route('users.index', ['employee' => $employee->id], false)
                : route('users.index', ['status' => 'blocked'], false),
        ];
    }
}
