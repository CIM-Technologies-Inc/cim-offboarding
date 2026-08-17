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
     * Maps an employee's department to the department vocabulary used by
     * checklist templates and email templates (HR, IT, Accounting, Sales).
     * Departments with no entry here have no matching checklist/email template.
     */
    public const CHECKLIST_DEPARTMENT_MAP = [
        'Human Resources' => 'HR',
        'Engineering' => 'IT',
        'Finance' => 'Accounting',
        'Sales' => 'Sales',
    ];

    /**
     * Designations eligible to be picked as a checklist template's department head.
     */
    public const MANAGEMENT_DESIGNATIONS = ['Head', 'Senior Manager', 'Manager'];

    protected $fillable = [
        'employee_code',
        'name',
        'email',
        'department',
        'employee_group_id',
        'designation',
        'date_of_joining',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'date_of_joining' => 'date',
        ];
    }

    public function offboardingRequests(): HasMany
    {
        return $this->hasMany(OffboardingRequest::class);
    }

    public function latestOffboardingRequest(): HasOne
    {
        return $this->hasOne(OffboardingRequest::class)->latestOfMany();
    }

    public function checklistDepartment(): ?string
    {
        return self::CHECKLIST_DEPARTMENT_MAP[$this->department] ?? null;
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
     * `username` equals this employee's `employee_code`.
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'username', 'employee_code');
    }
}
