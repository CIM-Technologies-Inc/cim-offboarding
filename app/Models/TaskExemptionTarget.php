<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One target of a `TaskExemption`. Department/position targets store the
 * text value in `target_value` (neither has a master table in this app —
 * both are free text on `employees`); employee targets use `employee_id`.
 */
class TaskExemptionTarget extends Model
{
    public const TYPE_DEPARTMENT = 'department';

    public const TYPE_POSITION = 'position';

    public const TYPE_EMPLOYEE = 'employee';

    protected $fillable = [
        'task_exemption_id',
        'target_type',
        'target_value',
        'employee_id',
    ];

    public function taskExemption(): BelongsTo
    {
        return $this->belongsTo(TaskExemption::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
