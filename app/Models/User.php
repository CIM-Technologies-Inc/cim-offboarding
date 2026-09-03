<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'role',
        'email',
        'password',
        'mobile_number',
        'position',
        'department',
        'address',
        'signature_path',
        'profile_photo_path',
        'must_change_password',
        'combine_assigned_checklists',
    ];

    public const ROLE_ADMIN = 'admin';
    public const ROLE_APPROVER = 'approver';
    public const ROLE_EMPLOYEE = 'employee';

    /**
     * Backed by Spatie's `HasRoles` trait rather than the plain `role`
     * column below — every existing caller of `isAdmin()`/`isApprover()`/
     * `isEmployee()` across the app keeps working unchanged, since the
     * decision now comes from `model_has_roles` instead of a raw string
     * comparison, without any of those call sites needing to change.
     */
    public function isAdmin(): bool
    {
        return $this->hasRole(self::ROLE_ADMIN);
    }

    public function isApprover(): bool
    {
        return $this->hasRole(self::ROLE_APPROVER);
    }

    public function isEmployee(): bool
    {
        return $this->hasRole(self::ROLE_EMPLOYEE);
    }

    /**
     * Whether this account has a usable e-signature on file — the single
     * gate every checklist/General Signatory approval action (in-app and
     * via an emailed approval link alike) must pass before it's allowed to
     * finalize, so an approval can never be recorded without a real
     * signature backing it. See `ApprovalController`/`GeneralSignatoryApprovalController`.
     */
    public function hasUsableSignature(): bool
    {
        return filled($this->signature_path);
    }

    /**
     * The employee record this account belongs to, matched by the
     * convention that `username` equals `employee_code_digits` — the
     * employee's own `employee_code` with its "EMP" prefix stripped (see
     * `Employee::stripEmpPrefix()`/`booted()`) — never `employee_code`
     * directly, since usernames no longer include that prefix.
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class, 'employee_code_digits', 'username');
    }

    /**
     * A root-relative URL (not an absolute one built from the static
     * `APP_URL` config) so it resolves correctly regardless of the actual
     * host/port the app is served from — matching how every other image in
     * this app is referenced (e.g. `/images/user/owner.png`).
     */
    public function signatureUrl(): ?string
    {
        return $this->signature_path ? '/storage/'.$this->signature_path : null;
    }

    /**
     * Unlike `signatureUrl()`, this always returns a usable URL — falling
     * back to the app's existing default avatar image (the same one every
     * user's avatar showed before this feature existed) when no photo has
     * been uploaded — so every call site (header dropdown, profile card,
     * anywhere else an avatar is shown) can use this directly without its
     * own null-check/placeholder logic.
     */
    public function profilePhotoUrl(): string
    {
        return $this->profile_photo_path ? '/storage/'.$this->profile_photo_path : '/images/user/owner.png';
    }

    /**
     * Finds the existing approver account for this employee, or creates one
     * using the established convention: username = password =
     * `employee_code_digits` (the employee number with its "EMP" prefix
     * stripped — see `Employee::stripEmpPrefix()`). Never promotes/downgrades
     * an existing account's role. A freshly created account is flagged
     * `must_change_password` immediately (not left to be detected only on
     * their first login) since password === username is true by
     * construction here.
     *
     * Sets BOTH the legacy `role` column and the Spatie role — deliberately
     * dual-written (not a one-time migration cutover) so the plain column
     * stays accurate for anything that might still read it directly, while
     * `hasRole()`/`assignRole()` become the actual source of truth `isAdmin()`
     * etc. consult.
     */
    public static function findOrCreateApprover(Employee $employee): self
    {
        $user = static::firstWhere('username', $employee->employee_code_digits);

        if ($user) {
            return $user;
        }

        $user = static::create([
            'name' => $employee->name,
            'username' => $employee->employee_code_digits,
            'password' => $employee->employee_code_digits,
            'role' => self::ROLE_APPROVER,
            'email' => $employee->email,
            'must_change_password' => true,
        ]);

        $user->assignRole(self::ROLE_APPROVER);

        return $user;
    }

    /**
     * Finds the existing account for this employee, or creates one with the
     * Employee role using the same established convention as
     * `findOrCreateApprover()` above (username = password =
     * `employee_code_digits`, flagged `must_change_password` immediately).
     * Never promotes/downgrades an existing account's role — an employee
     * who already has an account (e.g. as someone else's approver) keeps
     * that role rather than being switched to Employee, same rule
     * `findOrCreateApprover()` already follows. Dual-writes the legacy
     * `role` column and the Spatie role — see `findOrCreateApprover()`'s
     * docblock for why.
     */
    public static function findOrCreateEmployee(Employee $employee): self
    {
        $user = static::firstWhere('username', $employee->employee_code_digits);

        if ($user) {
            return $user;
        }

        $user = static::create([
            'name' => $employee->name,
            'username' => $employee->employee_code_digits,
            'password' => $employee->employee_code_digits,
            'role' => self::ROLE_EMPLOYEE,
            'email' => $employee->email,
            'must_change_password' => true,
        ]);

        $user->assignRole(self::ROLE_EMPLOYEE);

        return $user;
    }

    /**
     * Finds the existing account for this General Signatory (Clearance
     * Signatory), or creates one using the same established convention as
     * `findOrCreateApprover()`/`findOrCreateEmployee()` above (username =
     * password = `employee_code_digits`, flagged `must_change_password`
     * immediately). Never promotes/downgrades an existing account's role —
     * a General Signatory who already has an account (e.g. as a department
     * head elsewhere, or already holding the Approver role) keeps whatever
     * they already have untouched.
     *
     * `$shouldBeApprover` decides the role ONLY for a freshly created
     * account: Approver when this General Signatory has active employees
     * under them (Employee Master group), Employee otherwise — see
     * `ChecklistApprovalNotifier::notifyGeneralSignatories()` for how that's
     * determined. This is the one place this factory family's role isn't
     * hardcoded to a single constant, since a General Signatory isn't
     * inherently either role the way a checklist department head or a
     * plain offboardee is.
     */
    public static function findOrCreateGeneralSignatory(Employee $employee, bool $shouldBeApprover): self
    {
        $user = static::firstWhere('username', $employee->employee_code_digits);

        if ($user) {
            return $user;
        }

        $role = $shouldBeApprover ? self::ROLE_APPROVER : self::ROLE_EMPLOYEE;

        $user = static::create([
            'name' => $employee->name,
            'username' => $employee->employee_code_digits,
            'password' => $employee->employee_code_digits,
            'role' => $role,
            'email' => $employee->email,
            'must_change_password' => true,
        ]);

        $user->assignRole($role);

        return $user;
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'combine_assigned_checklists' => 'boolean',
        ];
    }

}
