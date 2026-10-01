<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OffboardingActivity extends Model
{
    protected $fillable = [
        'offboarding_request_id',
        'user_id',
        'offboarding_request_approver_id',
        'offboarding_request_general_signatory_id',
        'offboarding_request_final_approval_id',
        'checklist_due_date_extension_id',
        'action',
        'status',
        'comment',
    ];

    public function offboardingRequest(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function offboardingRequestApprover(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequestApprover::class);
    }

    /**
     * The General Signatory equivalent of `offboardingRequestApprover()` —
     * which specific `OffboardingRequestGeneralSignatory` assignment this
     * activity (a notification resend or first-view) belongs to, needed
     * once a request has more than one General Signatory attached.
     */
    public function offboardingRequestGeneralSignatory(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequestGeneralSignatory::class);
    }

    /**
     * Which specific `OffboardingRequestFinalApproval` process this
     * activity (sent/viewed/approved) belongs to — a request only ever has
     * one, but this keeps the same explicit-attribution convention as the
     * checklist/General Signatory FKs above.
     */
    public function offboardingRequestFinalApproval(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequestFinalApproval::class);
    }

    /**
     * Which specific `ChecklistDueDateExtension` row this `due_date_extended`
     * activity belongs to — lets `label()` build its Timeline sentence from
     * that row's own structured previous/new due dates rather than
     * re-parsing them out of this activity's free-text `comment`.
     */
    public function checklistDueDateExtension(): BelongsTo
    {
        return $this->belongsTo(ChecklistDueDateExtension::class);
    }

    /**
     * Human-readable summary for the timeline, e.g. "Cleared by John Santos (IT)".
     */
    public function label(): string
    {
        $actor = $this->user?->name ?? 'Unknown';
        $department = $this->user?->employee?->department;
        $suffix = $department ? " ({$department})" : '';

        return match ($this->action) {
            'approved' => $this->user
                ? "Cleared by {$actor}{$suffix}"
                : 'All checklist items completed — auto-approved',
            'general_signatory_approved' => "Cleared by General Signatory: {$actor}{$suffix}",
            'general_signatory_declined' => "Declined by General Signatory: {$actor}{$suffix}",
            'general_signatory_on_hold_removed' => "On Hold Removed by General Signatory: {$actor}{$suffix}",
            // Names the checklist itself — on a "Use Task Assignee as
            // Clearance Signatory" checklist this can be a Task Assignee,
            // their Immediate/Group/Department Head, or anyone else
            // `visibleTo()` already authorized (see
            // `ApprovalController::index()`'s view-tracking block), so
            // "Viewed by {actor}" alone would leave WHICH checklist
            // ambiguous once a request has more than one.
            'checklist_viewed' => $this->offboardingRequestApprover?->checklistTemplate?->title
                ? "Viewed \"{$this->offboardingRequestApprover->checklistTemplate->title}\" by {$actor}{$suffix}"
                : "Viewed by {$actor}{$suffix}",
            'general_signatory_viewed' => "Viewed by {$actor} (General Signatory)",
            'offboarding_reset' => "Offboarding Request Reset by {$actor}",
            'offboarding_cancelled' => "Offboarding Request Cancelled by {$actor}",
            // Names the checklist itself, same reasoning as `checklist_viewed`
            // above — a decline now places the checklist On Hold (see
            // `ApprovalController::decline()`), so knowing WHICH checklist
            // was declined/held matters just as much as who declined it.
            'declined' => $this->offboardingRequestApprover?->checklistTemplate?->title
                ? "Declined \"{$this->offboardingRequestApprover->checklistTemplate->title}\" by {$actor}{$suffix}"
                : "Declined by {$actor}{$suffix}",
            'on_hold_removed' => $this->offboardingRequestApprover?->checklistTemplate?->title
                ? "On Hold Removed from \"{$this->offboardingRequestApprover->checklistTemplate->title}\" by {$actor}{$suffix}"
                : "On Hold Removed by {$actor}{$suffix}",
            'all_checklists_approved' => 'All Offboarding Checklists Cleared',
            'final_pay_notified' => 'Final Pay Checklist Notification Sent',
            'reminder_sent' => "Reminder Sent by {$actor}",
            // Prefers the linked `ChecklistDueDateExtension` row's own
            // structured previous/new due dates, matching the exact
            // sentence spec'd for this Timeline entry; falls back to the
            // generic form only for a legacy row created before that FK
            // existed (should never happen for a row created going
            // forward, since `ApprovalController::extendDue()` always sets
            // it).
            'due_date_extended' => $this->checklistDueDateExtension
                ? sprintf(
                    'Checklist due date extended from %s to %s by %s.',
                    $this->checklistDueDateExtension->previous_due_date->format('M d, Y'),
                    $this->checklistDueDateExtension->new_due_date->format('M d, Y'),
                    $actor,
                )
                : "Due Date Extended by {$actor}{$suffix}",
            // The one Timeline entry per "Extend Due" click (as distinct
            // from the several per-checklist `due_date_extended` entries
            // that same click also creates — see
            // `ApprovalController::extendAllDue()`) — the specific
            // previous/new Last Working Day dates live in this row's own
            // `comment`, set by the controller, same convention `declined`
            // above already follows for its own `decline_reason`.
            'last_working_day_extended' => "Last Working Day Extended by {$actor}",
            'general_signatory_reminder_sent' => "Notification Resent by {$actor}",
            'final_approval_sent' => "Final Approval Requested by {$actor}",
            'final_approval_viewed' => "Final Approval Link Viewed by {$actor}",
            'final_approval_approved' => "Final Approval Given by {$actor}",
            'checklist_assigned' => "Checklist Assigned by {$actor}{$suffix}",
            'checklist_pool_assigned' => "Checklist Pool Opened by {$actor}{$suffix}",
            // Names the checklist itself, same reasoning as
            // `checklist_viewed`/`declined` above — see
            // `ChecklistDelegationController::reassignChecklist()`.
            'checklist_reassigned' => $this->offboardingRequestApprover?->checklistTemplate?->title
                ? "Checklist Reassigned: \"{$this->offboardingRequestApprover->checklistTemplate->title}\" by {$actor}{$suffix}"
                : "Checklist Reassigned by {$actor}{$suffix}",
            'checklist_item_reassigned' => "Checklist Item Reassigned by {$actor}{$suffix}",
            'checklist_item_auto_assigned' => 'Checklist Items Auto-Assigned to Group Members',
            'checklist_delegate_completed' => "Checklist Completed by {$actor}{$suffix} (Delegated Approver)",
            'checklist_item_cleared_by_other' => "Checklist Item Cleared by {$actor}{$suffix}",
            'checklist_item_held' => "Checklist Item Held by {$actor}{$suffix}",
            'checklist_ready_for_approval' => 'Checklist Ready for Department Head Approval',
            'clearance_generated' => "Clearance Form Generated by {$actor}{$suffix}",
            'completed' => 'Offboarding Completed',
            default => ucfirst($this->action) . " by {$actor}{$suffix}",
        };
    }
}
