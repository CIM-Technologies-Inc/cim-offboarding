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
        'email',
        'personal_email',
        'department',
        'employee_group_id',
        'is_task_assignee',
        'designation',
        'sup_one',
        'sup_two',
        'head',
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
