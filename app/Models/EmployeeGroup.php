<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeGroup extends Model
{
    protected $fillable = [
        'name',
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
     * from the group's rank-and-file `employees()` members below.
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
