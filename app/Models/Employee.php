<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    /** @use HasFactory<\Database\Factories\EmployeeFactory> */
    use HasFactory;

    /**
     * Designations eligible to be picked as a checklist template's department head.
     */
    public const MANAGEMENT_DESIGNATIONS = ['Head', 'Senior Manager', 'Manager'];

    protected $fillable = [
        'employee_code',
        'name',
        'firstName',
        'lastName',
        'middleName',
        'email',
        'personal_email',
        'department',
        'employee_group_id',
        'is_task_assignee',
        'designation',
        'position',
        'sup_one',
        'sup_two',
        'head',
        'head_employee_id',
        'date_of_joining',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'date_of_joining' => 'date',
            'is_task_assignee' => 'boolean',
        ];
    }

    /**
     * Keeps `employee_code_digits` — the "EMP" prefix stripped off
     * `employee_code` — automatically in sync on every save, so nothing
     * else in the app ever has to remember to maintain it (or risk it
     * drifting out of sync with `employee_code`). `employee_code` itself
     * is never touched by this; it exists purely so the User<->Employee
     * relationship below has a real, indexable column to join on now that
     * login usernames no longer equal the employee number verbatim.
     */
    protected static function booted(): void
    {
        static::saving(function (Employee $employee) {
            $employee->employee_code_digits = static::stripEmpPrefix($employee->employee_code);
        });
    }

    /**
     * Strips a leading "EMP" (case-insensitive) off an employee number —
     * "EMP03050" -> "03050" — used for both `employee_code_digits` above
     * and everywhere a login username/temporary password is derived from
     * an employee number. Returns the value unchanged when it doesn't
     * start with "EMP" at all, rather than mangling an unexpected format.
     */
    public static function stripEmpPrefix(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return str_starts_with(strtoupper($code), 'EMP') ? substr($code, 3) : $code;
    }

    public function offboardingRequests(): HasMany
    {
        return $this->hasMany(OffboardingRequest::class);
    }

    public function latestOffboardingRequest(): HasOne
    {
        return $this->hasOne(OffboardingRequest::class)->latestOfMany();
    }

    /**
     * The Employee Master group this employee has been explicitly assigned
     * to, if any — a separate, admin-curated membership list independent of
     * the free-text `department` column (see `EmployeeGroup`).
     */
    public function employeeGroup(): BelongsTo
    {
        return $this->belongsTo(EmployeeGroup::class);
    }

    /**
     * The employee's immediate head/supervisor, resolved from the Employee
     * Master Excel import's `headID` column (that column holds the head's
     * own `employeeNo`, resolved to this FK at import time — see
     * `EmployeeGroupController::import()`). Distinct from the free-text
     * `head` column (the head's NAME, kept for backward compatibility) and
     * from `departmentHead()` below (the admin-curated Employee Master
     * group head, a separate concept).
     */
    public function headEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'head_employee_id');
    }

    /**
     * The department head/group head actually responsible for this
     * employee. Prefers the Employee Master group's own Group Head, since
     * that's an explicit, admin-curated assignment; falls back to the
     * older standalone `department_heads` registry (keyed by the raw
     * `department` string) for any employee not yet placed in a group —
     * or null if neither has anything registered yet.
     */
    public function departmentHead(): ?Employee
    {
        return $this->employeeGroup?->groupHead ?? DepartmentHead::headFor($this->department);
    }

    /**
     * The login account for this employee, matched by the convention that
     * `username` equals `employee_code_digits` (the employee number with
     * its "EMP" prefix stripped) — never `employee_code` directly, since
     * usernames no longer include that prefix while the employee number
     * itself must stay unchanged.
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'username', 'employee_code_digits');
    }
}
