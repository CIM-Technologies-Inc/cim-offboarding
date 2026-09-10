<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeGroup extends Model
{
    protected $fillable = [
        'name',
        'department',
        'group_head_employee_id',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * The Group Head / Department Head responsible for this group — distinct
     * from the group's rank-and-file `employees()` members below. Set
     * directly by the admin picking an employee on the Create/Edit Group
     * form (see `EmployeeGroupController::store()`/`update()`); the picker
     * searches by name for convenience, but this FK — and everything that
     * reads it — is always keyed on that employee's unique id, so it stays
     * correct even if their name (or any other personal info) later
     * changes.
     */
    public function groupHead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'group_head_employee_id');
    }

    /**
     * Every employee currently assigned to this group. An employee belongs
     * to at most one group at a time (a plain `employee_group_id` column on
     * `employees`, not a pivot) — reassigning them to a different group
     * simply moves the foreign key, it never creates a second membership
     * row, so duplicate assignment is structurally impossible.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'employee_group_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
