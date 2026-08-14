<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepartmentHead extends Model
{
    protected $fillable = [
        'department',
        'employee_id',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Resolves the registered head for a raw `employees.department` value
     * (e.g. "Engineering", "Finance") — independent of any checklist
     * template, since an item approver may belong to a department with no
     * checklist template at all.
     */
    public static function headFor(?string $department): ?Employee
    {
        if (! $department) {
            return null;
        }

        return static::where('department', $department)->first()?->employee;
    }
}
