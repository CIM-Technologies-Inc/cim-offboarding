<?php

namespace App\Notifications;

use App\Models\OffboardingRequestApprover;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * In-app (bell) notification for the Clearance Signatory escalation
 * workflow — the new signatory, the previous signatory, and HR/Admin. Same
 * shape as `ChecklistOverdueNotification`; the message is composed per
 * recipient by `ClearanceSignatoryEscalationService::recipientsFor()`.
 */
class ClearanceSignatoryEscalationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public OffboardingRequestApprover $assignment,
        public string $event,
        public string $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'clearance_signatory_escalation';
    }

    public function toDatabase(object $notifiable): array
    {
        $offboardee = $this->assignment->offboardingRequest->employee;

        return [
            'offboarding_request_id' => $this->assignment->offboarding_request_id,
            'offboarding_request_approver_id' => $this->assignment->id,
            'offboardee_id' => $offboardee->id,
            'offboardee_name' => $offboardee->name,
            'offboardee_employee_code' => $offboardee->employee_code,
            'checklist_title' => $this->assignment->checklistTemplate?->title,
            'event' => $this->event,
            'message' => $this->message,
            'url' => route('approvals.index'),
        ];
    }
}
