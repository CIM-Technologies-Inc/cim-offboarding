<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OffboardingRequestApprover extends Model
{
    protected $fillable = [
        'offboarding_request_id',
        'checklist_template_id',
        'employee_id',
        'status',
        'assigned_at',
        'first_viewed_at',
        'approved_at',
        'declined_at',
        'decline_reason',
        'reminder_sent_at',
        'delegated_employee_id',
        'delegation_status',
        'delegated_at',
        'delegate_completed_at',
        'due_at',
        'ready_for_approval_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'first_viewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'declined_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'delegated_at' => 'datetime',
            'delegate_completed_at' => 'datetime',
            'due_at' => 'datetime',
            'ready_for_approval_notified_at' => 'datetime',
        ];
    }

    public function offboardingRequest(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequest::class);
    }

    public function checklistTemplate(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function delegatedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'delegated_employee_id');
    }

    public function itemProgress(): HasMany
    {
        return $this->hasMany(ChecklistItemProgress::class);
    }

    public function delegations(): HasMany
    {
        return $this->hasMany(ChecklistDelegation::class)->orderBy('assigned_at');
    }

    public function itemAssignments(): HasMany
    {
        return $this->hasMany(ChecklistItemAssignment::class);
    }

    public function isDelegated(): bool
    {
        return $this->delegated_employee_id !== null;
    }

    public function department(): ?string
    {
        return $this->checklistTemplate?->department ?? $this->employee?->department;
    }

    /**
     * True when the approver hasn't viewed, approved, or declined yet —
     * drives whether the "Notify Approver" reminder button is shown.
     */
    public function hasNoActivity(): bool
    {
        return ! $this->first_viewed_at && ! $this->approved_at && ! $this->declined_at;
    }

    /**
     * True when this assignment genuinely uses per-item approvers — i.e.
     * at least one checklist item's signatory differs from the primary
     * approver (department head) on this row. Templates where every item
     * either has no signatory or shares the same signatory as employee_id
     * keep today's single-approver behavior (manual Approve/Submit).
     */
    public function usesPerItemApprovers(): bool
    {
        $this->loadMissing('checklistTemplate.items');

        return $this->checklistTemplate->items->contains(
            fn (ChecklistItem $item) => $item->signatory_id !== null && $item->signatory_id !== $this->employee_id
        );
    }

    /**
     * True once every checklist item on this assignment's template has
     * been checked, regardless of who checked it. Items with no progress
     * row yet count as unchecked. An empty checklist counts as complete.
     */
    public function allItemsCompleted(): bool
    {
        $this->loadMissing('checklistTemplate.items', 'itemProgress');

        if ($this->checklistTemplate->items->isEmpty()) {
            return true;
        }

        $progress = $this->itemProgress->keyBy('checklist_item_id');

        return $this->checklistTemplate->items->every(
            fn (ChecklistItem $item) => (bool) ($progress->get($item->id)?->is_checked ?? false)
        );
    }

    /**
     * The employee actually responsible for checking this item on THIS
     * assignment — the Department Head's live reassignment if one is
     * active, otherwise the checklist template's own configured signatory.
     * Never mutates the shared template, so other offboarding requests
     * reusing the same template are completely unaffected by a
     * reassignment made here.
     */
    public function effectiveSignatoryFor(ChecklistItem $item): ?Employee
    {
        $this->loadMissing('itemAssignments.assignedEmployee');

        $override = $this->itemAssignments
            ->where('checklist_item_id', $item->id)
            ->where('status', 'active')
            ->first();

        return $override?->assignedEmployee ?? $item->signatory;
    }

    /**
     * Overdue means this specific approval is still outstanding past its
     * computed due date. Never overdue once resolved, and never overdue
     * if the template had no due_in_days set at assignment time.
     */
    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && ! in_array($this->status, ['approved', 'declined'], true)
            && now()->greaterThan($this->due_at);
    }
}
