<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChecklistTemplate extends Model
{
    /**
     * The two dates `due_in_days` can count from — see
     * `ChecklistApprovalNotifier::attachAndNotify()`'s `dueDateBasisDate()`
     * for where this is actually applied when a new offboarding request is
     * created.
     */
    public const DUE_DATE_BASIS_RESIGNATION_DATE = 'resignation_date';

    public const DUE_DATE_BASIS_LAST_WORKING_DAY = 'last_working_day';

    /**
     * The two Sync-workflow stages a non-Final-Pay checklist can belong to
     * — see `ChecklistCompletionService::checkPrimaryChecklistsCompletion()`.
     * Meaningless (left null) for a Final Pay checklist, which is
     * identified solely by `is_final_pay_checklist` — a deliberately
     * separate concept, not a third value alongside these two.
     */
    public const SEQUENCE_TYPE_PRIMARY = 'primary';

    public const SEQUENCE_TYPE_SECONDARY = 'secondary';

    protected $fillable = [
        'title',
        'department_head_id',
        'employee_group_id',
        'is_immediate_head_checklist',
        'use_task_assignee_as_signatory',
        'department',
        'is_final_pay_checklist',
        'sequence_type',
        'is_active',
        'created_by',
        'due_in_days',
        'due_date_basis',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_final_pay_checklist' => 'boolean',
            'is_immediate_head_checklist' => 'boolean',
            'use_task_assignee_as_signatory' => 'boolean',
            'due_in_days' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('sort_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function departmentHead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'department_head_id');
    }

    /**
     * The specific Employee Master group selected as this checklist's
     * Clearance Signatory — distinct from `department_head_id` (still that
     * group's Group Head employee, unchanged for every downstream
     * consumer), since two different groups can share the same Group Head
     * and must remain independently selectable/identifiable.
     */
    public function employeeGroup(): BelongsTo
    {
        return $this->belongsTo(EmployeeGroup::class);
    }

    public function offboardingRequests(): BelongsToMany
    {
        return $this->belongsToMany(OffboardingRequest::class, 'checklist_assignments')->withTimestamps();
    }

    /**
     * A checklist template with no `department` set applies to every
     * employee, unchanged from before this filter existed. One WITH a
     * `department` set is exclusive to employees whose own `department`
     * exactly matches it — an HR-only checklist must never reach an IT
     * employee, and vice versa.
     */
    public function scopeApplicableToDepartment(Builder $query, ?string $department): Builder
    {
        return $query->where(function (Builder $q) use ($department) {
            $q->whereNull('department')->orWhere('department', '');

            if ($department !== null && $department !== '') {
                $q->orWhere('department', $department);
            }
        });
    }
}
