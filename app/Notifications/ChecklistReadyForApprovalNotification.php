<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * In-app (bell) notification sent to a Clearance Signatory/Immediate Head
 * (the `OffboardingRequestApprover.employee_id` on every row in the group)
 * the moment every checklist item delegated to their Task Assignees — and
 * every item they're responsible for themselves — has been checked, i.e.
 * exactly when `ChecklistCompletionService::checkGroupReadyForApproval()`
 * already sends the matching `ChecklistReadyForApprovalMail` email (see
 * `ChecklistApprovalNotifier::notifyDepartmentHeadReady()`, the only place
 * this is dispatched from). That caller already guards this to fire
 * exactly once per group via `ready_for_approval_notified_at`, so this
 * never needs its own separate duplicate-prevention.
 *
 * Deliberately model-agnostic (plain scalar constructor args), same
 * convention as `ClearanceSigningDueNotification`: the group this covers
 * can combine several checklist templates for the same Department
 * Head/Clearance Signatory (a combined Approvals card), so there's no
 * single `OffboardingRequestApprover` this notification belongs to — only
 * the first member's id, kept purely so a future deep-link has something to
 * anchor to.
 */
class ChecklistReadyForApprovalNotification extends Notification
{
    use Queueable;

    public function __construct(
        public int $offboardingRequestId,
        public int $offboardingRequestApproverId,
        public string $offboardeeName,
        public ?string $offboardeeEmployeeCode,
        public string $checklistTitle,
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
        return 'checklist_ready_for_approval';
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'offboarding_request_id' => $this->offboardingRequestId,
            'offboarding_request_approver_id' => $this->offboardingRequestApproverId,
            'offboardee_name' => $this->offboardeeName,
            'offboardee_employee_code' => $this->offboardeeEmployeeCode,
            'checklist_title' => $this->checklistTitle,
            'status' => 'Ready for Approval',
            'message' => "All tasks assigned under the {$this->checklistTitle} for {$this->offboardeeName} have been completed. The checklist is now awaiting your review and final approval.",
            'url' => route('approvals.index'),
        ];
    }
}
