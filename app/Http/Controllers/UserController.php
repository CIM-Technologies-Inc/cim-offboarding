<?php

namespace App\Http\Controllers;

use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\Employee;
use App\Models\EmailTemplate;
use App\Models\SecurityLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * The 3 built-in roles the rest of the app still depends on BY NAME for
     * identity-scoped behavior that isn't itself permission-driven — the
     * post-login redirect (`AuthController::homeRouteFor()`,
     * `ChangePasswordController::update()`, `MenuHelper::homePath()`) and
     * the Employee Dashboard's own `role:employee` route gate. Used only to
     * pick a sensible "primary" value for the legacy `role` column when a
     * user holds one of these alongside others (or any custom role) — it
     * is NOT a restriction on which roles can be assigned; see
     * `updateRole()` below, which accepts any role that actually exists.
     */
    private const ROLE_PRIORITY = [User::ROLE_ADMIN, User::ROLE_EMPLOYEE, User::ROLE_APPROVER];

    /**
     * Role-assignment only — not full account CRUD. Lists every Employee
     * (not just those with a login account already — accounts are lazily
     * auto-provisioned, see `User::findOrCreateApprover()`/
     * `findOrCreateEmployee()`), so an admin can assign roles to a freshly
     * imported employee before they've ever needed to log in. An employee
     * with no account yet displays/defaults to the Employee role — exactly
     * what `findOrCreateEmployee()` would create if saved as-is — without
     * actually creating that account until the admin saves a change (see
     * `updateRole()`). The assignable options come straight from the
     * `roles` table, never a hardcoded list.
     */
    public function index(Request $request): View
    {
        $employees = Employee::with('user.roles')
            ->orderBy('name')
            ->get()
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employeeCode' => $employee->employee_code,
                'email' => $employee->email,
                'username' => $employee->user?->username,
                'hasAccount' => (bool) $employee->user,
                // "Active" requires a real account that ISN'T blocked;
                // "Inactive" covers both "no account at all" and "blocked"
                // — see User::isBlocked()'s own docblock for why
                // `blocked_at` alone is the single source of truth there.
                'isBlocked' => (bool) $employee->user?->isBlocked(),
                'accountStatus' => $employee->user && ! $employee->user->isBlocked() ? 'active' : 'inactive',
                'blockedAt' => $employee->user?->blocked_at?->format('M d, Y g:i A'),
                'failedLoginAttempts' => $employee->user?->failed_login_attempts ?? 0,
                'roles' => $employee->user
                    ? $employee->user->roles->pluck('name')->values()->all()
                    : [User::ROLE_EMPLOYEE],
            ]);

        // `?employee=<id>` — the specific-person deep link the "Account
        // Blocked" notification sends admins to (see
        // AccountBlockedNotification::toDatabase()), so clicking a
        // notification about ONE person filters straight to that one row
        // rather than the whole blocked list. `?status=blocked` is the
        // coarser fallback (used when a notification's account has no
        // linked Employee, or for a manual bookmark/link) — scoped to
        // actually blocked accounts specifically, not merely "no account"
        // (which `accountStatus === 'inactive'` would also match).
        $employeeIdFilter = $request->query('employee');
        $filteringBlocked = $request->query('status') === 'blocked';
        $filteredEmployeeName = null;

        if ($employeeIdFilter !== null) {
            $employees = $employees->filter(fn (array $user) => (string) $user['id'] === (string) $employeeIdFilter)->values();
            $filteredEmployeeName = $employees->first()['name'] ?? null;
        } elseif ($filteringBlocked) {
            $employees = $employees->filter(fn (array $user) => $user['isBlocked'])->values();
        }

        return view('pages.users.index', [
            'title' => 'Users',
            'users' => $employees,
            'roleOptions' => Role::where('guard_name', 'web')->orderBy('name')->pluck('name')->all(),
            'filteringBlocked' => $filteringBlocked && $filteredEmployeeName === null,
            'filteredEmployeeName' => $filteredEmployeeName,
            // For the Reactivate modal's "Notification Email Template"
            // picker — same `is_active` scope every other template dropdown
            // in this app uses (see `OffboardeeController::index()`).
            'emailTemplates' => EmailTemplate::where('is_active', true)
                ->orderBy('template_name')
                ->get(['id', 'template_name', 'subject', 'html_content', 'is_default_reactivation']),
        ]);
    }

    /**
     * Accepts any combination of any role that currently exists (validated
     * against the `roles` table itself, not a hardcoded list) — a newly
     * created custom role like "Test" is assignable the moment it exists,
     * with whatever permissions are attached to it at the time (and any
     * later, since Spatie's permission checks are always live — updating a
     * role's permissions afterward changes what every user holding that
     * role can do, automatically, with no per-user re-sync needed).
     * `EnsureUserHasRole`/Spatie's own `hasAnyRole()`/`can()` already treat
     * "has ANY of the assigned roles" as the gate everywhere in this app,
     * so holding several roles only ever grants the union of what each
     * role alone would allow — it can't conflict with or take away access
     * a single role would have had.
     */
    public function updateRole(Request $request, Employee $employee): RedirectResponse
    {
        $validated = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ]);

        $roles = array_values(array_unique($validated['roles']));

        // Checked BEFORE `findOrCreateEmployee()` runs — that call is itself
        // the "does an account already exist, else create one" logic (unique
        // on `username`, so this employee can never end up with two), but it
        // returns the account either way with no signal of which branch it
        // took. This flag is only used to decide which message/modal the
        // admin sees below — it never changes what account ends up existing.
        $accountExisted = User::where('username', $employee->employee_code_digits)->exists();

        $user = DB::transaction(function () use ($employee, $roles) {
            $user = User::findOrCreateEmployee($employee);
            $user->syncRoles($roles);
            $user->update(['role' => $this->primaryRole($roles)]);

            return $user;
        });

        $label = collect($roles)->map(fn ($role) => ucfirst($role))->implode(' + ');

        $accountNote = $accountExisted
            ? 'This employee already has an existing user account.'
            : 'A new user account was created for them.';

        $redirect = back()->with('success', "{$employee->name}'s role has been updated to \"{$label}\". {$accountNote}");

        if (! $accountExisted) {
            // The password is never read back off `$user` (it's hashed the
            // moment `findOrCreateEmployee()` saves it) — it's shown here
            // purely because `findOrCreateEmployee()`'s own convention
            // guarantees it equals `employee_code_digits`, which this
            // response already has in plain text regardless of the account.
            $redirect->with('newAccount', [
                'name' => $employee->name,
                'employeeCode' => $employee->employee_code,
                'username' => $user->username,
                'password' => $employee->employee_code_digits,
            ]);
        }

        return $redirect;
    }

    /**
     * The only way out of a block. Since this app never retains a
     * recoverable plaintext password (password is hashed at rest, and
     * `ForgotPasswordController` only ever emails a reset LINK, never a
     * password — see its own docblock), there is no real "current
     * password" to show the employee. Instead, a fresh secure temporary
     * password is generated here, stored only via the same `'hashed'`
     * cast every other password write in this app already uses, and
     * `must_change_password` is set so the employee is forced through the
     * existing first-login password-change flow
     * (`EnsurePasswordChanged`/`ChangePasswordController`) — no new
     * password-change mechanism needed. The temporary password is held
     * only in the local `$temporaryPassword` variable below: it is passed
     * to the email template render and the Mail object, and NEVER to
     * `Log::`/`SecurityLog::record()`.
     *
     * Gated by its own `users.manage-status` permission, separate from
     * `users.manage-roles`, since this is a distinct security-relevant
     * capability, not a role-assignment one.
     */
    public function reactivate(Request $request, Employee $employee): RedirectResponse
    {
        $user = $employee->user;

        abort_if(! $user, 404);

        if (! $user->isBlocked()) {
            return back()->with('error', "{$employee->name}'s account is not currently blocked.");
        }

        $validated = $request->validate([
            'email_template_id' => ['nullable', 'integer', 'exists:email_templates,id'],
        ]);

        $temporaryPassword = Str::password(12);

        DB::transaction(function () use ($user, $temporaryPassword) {
            $user->reactivate();
            $user->update([
                'password' => $temporaryPassword,
                'must_change_password' => true,
            ]);
        });

        // Reactivation itself is already committed above, BEFORE any email
        // is attempted — an email failure below can never roll this back.
        SecurityLog::record('account_reactivated', [
            'user_id' => $user->id,
            'performed_by_user_id' => auth()->id(),
        ]);

        $emailTemplate = ($validated['email_template_id'] ?? null)
            ? EmailTemplate::find($validated['email_template_id'])
            : EmailTemplate::activeDefaultReactivation();

        if (! $emailTemplate || ! $employee->email || ! filter_var($employee->email, FILTER_VALIDATE_EMAIL)) {
            Log::warning('Account reactivated but no notification email was sent (no template or no valid employee email).', [
                'user_id' => $user->id,
                'has_template' => (bool) $emailTemplate,
                'has_email' => (bool) $employee->email,
            ]);

            return back()->with('warning', "{$employee->name}'s account has been reactivated, but no notification email was sent (no email template or address available).");
        }

        [$subject, $body] = $emailTemplate->render(
            approverName: $employee->name,
            offboardeeName: $employee->name,
            username: $user->username,
            temporaryPassword: $temporaryPassword,
            accountStatus: 'Active',
            reactivatedAt: now()->format('M d, Y g:i A'),
        );

        try {
            Mail::to($employee->email)->send(new ChecklistSignatoryAnnouncementMail($subject, $body));

            SecurityLog::record('account_reactivation_email_sent', [
                'user_id' => $user->id,
                'performed_by_user_id' => auth()->id(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send account reactivation email.', [
                'user_id' => $user->id,
                'recipient' => $employee->email,
                'exception' => $e->getMessage(),
            ]);

            SecurityLog::record('account_reactivation_email_failed', [
                'user_id' => $user->id,
                'performed_by_user_id' => auth()->id(),
            ]);

            return back()->with('warning', "{$employee->name}'s account has been reactivated, but the notification email could not be sent. Please retry sending it.");
        }

        return back()->with('success', "{$employee->name}'s account has been reactivated and a notification email was sent.");
    }

    /**
     * Picks whichever of the 3 built-in roles ranks highest for the legacy
     * `role` column (see `ROLE_PRIORITY`'s docblock) — falls back to
     * whatever was submitted first when none of the 3 are present, e.g. a
     * user assigned only a custom role like "Test".
     *
     * @param  array<int, string>  $roles
     */
    private function primaryRole(array $roles): string
    {
        foreach (self::ROLE_PRIORITY as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        return $roles[0];
    }
}
