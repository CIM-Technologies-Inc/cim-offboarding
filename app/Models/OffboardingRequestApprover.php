<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'overdue_notified_at',
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
            'overdue_notified_at' => 'datetime',
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

    public function followUps(): HasMany
    {
        return $this->hasMany(ChecklistFollowUp::class)->orderByDesc('sent_at');
    }

    public function isDelegated(): bool
    {
        return $this->delegated_employee_id !== null;
    }

    /**
     * Rows this user may see/act on: every row for an admin, otherwise only
     * rows where they're the primary approver, the delegate, an item
     * signatory, or hold an active per-item override — the exact predicate
     * `ApprovalController::index()` used to have inlined, now shared with
     * the grouped save-progress endpoint so a delegate/item-signatory who
     * only has rights on SOME of a combined group's checklists can never
     * touch the rest of that group through it.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        $employee = $user->employee;

        if (! $employee) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $q) => $q->where('employee_id', $employee->id)
            ->orWhere('delegated_employee_id', $employee->id)
            ->orWhereHas('checklistTemplate.items', fn ($qi) => $qi->where('signatory_id', $employee->id))
            ->orWhereHas('itemAssignments', fn ($qi) => $qi->where('assigned_employee_id', $employee->id)->where('status', 'active')));
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
     * True when this assignment genuinely uses per-item approvers — i.e. at
     * least one checklist item's EFFECTIVE signatory (the Department Head's
     * live "Assign To" reassignment if one is active, otherwise the
     * template's own configured signatory — see `effectiveSignatoryFor()`)
     * differs from the primary approver (department head) on this row.
     * Templates where every item either has no signatory or shares the same
     * signatory as employee_id keep today's single-approver behavior
     * (manual Approve/Submit) — UNLESS the Department Head has reassigned
     * an item on this specific request, which must flip this on even when
     * the shared template itself was never configured with distinct
     * per-item signatories. Checking `signatory_id` alone here (the
     * template's static column) would silently ignore any such
     * reassignment, leaving the newly assigned approver with an enabled
     * checkbox but no way to actually submit it — no Save Progress button
     * (primary-approver/delegate only), no Done button (gated on this very
     * flag), and no "Check This List" either (already `editable`).
     */
    public function usesPerItemApprovers(): bool
    {
        $this->loadMissing('checklistTemplate.items', 'itemAssignments.assignedEmployee');

        return $this->checklistTemplate->items->contains(function (ChecklistItem $item) {
            $effectiveSignatory = $this->effectiveSignatoryFor($item);

            return $effectiveSignatory !== null && $effectiveSignatory->id !== $this->employee_id;
        });
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
     * True when the Submit button must stay disabled — and an approve()
     * request must be rejected — until every checklist item is checked,
     * even though a single approver with no distinct item-level signatories
     * would otherwise be free to submit at any time (today's default,
     * unchanged behavior). Two independent triggers: genuine per-item
     * approvers (`usesPerItemApprovers()`), and an Immediate Head checklist,
     * which always requires full completion before submission regardless of
     * whether it happens to use per-item signatories — the Immediate Head
     * is a single approver here, just like a Department Head, but must
     * still check off every item before they're allowed to submit.
     */
    public function requiresAllItemsCompletedBeforeApproval(): bool
    {
        return $this->usesPerItemApprovers() || (bool) $this->checklistTemplate?->is_immediate_head_checklist;
    }

    /**
     * The employee actually responsible for checking this item on THIS
     * assignment. Every item gets an active `ChecklistItemAssignment`
     * snapshot row at request-attach time (see
     * `ChecklistApprovalNotifier::snapshotItemSignatories()`) — including
     * an explicit "no signatory" row (`assigned_employee_id` null) for an
     * item that had neither its own configured signatory nor an applicable
     * group at that moment. That snapshot is authoritative once it exists:
     * `null` there means "definitely no signatory for this request," NOT
     * "go check the template" — the whole point is that a template edited
     * afterwards (signatory added/changed/removed, Department Head
     * reassigned) never leaks into a request that was already created.
     * Falling back to the template's own live `$item->signatory` only
     * happens when no snapshot row exists at all, which is only possible
     * for a request created before this snapshotting existed.
     */
    public function effectiveSignatoryFor(ChecklistItem $item): ?Employee
    {
        $this->loadMissing('itemAssignments.assignedEmployee');

        $override = $this->itemAssignments
            ->where('checklist_item_id', $item->id)
            ->where('status', 'active')
            ->first();

        if ($override) {
            return $override->assignedEmployee;
        }

        return $item->signatory;
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

    /**
     * This one checklist's current-state label for the Clearance Form's
     * Remarks column — the per-checklist equivalent of
     * `OffboardingRequest::displayStatus()`, since the form must show each
     * department's real current standing even while the request overall is
     * still pending/in progress, not just once everything is done.
     */
    public function clearanceStatusLabel(): string
    {
        if ($this->status === 'approved') {
            return 'Cleared';
        }

        if ($this->status === 'declined') {
            return 'Declined';
        }

        $this->loadMissing('itemProgress');

        if ($this->itemProgress->contains(fn (ChecklistItemProgress $progress) => $progress->status === 'hold')) {
            return 'Hold';
        }

        if ($this->isOverdue()) {
            return 'Overdue';
        }

        if ($this->status !== 'pending' || $this->itemProgress->isNotEmpty()) {
            return 'In Progress';
        }

        return 'Pending';
    }

    /**
     * Whole days past this assignment's due date — 0 when not overdue (or
     * no due date set). Feeds the overdue-checklist email's
     * "{{days_overdue}}" placeholder.
     */
    public function daysOverdue(): int
    {
        return $this->isOverdue() ? (int) now()->diffInDays($this->due_at) : 0;
    }

    /**
     * An HTML table of every checklist item with its current status (Done /
     * Hold / Pending) and due date — feeds the overdue-checklist email's
     * "{{pending_items}}" placeholder. Every item currently shares this
     * assignment's single `due_at`, since per-item due dates don't exist in
     * this schema (`checklist_items` has no due-date column of its own), so
     * that shared value is shown as each row's "due date".
     */
    public function itemsStatusTableHtml(): string
    {
        $this->loadMissing('checklistTemplate.items', 'itemProgress');

        if ($this->checklistTemplate->items->isEmpty()) {
            return '<p>No individual checklist items.</p>';
        }

        $progress = $this->itemProgress->keyBy('checklist_item_id');
        $dueDateLabel = $this->due_at?->format('M d, Y') ?? '—';

        $rows = $this->checklistTemplate->items->map(function (ChecklistItem $item) use ($progress, $dueDateLabel) {
            $itemProgress = $progress->get($item->id);
            $status = $itemProgress?->status === 'hold'
                ? 'Hold'
                : ((bool) ($itemProgress?->is_checked ?? false) ? 'Done' : 'Pending');

            return '<tr>'
                .'<td style="padding:6px 10px;border:1px solid #e5e7eb;">'.e($item->title).'</td>'
                .'<td style="padding:6px 10px;border:1px solid #e5e7eb;">'.e($dueDateLabel).'</td>'
                .'<td style="padding:6px 10px;border:1px solid #e5e7eb;">'.e($status).'</td>'
                .'</tr>';
        })->implode('');

        return '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
            .'<tr>'
            .'<th style="padding:6px 10px;border:1px solid #e5e7eb;text-align:left;background:#f3f4f6;">Checklist Item</th>'
            .'<th style="padding:6px 10px;border:1px solid #e5e7eb;text-align:left;background:#f3f4f6;">Due Date</th>'
            .'<th style="padding:6px 10px;border:1px solid #e5e7eb;text-align:left;background:#f3f4f6;">Status</th>'
            .'</tr>'
            .$rows
            .'</table>';
    }
}
