<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
     * The login account for this employee, matched by the convention that
     * `username` equals this employee's `employee_code`.
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'username', 'employee_code');
    }
}
