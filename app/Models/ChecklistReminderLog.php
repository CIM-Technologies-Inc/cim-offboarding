<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (checklist instance, recipient, reminder cycle) that
 * `app:send-checklist-reminders` has actually processed — see that
 * command and the `create_checklist_reminder_logs_table` migration's own
 * docblock for why no "pending" row is ever pre-created.
 */
class ChecklistReminderLog extends Model
{
    protected $fillable = [
        'offboarding_request_approver_id',
        'offboarding_request_id',
        'checklist_template_id',
        'email_template_id',
        'recipient_employee_id',
        'recipient_email',
        'notification_type',
        'reminder_number',
        'scheduled_at',
        'sent_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'reminder_number' => 'integer',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function offboardingRequestApprover(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequestApprover::class);
    }

    public function offboardingRequest(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequest::class);
    }

    public function checklistTemplate(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class);
    }

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    public function recipientEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recipient_employee_id');
    }
}
