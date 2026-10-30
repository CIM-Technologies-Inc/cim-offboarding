<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only history of a checklist instance's Clearance Signatory
 * assignment: the initial assignment, each automatic escalation, a
 * secondary-deadline failure, and every HR/Admin manual reassignment.
 * Rows are only ever created (and their `notifications` outcome updated by
 * the delivery retry) by `ClearanceSignatoryEscalationService` — nothing in
 * the UI edits or deletes them.
 */
class ChecklistSignatoryAssignment extends Model
{
    public const EVENT_INITIAL = 'initial_assignment';

    public const EVENT_ESCALATED = 'escalated_to_secondary';

    public const EVENT_HR_ACTION_REQUIRED = 'hr_action_required';

    public const EVENT_HR_REASSIGNED = 'reassigned_by_hr';

    public const TRIGGER_SYSTEM = 'system';

    public const TRIGGER_SCHEDULER = 'scheduler';

    public const TRIGGER_MANUAL = 'manual';

    public const EVENT_LABELS = [
        self::EVENT_INITIAL => 'Assigned to Primary',
        self::EVENT_ESCALATED => 'Escalated to Secondary',
        self::EVENT_HR_ACTION_REQUIRED => 'HR/Admin Action Required',
        self::EVENT_HR_REASSIGNED => 'Reassigned by HR/Admin',
    ];

    protected $fillable = [
        'offboarding_request_approver_id',
        'offboarding_request_id',
        'checklist_template_id',
        'event',
        'stage',
        'trigger',
        'previous_employee_id',
        'new_employee_id',
        'previous_employee_name',
        'new_employee_name',
        'assigned_at',
        'deadline_at',
        'occurred_at',
        'previous_status',
        'new_status',
        'reason',
        'performed_by_user_id',
        'notifications',
        'notification_status',
        'notification_attempts',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'deadline_at' => 'datetime',
            'occurred_at' => 'datetime',
            'notifications' => 'array',
            'notification_attempts' => 'integer',
        ];
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequestApprover::class, 'offboarding_request_approver_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    /**
     * Who initiated this movement — the HR/Admin user for a manual
     * reassignment, otherwise the system's automatic escalation.
     */
    public function initiatorLabel(): string
    {
        if ($this->performedBy) {
            return $this->performedBy->name;
        }

        return $this->event === self::EVENT_INITIAL ? 'System' : 'System (automatic escalation)';
    }

    /**
     * One row of a checklist's reassignment history, as shown on the
     * Offboarding Status/Timeline tabs.
     *
     * @return array<string, mixed>
     */
    public function toHistoryEntry(): array
    {
        return [
            'event' => $this->event,
            'label' => self::EVENT_LABELS[$this->event] ?? $this->event,
            'occurredAt' => $this->occurred_at?->format('M d, Y g:i A'),
            'from' => $this->previous_employee_name,
            'to' => $this->new_employee_name,
            'deadline' => $this->deadline_at?->format('M d, Y g:i A'),
            'initiatedBy' => $this->initiatorLabel(),
            'isManual' => $this->trigger === self::TRIGGER_MANUAL,
            'reason' => $this->reason,
        ];
    }
}
