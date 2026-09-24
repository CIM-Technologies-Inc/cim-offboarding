<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * In-app (bell) notification sent once a Clearance Signatory's or General
 * Signatory's own Clearance Signing Due Date is reached — the Clearance
 * Signing Deadline equivalent of `ChecklistOverdueNotification`, but
 * intentionally its own class/type rather than reused: it fires off the
 * independent `clearance_signing_due_at`/`due_at` fields (see
 * `OffboardingRequestApprover`/`OffboardingRequestGeneralSignatory`), not
 * the pre-existing `due_at`/`isOverdue()` checklist-overdue concept, and is
 * sent only to the assigned signatory themselves (never admins) — see
 * `App\Console\Commands\NotifyClearanceSigningDue`.
 *
 * Deliberately model-agnostic (plain scalar constructor args) rather than
 * typed to `OffboardingRequestApprover` the way `ChecklistOverdueNotification`
 * is: the same notification is sent for both a checklist assignment and a
 * General Signatory assignment, which are different Eloquent models with no
 * common base.
 */
class ClearanceSigningDueNotification extends Notification
{
    use Queueable;

    public function __construct(
        public int $offboardingRequestId,
        public ?int $offboardingRequestApproverId,
        public ?int $offboardingRequestGeneralSignatoryId,
        public string $offboardeeName,
        public ?string $offboardeeEmployeeCode,
        public string $checklistTitle,
        public ?string $dueAt,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'clearance_signing_due';
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'offboarding_request_id' => $this->offboardingRequestId,
            'offboarding_request_approver_id' => $this->offboardingRequestApproverId,
            'offboarding_request_general_signatory_id' => $this->offboardingRequestGeneralSignatoryId,
            'offboardee_name' => $this->offboardeeName,
            'offboardee_employee_code' => $this->offboardeeEmployeeCode,
            'checklist_title' => $this->checklistTitle,
            'due_at' => $this->dueAt,
            'status' => 'Clearance Signing Due',
            'message' => "Your clearance signing deadline for {$this->offboardeeName} has been reached. Please complete and approve your assigned checklist as soon as possible.",
            'url' => route('approvals.index'),
        ];
    }
}
