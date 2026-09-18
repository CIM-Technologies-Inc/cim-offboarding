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
        // "Schedule Email/Notification" at the CHECKLIST level — timed off
        // this checklist's own due date on a given request
        // (`OffboardingRequestApprover::due_at`), independent of the
        // request-wide `EmailTemplate`-level schedule and the per-item
        // `ChecklistItem` one. See `app:send-checklist-reminders`.
        'notification_enabled',
        'notification_email_template_id',
        'notification_days_before',
        'notification_time',
        'notification_type',
        'notification_repeat',
        'notification_repeat_interval_days',
        'notification_max_reminders',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_final_pay_checklist' => 'boolean',
            'is_immediate_head_checklist' => 'boolean',
            'use_task_assignee_as_signatory' => 'boolean',
            'due_in_days' => 'integer',
            'notification_enabled' => 'boolean',
            'notification_days_before' => 'integer',
            'notification_time' => 'datetime:H:i',
            'notification_repeat' => 'boolean',
            'notification_repeat_interval_days' => 'integer',
            'notification_max_reminders' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('sort_order');
    }

    public function notificationEmailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'notification_email_template_id');
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
