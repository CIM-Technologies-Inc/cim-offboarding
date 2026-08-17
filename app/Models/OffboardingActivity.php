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
            'declined' => "Declined by {$actor}{$suffix}",
            'all_checklists_approved' => 'All Offboarding Checklists Cleared',
            'final_pay_notified' => 'Final Pay Checklist Notification Sent',
            'reminder_sent' => "Reminder Sent by {$actor}",
            'checklist_assigned' => "Checklist Assigned by {$actor}{$suffix}",
            'checklist_item_reassigned' => "Checklist Item Reassigned by {$actor}{$suffix}",
            'checklist_item_auto_assigned' => 'Checklist Items Auto-Assigned to Group Members',
            'checklist_delegate_completed' => "Checklist Completed by {$actor}{$suffix} (Delegated Approver)",
            'checklist_item_cleared_by_other' => "Checklist Item Cleared by {$actor}{$suffix}",
            'checklist_item_held' => "Checklist Item Held by {$actor}{$suffix}",
            'checklist_ready_for_approval' => 'Checklist Ready for Department Head Approval',
            'completed' => 'Offboarding Completed',
            default => ucfirst($this->action) . " by {$actor}{$suffix}",
        };
    }
}
