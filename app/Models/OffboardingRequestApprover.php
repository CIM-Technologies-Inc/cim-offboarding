<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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
        'approval_remarks',
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
     * Bumps this assignment from 'pending' to 'viewed' the moment ANY task
     * assignee genuinely acts on it — checks/completes an item, or places
     * one on Hold — not just when the primary approver visits their own
     * queue (see `ApprovalController::index()`'s own "viewing counts as
     * viewed" side effect, which already does this for that specific
     * trigger). 'viewed' is already this app's existing "In Progress"
     * indicator everywhere it's displayed (the Offboarding Status/Timeline
     * badges, `clearanceStatusLabel()`), so bumping the SAME column here —
     * rather than inventing a parallel "in progress" flag — keeps every one
     * of those displays automatically consistent with no further changes.
     * A no-op once the assignment is already 'viewed' or beyond
     * ('approved'/'declined'), so this is always safe to call
     * unconditionally after any item action.
     */
    public function markInProgressIfPending(): void
    {
        if ($this->status === 'pending') {
            $this->update(['status' => 'viewed']);
        }
    }

    /**
     * Rows this user may see/act on: every row for an admin, otherwise
     * rows where they're the primary approver, the delegate, an item
     * signatory, hold an active per-item override, OR are a flagged Task
     * Assignee (`Employee.is_task_assignee`) of the SAME Employee Master
     * group this checklist template's own Clearance Signatory heads
     * (`checklistTemplate.employee_group_id`) — this last clause is what
     * lets an employee who was only ever marked "Task Assignee" on the
     * Employee Master page (never picked for a specific task item) still
     * see and act on the request, without that flag alone ever having
     * triggered the "Offboarding Checklist Assigned to You" email (that's
     * a completely separate, unrelated code path in
     * `ChecklistApprovalNotifier` — this clause only grants visibility/
     * action rights, never sends anything). The Clearance Signatory/
     * Department Head themselves are excluded from qualifying via this
     * clause — they already match via `employee_id` above regardless.
     *
     * This exact predicate `ApprovalController::index()` used to have
     * inlined, now shared with the grouped save-progress endpoint so a
     * delegate/item-signatory/group-task-assignee who only has rights on
     * SOME of a combined group's checklists can never touch the rest of
     * that group through it.
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

        return $query->where(function (Builder $q) use ($employee) {
            $q->where('employee_id', $employee->id)
                ->orWhere('delegated_employee_id', $employee->id)
                ->orWhereHas('checklistTemplate.items', fn ($qi) => $qi->where('signatory_id', $employee->id))
                ->orWhereHas('itemAssignments', fn ($qi) => $qi->where('assigned_employee_id', $employee->id)->where('status', 'active'));

            if ($employee->is_task_assignee && $employee->employee_group_id) {
                $q->orWhereHas('checklistTemplate', fn ($qt) => $qt
                    ->where('employee_group_id', $employee->employee_group_id)
                    ->where(fn ($qh) => $qh->whereNull('department_head_id')->orWhere('department_head_id', '!=', $employee->id)));
            }
        });
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
     * True while this checklist is still a single, undifferentiated block
     * under its own primary approver — no item anywhere on it has any real
     * distinct signatory yet (`usesPerItemApprovers() === false`) — and the
     * assignment itself hasn't already been actioned. This is exactly the
     * "IT Checklist vs. Department Head Checklist" distinction the bulk
     * "Assign Checklist" modal draws: a checklist that already has real
     * per-item ownership is "spoken for" and must never be reopened for
     * bulk (re)assignment, while one that's never had any distinct
     * signatory is fair game. Once even one item gets a real assignee (via
     * bulk assignment, "Check This List", or otherwise), this flips to
     * `false` on its own — no separate bookkeeping needed.
     */
    public function isEligibleForPoolAssignment(): bool
    {
        return in_array($this->status, ['pending', 'viewed'], true)
            && ! $this->usesPerItemApprovers();
    }

    /**
     * The employee ids the bulk "Assign Checklist" modal may assign onto
     * this assignment — every Employee Master group THIS assignment's own owner
     * (`employee_id`, the current Clearance Signatory/Immediate Head) is
     * the Group Head of, flagged `is_task_assignee`, excluding themselves.
     * Anchored on the person, not the checklist template's own
     * `employee_group_id` — a combined card's several checklists (e.g. a
     * Clearance Signatory checklist alongside that same person's own
     * Immediate Head checklist, which typically has no group configured at
     * all) are all owned by the same employee, so this naturally offers the
     * identical, correctly scoped pool for every one of them. No headed
     * group at all means no eligible pool, never a company-wide fallback —
     * see `ApprovalController::eligibleAssigneesForPool()`, which shapes
     * this same id list for display, and `ChecklistDelegationController::assignPool()`,
     * which re-derives it server-side to validate the submitted selection.
     *
     * @return array<int, int>
     */
    public function eligiblePoolAssigneeIds(): array
    {
        $headedGroupIds = EmployeeGroup::where('group_head_employee_id', $this->employee_id)->pluck('id');

        if ($headedGroupIds->isEmpty()) {
            return [];
        }

        return Employee::whereIn('employee_group_id', $headedGroupIds)
            ->where('is_task_assignee', true)
            ->where('id', '!=', $this->employee_id)
            ->where('status', 'active')
            ->pluck('id')
            ->all();
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
     * For a "Use Task Assignee as Clearance Signatory" checklist (no single
     * Clearance Signatory of its own — see `ChecklistTemplate::$use_task_assignee_as_signatory`),
     * every distinct employee actually assigned to at least one item here
     * who has NOT yet fully completed their own item(s) — the reminder
     * recipients for this checklist, in place of a single (nonexistent)
     * Clearance Signatory. Grouped by `effectiveSignatoryFor()` — the same
     * per-request-override-aware resolution the Clearance Form's own
     * per-Task-Assignee rows use — so a live item reassignment is reflected
     * identically here. An employee counts as pending unless EVERY one of
     * their own items is checked; items nobody was ever assigned
     * contribute nothing. Empty once every Task Assignee has cleared their
     * own work (or the checklist has no items at all).
     *
     * @return Collection<int, Employee>
     */
    public function pendingTaskAssigneeEmployees(): Collection
    {
        $this->loadMissing('checklistTemplate.items', 'itemProgress');

        $itemsByEmployeeId = $this->checklistTemplate->items
            ->groupBy(fn (ChecklistItem $item) => $this->effectiveSignatoryFor($item)?->id)
            ->forget(null);

        $checkedItemIds = $this->itemProgress->where('is_checked', true)->pluck('checklist_item_id');

        return $itemsByEmployeeId
            ->reject(fn (Collection $items) => $items->every(fn (ChecklistItem $item) => $checkedItemIds->contains($item->id)))
            ->keys()
            ->map(fn ($employeeId) => Employee::find($employeeId))
            ->filter()
            ->values();
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
     * True once "now" is at or past this checklist's due date, regardless of
     * status — the trigger for the Submit-time due-date confirmation/remarks
     * dialog (see `checklist-modal.blade.php`). Unlike `isOverdue()` (which
     * stops applying the moment a row is resolved, since it only drives the
     * "still outstanding and overdue" warning badge), this deliberately
     * keeps returning true after approval too, since `wasCompletedLate()`
     * below needs the exact same "was due" fact to still hold once the row
     * is approved.
     */
    public function hasReachedDueDate(): bool
    {
        return $this->due_at !== null && now()->greaterThanOrEqualTo($this->due_at);
    }

    /**
     * The permanent, persisted basis for the green ("Completed On Time") vs
     * red ("Completed Late") status color — always compares this row's own
     * stored `due_at` against its own stored `approved_at` (never "now"), so
     * it stays correct forever after a refresh, regardless of when it's
     * viewed. A checklist with no due date configured, or not yet approved,
     * is never "late".
     */
    public function wasCompletedLate(): bool
    {
        return $this->status === 'approved'
            && $this->due_at !== null
            && $this->approved_at !== null
            && $this->approved_at->greaterThanOrEqualTo($this->due_at);
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

    /**
     * An HTML table of every checklist item's checked status, who checked
     * it, when, and their remark — feeds the "{{checklist_summary}}"
     * placeholder on a "ready for approval" follow-up email (e.g. when an
     * admin resends `ChecklistReadyForApprovalMail`'s content via a
     * customizable `EmailTemplate` instead — see
     * `ApprovalController::remind()`). Unlike `itemsStatusTableHtml()`
     * above (built for an OVERDUE notice, so it only shows each item's due
     * date/status), this shows exactly who did the work and when — the
     * same shape `ChecklistApprovalNotifier::buildChecklistsPayload()`
     * already assembles for the hardcoded ready-for-approval Blade view,
     * just rendered as inline HTML here instead so it can be dropped into
     * an admin-editable template body.
     */
    public function checkedItemsSummaryHtml(): string
    {
        $this->loadMissing('checklistTemplate.items', 'itemProgress.checkedBy.employee');

        if ($this->checklistTemplate->items->isEmpty()) {
            return '<p>No individual checklist items.</p>';
        }

        $progress = $this->itemProgress->keyBy('checklist_item_id');

        $rows = $this->checklistTemplate->items->map(function (ChecklistItem $item) use ($progress) {
            $itemProgress = $progress->get($item->id);
            $isChecked = (bool) ($itemProgress?->is_checked ?? false);
            $status = $itemProgress?->status === 'hold'
                ? 'Hold'
                : ($isChecked ? 'Checked' : 'Pending');
            $checkedByName = $isChecked ? ($itemProgress?->checkedBy?->employee?->name ?? $itemProgress?->checkedBy?->name) : null;
            $checkedAt = $isChecked ? $itemProgress?->checked_at?->format('M d, Y g:i A') : null;

            return '<tr>'
                .'<td style="padding:6px 10px;border:1px solid #e5e7eb;">'.e($item->title).'</td>'
                .'<td style="padding:6px 10px;border:1px solid #e5e7eb;">'.e($status).'</td>'
                .'<td style="padding:6px 10px;border:1px solid #e5e7eb;">'.e($checkedByName ?? '—').'</td>'
                .'<td style="padding:6px 10px;border:1px solid #e5e7eb;">'.e($checkedAt ?? '—').'</td>'
                .'<td style="padding:6px 10px;border:1px solid #e5e7eb;">'.e($itemProgress?->remark ?? '—').'</td>'
                .'</tr>';
        })->implode('');

        return '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
            .'<tr>'
            .'<th style="padding:6px 10px;border:1px solid #e5e7eb;text-align:left;background:#f3f4f6;">Item</th>'
            .'<th style="padding:6px 10px;border:1px solid #e5e7eb;text-align:left;background:#f3f4f6;">Status</th>'
            .'<th style="padding:6px 10px;border:1px solid #e5e7eb;text-align:left;background:#f3f4f6;">Checked By</th>'
            .'<th style="padding:6px 10px;border:1px solid #e5e7eb;text-align:left;background:#f3f4f6;">Date/Time</th>'
            .'<th style="padding:6px 10px;border:1px solid #e5e7eb;text-align:left;background:#f3f4f6;">Remarks</th>'
            .'</tr>'
            .$rows
            .'</table>';
    }
}
