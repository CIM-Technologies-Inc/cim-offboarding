<?php

namespace App\Http\Controllers;

use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\ChecklistApprovalToken;
use App\Models\ChecklistDueDateExtension;
use App\Models\ChecklistItemProgress;
use App\Models\ChecklistTemplate;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\EmployeeGroup;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use App\Models\OffboardingRequestFinalApproval;
use App\Models\OffboardingRequestGeneralSignatory;
use App\Models\User;
use App\Notifications\OffboardingApprovalUpdated;
use App\Services\ChecklistApprovalNotifier;
use App\Services\ChecklistCompletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ApprovalController extends Controller
{
    /**
     * Email template used for the "Notify Approver" reminder while the
     * checklist is still within its due date (or has no due date at all).
     */
    private const REMINDER_TEMPLATE = 'Offboarding Reminder';

    /**
     * Email template used instead, once the checklist has reached or passed
     * its due date — see `remind()`.
     */
    private const OVERDUE_TEMPLATE = 'Offboarding Overdue Notice';

    /**
     * Shown wherever an approval is blocked for lack of an uploaded
     * e-signature — see `hasUsableSignature()`'s own docblock. Kept as one
     * constant so the in-app flow (`redirectToUploadSignature()`) and the
     * emailed-link flow (`EMAIL_APPROVAL_MESSAGES['no_signature']` below)
     * never drift into two different wordings for the same rule.
     */
    private const MISSING_SIGNATURE_MESSAGE = 'Please upload your e-signature before approving checklist.';

    public function index(Request $request): View
    {
        $user = auth()->user();
        $employee = $user->employee;

        $assignments = OffboardingRequestApprover::query()
            // 'on_hold' stays in this queue deliberately — a declined
            // checklist must remain visible to its signatory (see
            // `ApprovalController::decline()`), not disappear the way a
            // genuinely resolved (approved) one does.
            ->whereIn('status', ['pending', 'viewed', 'on_hold'])
            ->whereHas('offboardingRequest', fn ($q) => $q->where('status', 'pending'))
            ->visibleTo($user)
            ->with(['offboardingRequest.employee', 'checklistTemplate.items.signatory', 'employee', 'delegatedEmployee', 'itemProgress.checkedBy.employee', 'itemProgress.heldBy.employee', 'itemProgress.headApprover', 'itemAssignments.assignedEmployee'])
            ->get()
            ->filter(fn (OffboardingRequestApprover $assignment) => $assignment->offboardingRequest?->employee);

        // Loading your own queue counts as "viewing" whatever's still pending in
        // it — but only for the primary approver on a normal checklist. A
        // delegate merely opening their queue must never flip the primary
        // assignment's status.
        //
        // A "Use Task Assignee as Clearance Signatory" checklist has no
        // primary owner at all (`employee_id` is null) — the Task
        // Assignee(s), their Immediate/Group/Department Head, and anyone
        // else who reaches this row at all already did so ONLY because
        // `visibleTo()` above independently authorized them (its item-level
        // signatory, active per-item override, group-Task-Assignee, or
        // Head-monitoring clauses — never an unrelated employee). There is
        // no separate "primary vs. delegate" distinction to draw the way
        // there is for a normal checklist, so any such viewer's first visit
        // counts. Previously this block only ever matched `employee_id`,
        // which is always null here — so `first_viewed_at` could never be
        // set for a headless checklist no matter who opened it, even after
        // it had already been fully cleared/approved.
        if (! $user->isAdmin() && $employee) {
            $assignments->each(function (OffboardingRequestApprover $assignment) use ($user, $employee) {
                $isPrimaryViewer = $assignment->employee_id === $employee->id;
                $isAuthorizedHeadlessViewer = $assignment->employee_id === null
                    && $assignment->checklistTemplate?->use_task_assignee_as_signatory;

                // Must not touch an 'on_hold' row (now also included in
                // `$assignments` above, see its own comment) — it should
                // never be silently flipped back to 'viewed' just because
                // this viewer's queue happened to load while it's held.
                if (($isPrimaryViewer || $isAuthorizedHeadlessViewer) && ! $assignment->first_viewed_at && $assignment->status !== 'on_hold') {
                    $assignment->update([
                        'first_viewed_at' => now(),
                        'first_viewed_by_employee_id' => $employee->id,
                        'status' => 'viewed',
                    ]);

                    // Recorded so the admin-facing Offboarding Status/Timeline
                    // shows exactly who first opened this checklist and when
                    // — previously this state change was silent, the only
                    // approver action with no corresponding activity row.
                    $assignment->offboardingRequest->activities()->create([
                        'user_id' => $user->id,
                        'offboarding_request_approver_id' => $assignment->id,
                        'action' => 'checklist_viewed',
                        'status' => $assignment->offboardingRequest->status,
                    ]);
                }
            });
        }

        // Computed once (not per row) — the set of employees this viewer is
        // the approval head (Immediate Head, else Group/Department Head) of,
        // feeding the `isMonitoring` flag below. See
        // `Employee::approvalSubordinateEmployeeIds()`.
        $monitoredEmployeeIds = $employee ? $employee->approvalSubordinateEmployeeIds() : collect();

        $rows = $assignments
            ->map(function (OffboardingRequestApprover $assignment) use ($user, $employee, $monitoredEmployeeIds) {
                $request = $assignment->offboardingRequest;
                $template = $assignment->checklistTemplate;

                $isDepartmentHead = $employee && $assignment->employee_id === $employee->id;
                $isPrimaryApprover = $user->isAdmin() || $isDepartmentHead;
                $isDelegate = $employee && $assignment->delegated_employee_id === $employee->id;
                $usesPerItemApprovers = $assignment->usesPerItemApprovers();
                $mustBeCheckedForSubmit = $assignment->requiresAllItemsCompletedBeforeApproval();

                $progressByItemId = $assignment->itemProgress->keyBy('checklist_item_id');

                // Genuinely tasked with this checklist — the real Department
                // Head, the delegate, the signatory of at least one item, or
                // a flagged Task Assignee of this checklist's own Employee
                // Master group (see `OffboardingRequestApprover::scopeVisibleTo()`'s
                // matching clause) — as distinct from `isPrimaryApprover`,
                // which is also true for ANY admin merely browsing/monitoring.
                $isGroupTaskAssignee = $employee
                    && $employee->is_task_assignee
                    && $employee->employee_group_id !== null
                    && $template?->employee_group_id === $employee->employee_group_id
                    && $template?->department_head_id !== $employee->id;

                $isAssignedApprover = $isDepartmentHead
                    || $isDelegate
                    || $isGroupTaskAssignee
                    || ($employee && $template?->items->contains(fn ($item) => $assignment->effectiveSignatoryFor($item)?->id === $employee->id));

                // Group Head/Department Head MONITORING visibility (see
                // `scopeVisibleTo()`'s matching clause) — reached this row
                // only because one of THEIR OWN people is a Task Assignee on
                // it, never because they own any item themselves. Drives the
                // "you're only monitoring" copy in `checklist-modal.blade.php`
                // and suppresses `canTakeOver` below — this viewer must never
                // be offered a way to take over/complete someone else's item,
                // even though the server would independently reject it
                // anyway (see `ChecklistDelegationController::authorizeItemAction()`).
                $isMonitoring = $employee
                    && ! $isAssignedApprover
                    && $template?->use_task_assignee_as_signatory
                    && $monitoredEmployeeIds->isNotEmpty()
                    && $template->items->contains(fn ($item) => $monitoredEmployeeIds->contains($assignment->effectiveSignatoryFor($item)?->id));

                $isReadyForApproval = $usesPerItemApprovers
                    && $assignment->allItemsCompleted()
                    && in_array($assignment->status, ['pending', 'viewed'], true);

                // The pool offered in the "Employee / Approver" (whole-
                // checklist delegate) and "Assign To" (per-item reassign)
                // pickers — restricted to employees under this checklist's
                // own Clearance Signatory (its Employee Master group),
                // never the full active-employee roster. See
                // `eligibleAssigneesFor()`'s own docblock for the fallback
                // when this checklist has no group at all.
                $rowAssignableEmployees = $this->eligibleAssigneesFor($assignment);
                // Whether the list above is a REAL group restriction, as
                // opposed to the "no group configured" unrestricted
                // fallback — consulted by `groupIntoCombinedApprovals()` so
                // a combined card that merges a real, grouped checklist
                // with a groupless one (e.g. the same person is both the
                // Information Services Clearance Signatory AND this
                // request's Immediate Head) restricts the whole card's
                // delegate picker to the real group, instead of the
                // groupless checklist's unrestricted fallback silently
                // widening it back out to every active employee. An
                // Immediate Head/Department Head checklist counts as a real
                // restriction too — it has no `employee_group_id` of its
                // own, but `eligibleAssigneesFor()` still narrows it to the
                // current Clearance Signatory's own group (plus anyone
                // already assigned elsewhere on this request).
                $rowHasGroupRestriction = ($template && $template->employee_group_id !== null)
                    || (bool) $template?->is_immediate_head_checklist;

                return [
                    // Grouping key — every checklist the same approver is
                    // responsible for on the same offboarding request is
                    // combined into one card below (see `groupIntoCombinedApprovals()`).
                    'offboardingRequestId' => $request->id,
                    'approverEmployeeId' => $assignment->employee_id,
                    'assignmentId' => $assignment->id,
                    // Only actually consulted for the grouping-key fallback
                    // below, when this row has no owning employee at all (a
                    // "Use Task Assignee as Clearance Signatory" checklist) —
                    // ensures each such template gets its own card instead of
                    // several unrelated headless checklists silently merging
                    // under one shared "no employee" key.
                    'checklistTemplateId' => $template?->id,
                    // Internal-only, consulted only while aggregating a
                    // group's `displayStatus` — never copied into a card's
                    // final output.
                    'rowStatus' => $assignment->status,
                    // Internal-only, consulted only while building the
                    // card's own `isOnHold`/`declineReason`/hold-removal
                    // fields below — a genuinely combined multi-checklist
                    // card only surfaces these when exactly one checklist
                    // is on it (see `declineUrl`'s own identical rule),
                    // same reasoning: there's no single "which checklist"
                    // to attribute a shared reason/removal to otherwise.
                    'declineReasonRaw' => $assignment->decline_reason,
                    'declinedAtRaw' => $assignment->declined_at?->format('M d, Y g:i A'),
                    'onHoldRemovedAtRaw' => $assignment->on_hold_removed_at?->format('M d, Y g:i A'),
                    'onHoldRemovedByNameRaw' => $assignment->onHoldRemovedBy?->name,
                    'onHoldRemovalReasonRaw' => $assignment->on_hold_removal_reason,
                    'onHoldRemoveUrl' => route('approvals.remove-hold', $assignment->id),
                    'isReadyForApproval' => $isReadyForApproval,
                    'delegationStatusRaw' => $assignment->delegation_status,
                    'dueAtRaw' => $assignment->due_at,
                    'dueDateReached' => $assignment->hasReachedDueDate(),
                    'clearanceSigningDueAtRaw' => $assignment->clearance_signing_due_at,
                    'isClearanceSigningOverdueRow' => $assignment->isClearanceSigningOverdue(),

                    'name' => $request->employee->name,
                    // Discrete name parts alongside `name` — feeds the
                    // Approvals page's Search field (see `index.blade.php`),
                    // which matches First/Last/Middle Name and Employee
                    // Number individually rather than relying solely on the
                    // combined `name` string.
                    'firstName' => $request->employee->firstName,
                    'lastName' => $request->employee->lastName,
                    'middleName' => $request->employee->middleName,
                    'employeeCode' => $request->employee->employee_code,
                    'department' => $request->employee->department,
                    'designation' => $request->employee->designation,
                    'status' => $request->status,
                    // Already a display-ready title, frozen at creation
                    // time — see `OffboardingRequestController::store()`.
                    'reason' => $request->reason,
                    'noticeDate' => $request->notice_date?->format('M d, Y'),
                    'lastWorkingDay' => $request->last_working_day->format('M d, Y'),
                    'approvalMode' => $request->approval_mode === 'sync' ? 'Sync' : 'Async',
                    'checklistTemplates' => $template ? [$template->title] : [],
                    'assignableEmployees' => $rowAssignableEmployees,
                    'hasGroupRestriction' => $rowHasGroupRestriction,
                    // One option for the bulk "Assign Checklist" modal's
                    // checklist-selection list — carries the employee pool
                    // eligible for THIS SPECIFIC assignment's own owner (the
                    // current Clearance Signatory/Immediate Head), via
                    // `eligibleAssigneesForPool()` below, so the modal can
                    // narrow its employee multi-select to the union of only
                    // the checklists actually checked, rather than every
                    // checklist in the combined card at once. `eligible`
                    // flips to false the moment any item on this checklist
                    // gets a real assignee (via this same bulk action or
                    // "Check This List"), so there's never a meaningful
                    // "who's currently assigned" list to show alongside an
                    // eligible option — by definition, nobody is yet.
                    'poolOption' => $template ? [
                        'templateId' => $template->id,
                        'title' => $template->title,
                        'eligible' => $assignment->isEligibleForPoolAssignment(),
                        'assignableEmployees' => $this->eligibleAssigneesForPool($assignment),
                    ] : null,
                    'checklistItems' => $template
                        ? $template->items->map(function ($item) use ($assignment, $template, $progressByItemId, $isPrimaryApprover, $isDelegate, $employee, $usesPerItemApprovers, $mustBeCheckedForSubmit, $rowAssignableEmployees, $isMonitoring) {
                            $progress = $progressByItemId->get($item->id);
                            $isChecked = (bool) ($progress?->is_checked ?? false);
                            $onHold = $progress?->status === 'hold' && ! $isChecked;
                            // The Department Head's live reassignment, if any,
                            // takes precedence over the template's own static
                            // signatory — items with neither fall back to the
                            // primary approver, same as before per-item
                            // approvers existed.
                            $effectiveSignatory = $assignment->effectiveSignatoryFor($item);
                            // The TRUE original assignee (the very first
                            // `ChecklistItemAssignment` row ever created for
                            // this item — see `originalSignatoryFor()`'s own
                            // docblock), not merely the template's static
                            // `signatory_id` — so "Originally Assigned To"
                            // reflects real reassignment history (e.g. via
                            // `reassignChecklist()`), not just a deviation
                            // from the template's default.
                            $originalSignatory = $assignment->originalSignatoryFor($item);
                            $hasOriginalAssignee = $originalSignatory !== null;
                            $isReassigned = $hasOriginalAssignee && $effectiveSignatory?->id !== $originalSignatory->id;
                            // True for both the CURRENT effective signatory
                            // and the ORIGINAL one (if different) — the
                            // latter keeps clear/approve rights on just
                            // their own originally-assigned item even after
                            // `reassignChecklist()` hands the rest of the
                            // checklist to someone else (see
                            // `ChecklistDelegationController::authorizeItemEditor()`/
                            // `authorizeItemAction()`, which gate the real
                            // actions the same way).
                            $isOwnItem = $employee && (
                                ($effectiveSignatory && $effectiveSignatory->id === $employee->id)
                                || ($hasOriginalAssignee && $originalSignatory->id === $employee->id)
                            );
                            // A checklist On Hold (see `ApprovalController::decline()`)
                            // freezes every one of its own items server-side, not
                            // just client-side — the same defense-in-depth
                            // `ChecklistDelegationController`'s own action
                            // guards already provide (their `['pending','
                            // viewed']` allow-lists already reject `on_hold`),
                            // this just also keeps a fresh page load from
                            // rendering the checkboxes as clickable at all.
                            $editable = ($isPrimaryApprover || $isDelegate || $isOwnItem) && $assignment->status !== 'on_hold';
                            $checkedByEmployee = $progress?->checkedBy?->employee;
                            $heldByEmployee = $progress?->heldBy?->employee;

                            return [
                                'id' => $item->id,
                                'title' => $item->title,
                                'templateTitle' => $template->title,
                                // Which underlying checklist row this item
                                // belongs to — item-level actions (hold,
                                // reassign, take-over) already target this
                                // exact assignment via the URLs below, so
                                // they need no further change now that
                                // several checklists' items can appear
                                // together in one combined card.
                                'assignmentId' => $assignment->id,
                                // This item's own checklist's per-item-
                                // approver/completion-gate flags — a
                                // combined card can mix checklists that do
                                // and don't require full completion, so
                                // gating (`mustBeCheckedForSubmit`) and the
                                // Done-button flow (`usesPerItemApprovers`)
                                // are read per item, not off the card as a
                                // whole.
                                'usesPerItemApprovers' => $usesPerItemApprovers,
                                'mustBeCheckedForSubmit' => $mustBeCheckedForSubmit,
                                'checked' => $isChecked,
                                'remark' => $progress?->remark,
                                'approverName' => $effectiveSignatory?->name,
                                'approverCode' => $effectiveSignatory?->employee_code,
                                // Set whenever this item ever had a real
                                // original assignee at all (regardless of
                                // whether it's since been reassigned) — lets
                                // the UI show "Originally Assigned To: X"
                                // alongside the current assignee. `null` for
                                // an item that never had one, per spec: no
                                // such line renders for those.
                                'originalApproverName' => $hasOriginalAssignee ? $originalSignatory->name : null,
                                'originalApproverCode' => $hasOriginalAssignee ? $originalSignatory->employee_code : null,
                                'isReassigned' => $isReassigned,
                                'editable' => $editable,
                                // True when the current viewer is literally the
                                // effective signatory for this item (regardless
                                // of whether they're also the primary approver)
                                // — drives the "Done" per-item UI, as distinct
                                // from `editable`, which is also true for the
                                // primary approver/delegate correcting someone
                                // else's item.
                                'isOwnItem' => $isOwnItem,
                                // A peer item-approver (already granted visibility
                                // into this card) may voluntarily take over any
                                // OTHER not-yet-checked item — "Check This List" —
                                // but never one that's on Hold: that's a deliberate
                                // pause only the item's own approver can resolve.
                                // Never offered to a Group Head/Department
                                // Head who only reached this card through
                                // monitoring visibility (see `isMonitoring`
                                // above) — their access is for tracking their
                                // own employee's progress, never for taking
                                // over the work itself.
                                'canTakeOver' => ! $editable && ! $isChecked && ! $onHold && ! $isMonitoring && $assignment->status !== 'on_hold',
                                'clearedByName' => $isChecked ? $progress?->checkedBy?->name : null,
                                'clearedByCode' => $isChecked ? $checkedByEmployee?->employee_code : null,
                                'clearedAt' => $isChecked ? $progress?->checked_at?->format('M d, Y g:i A') : null,
                                // Green vs red for this item's own "Status:
                                // Checked" text — items share their
                                // checklist's own due date (no per-item due
                                // date exists), so this compares the item's
                                // actual `checked_at` against the
                                // assignment's `due_at`.
                                'completedLate' => $isChecked
                                    && $assignment->due_at !== null
                                    && $progress?->checked_at !== null
                                    && $progress->checked_at->greaterThanOrEqualTo($assignment->due_at),
                                'onHold' => $onHold,
                                'heldByName' => $onHold ? $progress?->heldBy?->name : null,
                                'heldByCode' => $onHold ? $heldByEmployee?->employee_code : null,
                                'heldAt' => $onHold ? $progress?->held_at?->format('M d, Y g:i A') : null,
                                'holdUrl' => route('approvals.items.hold', [$assignment->id, $item->id]),
                                // Fetched in the background whenever the
                                // checklist modal opens this item's own
                                // assignment, so a reopened card shows this
                                // item's actual persisted status rather than
                                // the page's original static snapshot — see
                                // `ChecklistDelegationController::checkedItemsForAssignment()`.
                                'checkedItemsUrl' => route('approvals.items.checked', $assignment->id),
                                // The button itself is only rendered for the
                                // Department Head client-side; the route is
                                // authorized server-side regardless.
                                'assignItemUrl' => route('approvals.items.assign', [$assignment->id, $item->id]),
                                // "Check This List": a peer item-approver
                                // voluntarily accepts this item as their own,
                                // without checking/completing it.
                                'takeOverUrl' => route('approvals.items.take-over', [$assignment->id, $item->id]),
                                // For the "Assign To" picker opened from
                                // this specific item — restricted to this
                                // item's own checklist's Clearance
                                // Signatory group, same pool as the row's
                                // own `assignableEmployees` above.
                                'assignableEmployees' => $rowAssignableEmployees,
                                // "Use Task Assignee as Clearance Signatory"
                                // head-approval gate (see
                                // `ChecklistItemProgress::resolveHeadApproval()`)
                                // — always false/null for any other checklist
                                // kind, so these never affect a normal
                                // single-/per-item-approver checklist's UI.
                                'headApprovalRequired' => (bool) $progress?->head_approval_required,
                                'headApprovalPending' => (bool) $progress?->head_approval_required && ! $progress?->head_approved_at,
                                'headApproverName' => $progress?->headApprover?->name,
                                'headApproverCode' => $progress?->headApprover?->employee_code,
                                // True when the CURRENT viewer is the specific
                                // Department/Group Head recorded for this
                                // item — as distinct from `isOwnItem` (the
                                // Task Assignee who checked it) and
                                // `$isMonitoring` (read-only access to every
                                // OTHER item on this same checklist).
                                'isHeadApprover' => $employee !== null && $progress?->head_approver_employee_id === $employee->id,
                                'approveHeadItemUrl' => route('approvals.items.approve-head', [$assignment->id, $item->id]),
                            ];
                        })->values()->all()
                        : [],
                    'isPrimaryApprover' => $isPrimaryApprover,
                    'isAssignedApprover' => $isAssignedApprover,
                    'isMonitoring' => (bool) $isMonitoring,
                    'isDelegate' => $isDelegate,
                    'usesPerItemApprovers' => $usesPerItemApprovers,
                    // Core/Primary vs. Secondary vs. Final Pay — same three-
                    // way split `decline()`'s own notification email derives
                    // (see `sendDeclineNotification()`'s `$checklistType`).
                    // Drives the checklist-modal's "Select All"/"Select All
                    // My Tasks" gating below: that restriction (Immediate/
                    // Group/Department Head only) applies to the Core/Primary
                    // checklist alone, never Secondary or Final Pay.
                    'isCorePrimaryChecklist' => ! $template?->is_final_pay_checklist
                        && $template?->sequence_type !== ChecklistTemplate::SEQUENCE_TYPE_SECONDARY,
                    'isImmediateHeadChecklist' => (bool) $template?->is_immediate_head_checklist,
                    'allItemsCompleted' => $assignment->allItemsCompleted(),
                    'isOverdue' => $assignment->isOverdue(),
                    'assignedByName' => $assignment->employee?->name,
                    'assignedByCode' => $assignment->employee?->employee_code,
                    'delegation' => $assignment->isDelegated() ? [
                        'templateTitle' => $template?->title,
                        'delegatedEmployeeName' => $assignment->delegatedEmployee?->name,
                        'delegatedEmployeeCode' => $assignment->delegatedEmployee?->employee_code,
                        'delegationStatus' => $assignment->delegation_status,
                        'delegatedAt' => $assignment->delegated_at?->format('M d, Y g:i A'),
                        'delegateCompletedAt' => $assignment->delegate_completed_at?->format('M d, Y g:i A'),
                    ] : null,
                    // The "Offboarding In Progress" step is omitted here —
                    // this page is scoped to checklist approval activity, and
                    // that step is redundant alongside the approve/decline
                    // steps already shown. Left untouched in `timeline()`
                    // itself since the Calendar page's timeline still uses it.
                    'timeline' => collect($request->timeline())
                        ->reject(fn ($step) => $step['label'] === 'Offboarding In Progress')
                        ->values()
                        ->all(),
                ];
            });

        // Per-approver display preference — whether multiple checklists for
        // the same offboardee are combined into one card or kept separate.
        // Defaults to combined (today's original behavior) for every
        // existing/new account; purely a view-layer choice, toggled below.
        $combineChecklists = (bool) ($user->combine_assigned_checklists ?? true);

        $approvals = $this->groupIntoCombinedApprovals($rows, $combineChecklists)
            ->concat($this->buildGeneralSignatoryApprovals($user))
            ->concat($this->buildFinalApprovalApprovals($user));

        // Filter dropdown options, dynamically sourced from whatever's
        // actually on THIS admin/HR user's own queue right now (not a
        // separate query against every employee/checklist template in the
        // system) — never hard-coded, and always in sync with what could
        // possibly match. Computed from the full, unfiltered set below so
        // the dropdowns never shrink to only the options still matching the
        // filters currently applied.
        $departments = $approvals->pluck('department')->filter()->unique()->sort()->values();
        $checklistTitles = $approvals->pluck('checklistTemplates')->flatten()->filter()->unique()->sort()->values();

        // Status/Department/Checklist Title are applied server-side (GET
        // query params, same convention as `OffboardeeController::index()`'s
        // own Status/Department filter) — a pure post-filter over the
        // already-fully-computed `$approvals` collection above, so none of
        // the assignment/grouping/permission logic that produced it is ever
        // touched. The Search field itself stays client-side (Alpine, in
        // the view) for instant results, same split as the Offboardee page.
        $statusFilter = (string) $request->query('status', '');
        $departmentFilter = (string) $request->query('department', '');
        $checklistFilter = (string) $request->query('checklist', '');

        if ($statusFilter !== '') {
            // Reuses each card's own already-computed `displayStatus`/
            // `isOverdue` (see `aggregateDisplayStatus()` and the General
            // Signatory cards' own hardcoded 'pending') rather than a new
            // status system — "Completed" maps to `ready_for_approval`
            // (every item done, awaiting the Department Head's Submit),
            // the closest this pending-only queue ever gets to "done" since
            // a genuinely approved assignment leaves the queue entirely;
            // "Due" maps to the same `isOverdue` flag the card's own badge
            // already uses.
            $approvals = $approvals->filter(fn (array $approval) => match ($statusFilter) {
                'pending' => $approval['displayStatus'] === 'pending',
                'in_progress' => in_array($approval['displayStatus'], ['in_progress', 'assigned'], true),
                'completed' => $approval['displayStatus'] === 'ready_for_approval',
                'due' => $approval['isOverdue'],
                default => true,
            });
        }

        if ($departmentFilter !== '') {
            $approvals = $approvals->where('department', $departmentFilter);
        }

        if ($checklistFilter !== '') {
            $approvals = $approvals->filter(
                fn (array $approval) => in_array($checklistFilter, $approval['checklistTemplates'], true)
            );
        }

        return view('pages.approvals.index', [
            'title' => 'Approvals',
            'approvals' => $approvals->values(),
            'combineChecklists' => $combineChecklists,
            'employees' => Employee::where('status', 'active')->orderBy('name')->get(['id', 'name', 'employee_code', 'department']),
            'departments' => $departments,
            'checklistTitles' => $checklistTitles,
            'statusFilter' => $statusFilter,
            'departmentFilter' => $departmentFilter,
            'checklistFilter' => $checklistFilter,
        ]);
    }

    /**
     * Flips the current user's own "Combine Checklist" / "Separate
     * Checklist" display preference for the Approvals page — see
     * `groupIntoCombinedApprovals()`. Affects only how that one user's own
     * queue is grouped/rendered; never touches any `OffboardingRequestApprover`
     * row, checklist template, or approval workflow.
     */
    public function updateDisplayPreference(Request $request): RedirectResponse
    {
        auth()->user()->update([
            'combine_assigned_checklists' => ! $request->boolean('separate_checklists'),
        ]);

        return back();
    }

    /**
     * General Signatory cards for the Approvals page — built and shaped
     * independently of `groupIntoCombinedApprovals()` above (which is
     * checklist-specific: template titles, per-item approvers, delegation),
     * since a General Signatory has none of that. Each card still carries
     * every field `pages.approvals.index`'s Blade already reads off a
     * checklist card (`checklistTemplates`, `delegations`, `dueAt`, etc.,
     * all empty/null here) so the shared card markup renders unchanged,
     * plus a `kind` discriminator the view uses to hide checklist-only
     * actions (Assign To) and open the General Signatory modal instead of
     * the checklist one.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function buildGeneralSignatoryApprovals(User $user)
    {
        $assignments = OffboardingRequestGeneralSignatory::query()
            // 'on_hold' stays in this queue deliberately, same reasoning
            // as `index()`'s own matching comment — a declined General
            // Signatory approval must remain visible, not disappear.
            ->whereIn('status', ['pending', 'on_hold'])
            ->whereHas('offboardingRequest', fn ($q) => $q->where('status', 'pending'))
            ->visibleTo($user)
            ->with(['offboardingRequest.employee', 'offboardingRequest.approvers', 'offboardingRequest.generalSignatoryApprovals', 'generalSignatory.clearanceSignatory', 'generalSignatory.tasks.signatory'])
            ->get()
            ->filter(fn (OffboardingRequestGeneralSignatory $assignment) => $assignment->offboardingRequest?->employee);

        // Same "loading your own queue counts as viewing" side effect
        // `index()` applies to a checklist approver's own assignments above
        // — scoped the identical way (never for an admin merely monitoring,
        // only for the General Signatory actually named on the row), and
        // guarded on `! $assignment->first_viewed_at` so this can only ever
        // fire once per assignment no matter how many times the page is
        // reloaded afterward.
        $employee = $user->employee;

        if (! $user->isAdmin() && $employee) {
            $assignments->each(function (OffboardingRequestGeneralSignatory $assignment) use ($user, $employee) {
                if ($assignment->generalSignatory->clearance_signatory_id === $employee->id && ! $assignment->first_viewed_at) {
                    $assignment->update(['first_viewed_at' => now()]);

                    // Recorded so the admin-facing Offboarding Status/Timeline
                    // shows exactly who first opened this General Signatory
                    // assignment and when — see
                    // `OffboardingRequest::approverActivityTimeline()`'s
                    // `$generalSignatoryEventsByAssignment` grouping, which
                    // is what actually surfaces this row under the right
                    // General Signatory's card.
                    $assignment->offboardingRequest->activities()->create([
                        'user_id' => $user->id,
                        'offboarding_request_general_signatory_id' => $assignment->id,
                        'action' => 'general_signatory_viewed',
                        'status' => $assignment->offboardingRequest->status,
                    ]);
                }
            });
        }

        return $assignments
            ->map(function (OffboardingRequestGeneralSignatory $assignment) {
                $request = $assignment->offboardingRequest;
                $generalSignatory = $assignment->generalSignatory;

                return [
                    'id' => 'general-signatory-' . $assignment->id,
                    'kind' => 'general_signatory',
                    'offboardingRequestId' => $request->id,
                    'name' => $request->employee->name,
                    // Same discrete name parts as the checklist rows above —
                    // feeds the Approvals page's Search field.
                    'firstName' => $request->employee->firstName,
                    'lastName' => $request->employee->lastName,
                    'middleName' => $request->employee->middleName,
                    'employeeCode' => $request->employee->employee_code,
                    'department' => $request->employee->department,
                    'designation' => $request->employee->designation,
                    'status' => $request->status,
                    // Always 'pending' — this card only ever shows while
                    // THIS General Signatory's own action on it is pending
                    // (see the `where('status', 'pending')` query above),
                    // matching every other approval card's queue badge.
                    // Deliberately NOT the request's own overall processing
                    // status — see `requestStatus`/`requestStatusLabel`
                    // below for that, a separate field so this queue badge
                    // (shared by every approval kind on this page) is never
                    // affected by adding it.
                    'displayStatus' => $assignment->status === 'on_hold' ? 'on_hold' : 'pending',
                    // The offboarding request's own current overall
                    // processing status (Pending/In Progress/Overdue/
                    // Completed/etc.) — see `OffboardingRequest::displayStatus()`,
                    // the same computed value the Admin/HR Offboarding
                    // Status page already shows, reused as-is (not
                    // recomputed) so a General Signatory sees this without
                    // navigating there. `requestStatusLabel` uses the exact
                    // same "ucfirst + underscores to spaces" convention
                    // already applied to this same value elsewhere (see
                    // `ChecklistApprovalNotifier::notifyOffboardee()`).
                    'requestStatus' => $request->displayStatus(),
                    'requestStatusLabel' => ucfirst(str_replace('_', ' ', $request->displayStatus())),
                    // Already a display-ready title, frozen at creation
                    // time — see `OffboardingRequestController::store()`.
                    'reason' => $request->reason,
                    'noticeDate' => $request->notice_date?->format('M d, Y'),
                    'lastWorkingDay' => $request->last_working_day->format('M d, Y'),
                    'approvalMode' => $request->approval_mode === 'sync' ? 'Sync' : 'Async',
                    'checklistTemplates' => [],
                    'checklistItems' => [],
                    'isPrimaryApprover' => true,
                    'isAssignedApprover' => true,
                    'isMonitoring' => false,
                    'isDelegate' => false,
                    'usesPerItemApprovers' => false,
                    'isImmediateHeadChecklist' => false,
                    'allItemsCompleted' => true,
                    'dueAt' => null,
                    'isOverdue' => false,
                    'hasReachedDueDate' => false,
                    'clearanceSigningDueAt' => $assignment->due_at?->format('M d, Y g:i A'),
                    // Machine-parseable ISO8601 counterpart to the
                    // human-formatted value above — lets the frontend
                    // compute the "5 days before" / "on or after" visual
                    // escalation live, purely from the current time, without
                    // a full-page refresh (see `checklist-modal.blade.php`'s
                    // matching `clearanceSigningDueAtIso` for the same
                    // reasoning).
                    'clearanceSigningDueAtIso' => $assignment->due_at?->toIso8601String(),
                    'isClearanceSigningOverdue' => $assignment->isClearanceSigningOverdue(),
                    'assignedByName' => null,
                    'assignedByCode' => null,
                    'delegations' => [],
                    'checklistPoolOptions' => [],
                    'showAssignChecklistPool' => false,
                    'generalSignatoryName' => $generalSignatory->clearanceSignatory?->name,
                    'generalSignatoryTasks' => $generalSignatory->tasks
                        ->map(fn ($task) => [
                            'title' => $task->title,
                            'assigneeName' => $task->signatory?->name,
                        ])
                        ->values()
                        ->all(),
                    'submitUrl' => route('general-signatory-approvals.approve', $assignment->id),
                    'declineUrl' => route('general-signatory-approvals.decline', $assignment->id),
                    // On Hold state (see `GeneralSignatoryApprovalController::decline()`)
                    // — mirrors the checklist card's own identical fields.
                    'isOnHold' => $assignment->status === 'on_hold',
                    'onHoldRemoveUrl' => route('general-signatory-approvals.remove-hold', $assignment->id),
                    'declineReason' => $assignment->decline_reason,
                    'declinedAt' => $assignment->declined_at?->format('M d, Y g:i A'),
                    'onHoldRemovedAt' => $assignment->on_hold_removed_at?->format('M d, Y g:i A'),
                    'onHoldRemovedByName' => $assignment->onHoldRemovedBy?->name,
                    'onHoldRemovalReason' => $assignment->on_hold_removal_reason,
                    'timeline' => collect($request->timeline())
                        ->reject(fn ($step) => $step['label'] === 'Offboarding In Progress')
                        ->values()
                        ->all(),
                ];
            })
            ->values();
    }

    /**
     * Final Approval as a third card "kind" on this same page — see this
     * method's counterpart `buildGeneralSignatoryApprovals()` immediately
     * above, which this mirrors field-for-field. Only ever surfaces a
     * request that has ALREADY reached `status === 'completed'` (every
     * checklist and General Signatory already cleared) — the same
     * precondition `FinalApprovalController::send()` itself enforces
     * before a Final Approval process can even be created, so a request
     * still mid-workflow never appears here regardless of who's looking.
     */
    private function buildFinalApprovalApprovals(User $user)
    {
        $assignments = OffboardingRequestFinalApproval::query()
            ->where('status', 'pending')
            ->whereHas('offboardingRequest', fn ($q) => $q->where('status', 'completed'))
            ->visibleTo($user)
            ->with(['offboardingRequest.employee', 'offboardingRequest.activities'])
            ->get()
            ->filter(fn (OffboardingRequestFinalApproval $assignment) => $assignment->offboardingRequest?->employee);

        // Same "loading your own queue counts as viewing" side effect the
        // General Signatory builder above applies, and the email path
        // (`FinalApprovalController::showEmailApproval()`) already applies
        // for a link click — opening the card in-app marks it viewed the
        // identical way, so "first_viewed_at" reflects whichever channel
        // the Final Approver actually used first.
        $employee = $user->employee;

        if (! $user->isAdmin() && $employee) {
            $assignments->each(function (OffboardingRequestFinalApproval $assignment) use ($user, $employee) {
                if ($assignment->employee_id === $employee->id && ! $assignment->first_viewed_at) {
                    $assignment->update(['first_viewed_at' => now()]);

                    $assignment->offboardingRequest->activities()->create([
                        'user_id' => $user->id,
                        'offboarding_request_final_approval_id' => $assignment->id,
                        'action' => 'final_approval_viewed',
                        'status' => $assignment->offboardingRequest->status,
                    ]);
                }
            });
        }

        return $assignments
            ->map(function (OffboardingRequestFinalApproval $assignment) {
                $request = $assignment->offboardingRequest;

                return [
                    'id' => 'final-approval-' . $assignment->id,
                    'kind' => 'final_approval',
                    'offboardingRequestId' => $request->id,
                    'name' => $request->employee->name,
                    'firstName' => $request->employee->firstName,
                    'lastName' => $request->employee->lastName,
                    'middleName' => $request->employee->middleName,
                    'employeeCode' => $request->employee->employee_code,
                    'department' => $request->employee->department,
                    'designation' => $request->employee->designation,
                    'status' => $request->status,
                    'displayStatus' => 'pending',
                    'requestStatus' => $request->displayStatus(),
                    'requestStatusLabel' => ucfirst(str_replace('_', ' ', $request->displayStatus())),
                    'reason' => $request->reason,
                    'noticeDate' => $request->notice_date?->format('M d, Y'),
                    'lastWorkingDay' => $request->last_working_day->format('M d, Y'),
                    // Requirement #3's "Original Last Working Day"/
                    // "Extended Last Working Day, if applicable" — same
                    // fields the Offboardee page's own status modal already
                    // uses (see `OffboardingRequest::isLastWorkingDayExtended()`).
                    'originalLastWorkingDay' => $request->original_last_working_day?->format('M d, Y'),
                    'isLastWorkingDayExtended' => $request->isLastWorkingDayExtended(),
                    'approvalMode' => $request->approval_mode === 'sync' ? 'Sync' : 'Async',
                    'checklistTemplates' => [],
                    'checklistItems' => [],
                    'isPrimaryApprover' => true,
                    'isAssignedApprover' => true,
                    'isMonitoring' => false,
                    'isDelegate' => false,
                    'usesPerItemApprovers' => false,
                    'isImmediateHeadChecklist' => false,
                    'allItemsCompleted' => true,
                    'dueAt' => null,
                    'isOverdue' => false,
                    'hasReachedDueDate' => false,
                    'assignedByName' => null,
                    'assignedByCode' => null,
                    'delegations' => [],
                    'checklistPoolOptions' => [],
                    'showAssignChecklistPool' => false,
                    'generalSignatoryName' => null,
                    'generalSignatoryTasks' => [],
                    // "Checklist completion/status" and "Clearance status"
                    // (requirement #3) are exactly what the Clearance Form
                    // already summarizes — surfaced here as the "Supporting
                    // documents" link rather than re-deriving a duplicate
                    // per-checklist breakdown. Same routes
                    // `OffboardeeController::index()` already generates.
                    'clearanceFormUrl' => route('clearance-form.pdf', $request),
                    'printClearanceFormUrl' => route('clearance-form.print', $request),
                    'finalApprovalStatus' => $assignment->status,
                    'submitUrl' => route('final-approval-approvals.approve', $assignment->id),
                    // "Previous approval/signatory status" — already
                    // includes its own dedicated Final Approval steps (see
                    // `OffboardingRequest::timeline()`), so the modal shows
                    // the same history a checklist card's modal would.
                    'timeline' => collect($request->timeline())
                        ->reject(fn ($step) => $step['label'] === 'Offboarding In Progress')
                        ->values()
                        ->all(),
                ];
            })
            ->values();
    }

    /**
     * Combines every checklist the same approver is responsible for on the
     * same offboarding request into one card — the whole point of this
     * feature: an approver assigned several checklist templates for one
     * offboardee sees (and acts on) exactly one card, not one per
     * checklist. A lone checklist simply becomes a "group of one", so
     * there's no special-cased single-vs-combined rendering path.
     *
     * When `$combine` is false (the approver's own "Separate Checklist"
     * display preference — see `updateDisplayPreference()`), every
     * checklist gets its own card instead, keyed by its own assignment id
     * rather than by employee — this is purely a different grouping key fed
     * into the SAME generic per-group builder below, so every field a card
     * carries (aggregated status, items, delegations, timeline, …) is
     * computed identically either way, just over a group of size 1. The
     * one place that genuinely needs to know which mode is active is the
     * action-URL block: a real combined group's routes intentionally
     * re-derive and act on the employee's ENTIRE pending set server-side
     * (`approveGroup()`/`saveProgressGroup()`), which would silently sweep
     * in the OTHER checklist(s) too if used for a card that's only
     * DISPLAYING one of several — so separate mode always falls back to the
     * single-assignment routes, exactly like a headless (no owning
     * employee) card already does.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function groupIntoCombinedApprovals($rows, bool $combine = true)
    {
        return $rows
            // A "Use Task Assignee as Clearance Signatory" checklist has no
            // owning employee (`approverEmployeeId` null) — falling back to
            // the template id keeps each such headless checklist on its own
            // card instead of merging unrelated ones under one shared
            // "no employee" key. A headless template is therefore always a
            // "group of one" by construction. In "Separate Checklist" mode,
            // every row is its own group regardless of owner, keyed by its
            // own assignment id.
            ->groupBy(fn (array $row) => $combine
                ? $row['offboardingRequestId'] . ':' . ($row['approverEmployeeId'] ?? 'template-' . $row['checklistTemplateId'])
                : $row['offboardingRequestId'] . ':assignment-' . $row['assignmentId'])
            ->map(function ($group) use ($combine) {
                $first = $group->first();
                $earliestDueAt = $group->pluck('dueAtRaw')->filter()->sort()->first();
                $earliestClearanceSigningDueAt = $group->pluck('clearanceSigningDueAtRaw')->filter()->sort()->first();

                // Whether this specific card is safe to act on via the
                // "whole employee group" routes — only true for a genuine
                // combined card with a real owning employee. Separate mode,
                // and any headless card, always use the single-assignment
                // routes instead (see this method's docblock above).
                $useGroupRoutes = $combine && $first['approverEmployeeId'];

                // Same null-safe fallback as the grouping key above, so two
                // different headless checklists (or, in separate mode, any
                // two checklists at all) on the same request never collide
                // on the same card id (e.g. for Alpine's `:key`).
                $cardOwnerKey = $combine
                    ? ($first['approverEmployeeId'] ?? 'template-' . $first['checklistTemplateId'])
                    : 'assignment-' . $first['assignmentId'];

                return [
                    'id' => $first['offboardingRequestId'] . '-' . $cardOwnerKey,
                    'offboardingRequestId' => $first['offboardingRequestId'],
                    'approverEmployeeId' => $first['approverEmployeeId'],
                    'name' => $first['name'],
                    'firstName' => $first['firstName'],
                    'lastName' => $first['lastName'],
                    'middleName' => $first['middleName'],
                    'employeeCode' => $first['employeeCode'],
                    'department' => $first['department'],
                    'designation' => $first['designation'],
                    'status' => $first['status'],
                    'displayStatus' => $this->aggregateDisplayStatus($group),
                    'reason' => $first['reason'],
                    'noticeDate' => $first['noticeDate'],
                    'lastWorkingDay' => $first['lastWorkingDay'],
                    'approvalMode' => $first['approvalMode'],
                    'checklistTemplates' => $group->pluck('checklistTemplates')->flatten()->values()->all(),
                    'checklistItems' => $group->pluck('checklistItems')->flatten(1)->values()->all(),
                    // The "Employee / Approver" picker for the whole-card
                    // "Assign To" (delegate every checklist in this card at
                    // once) — the union of each member checklist's own
                    // Clearance-Signatory-group pool, deduped by employee
                    // id. In the common case a card has exactly one
                    // checklist, so this is just that checklist's own pool.
                    //
                    // A real group restriction always wins over a groupless
                    // checklist's unrestricted fallback: if ANY checklist in
                    // this card has an actual Clearance-Signatory group,
                    // only THOSE checklists' member lists are unioned — a
                    // co-merged groupless checklist (e.g. this same person
                    // is also the request's Immediate Head) never widens the
                    // picker back out to every active employee. Only when
                    // NO checklist in the whole card has any group at all
                    // does the true "everyone" fallback apply.
                    'assignableEmployees' => (function () use ($group) {
                        $restricted = $group->filter(fn (array $row) => $row['hasGroupRestriction']);
                        $source = $restricted->isNotEmpty() ? $restricted : $group;

                        return $source->pluck('assignableEmployees')->flatten(1)->unique('id')->values()->all();
                    })(),
                    'isPrimaryApprover' => $first['isPrimaryApprover'],
                    'isAssignedApprover' => $group->contains('isAssignedApprover', true),
                    // A headless (Task-Assignee-as-Signatory) template is
                    // always a "group of one" by construction (see this
                    // method's own docblock above), so `$first` alone is
                    // authoritative here — never mixed with a real owning
                    // employee's other, non-monitored checklists.
                    'isMonitoring' => (bool) ($first['isMonitoring'] ?? false),
                    'isDelegate' => $group->contains('isDelegate', true),
                    'usesPerItemApprovers' => $group->contains('usesPerItemApprovers', true),
                    // Conservative: only true when EVERY checklist merged
                    // onto this card is Core/Primary — a card that combines
                    // a Core/Primary checklist with a Secondary/Final Pay one
                    // (the same Head owning both) must NOT have its Select
                    // All checkboxes hidden on account of the other member's
                    // type, so the new Head-only restriction below only ever
                    // fires when the whole card is unambiguously Core/Primary.
                    'isCorePrimaryChecklist' => $group->every(fn (array $row) => $row['isCorePrimaryChecklist']),
                    'isImmediateHeadChecklist' => $group->contains('isImmediateHeadChecklist', true),
                    'allItemsCompleted' => $group->every(fn (array $row) => $row['allItemsCompleted']),
                    'dueAt' => $earliestDueAt?->format('M d, Y'),
                    'isOverdue' => $group->contains('isOverdue', true),
                    // Whether ANY checklist in this card has reached/passed
                    // its due date — since Submit approves the whole group
                    // in one action, reaching even one member's due date is
                    // enough to trigger the due-date confirmation/remarks
                    // dialog for the group as a whole (see
                    // `OffboardingRequestApprover::hasReachedDueDate()`).
                    'hasReachedDueDate' => $group->contains('dueDateReached', true),
                    // Clearance Signing Due Date — a separate, purely
                    // informational deadline from `dueAt` above (see
                    // `OffboardingRequestApprover.clearance_signing_due_at`).
                    // Earliest across the group, same convention as `dueAt`.
                    'clearanceSigningDueAt' => $earliestClearanceSigningDueAt?->format('M d, Y g:i A'),
                    'clearanceSigningDueAtIso' => $earliestClearanceSigningDueAt?->toIso8601String(),
                    'isClearanceSigningOverdue' => $group->contains('isClearanceSigningOverdueRow', true),
                    'assignedByName' => $first['assignedByName'],
                    'assignedByCode' => $first['assignedByCode'],
                    'delegations' => $group->pluck('delegation')->filter()->values()->all(),
                    // Bulk "Assign Checklist" — one option per checklist
                    // template in this card, each carrying its own
                    // eligibility and group-restricted employee pool (see
                    // the row-level `poolOption` above). Only rendered when
                    // `showAssignChecklistPool` below is true.
                    'checklistPoolOptions' => $group->pluck('poolOption')->filter()->unique('templateId')->values()->all(),
                    // "Only when relevant": the bulk modal only makes sense
                    // once this card actually combines more than one
                    // distinct checklist template — a single-checklist card
                    // has nothing else to bulk-open, so no button renders.
                    // Naturally always false in "Separate Checklist" mode,
                    // since every card there is a group of exactly one.
                    'showAssignChecklistPool' => $first['isPrimaryApprover']
                        && $group->pluck('checklistTemplates')->flatten()->unique()->count() > 1,
                    // A headless (Use Task Assignee as Clearance Signatory)
                    // card, or any card while "Separate Checklist" mode is
                    // on, has no safe "whole employee group" action to key
                    // the `approvals.group.*` routes by — see
                    // `$useGroupRoutes` above. `route()` also throws on a
                    // null required parameter, which would break the WHOLE
                    // page render for anyone viewing a headless card, not
                    // just on click — so the null-employee case must always
                    // fall back too, regardless of `$combine`. Save
                    // Progress/Submit fall back to the single-row routes
                    // keyed by this one assignment; whole-card delegate/
                    // bulk-assign have no single-row equivalent for a
                    // headless checklist (no owner to delegate FROM) and
                    // stay null there, but DO have one for an ordinary
                    // checklist shown separately (`approvals.assign`).
                    'approveUrl' => $useGroupRoutes
                        ? route('approvals.group.approve', ['offboardingRequest' => $first['offboardingRequestId'], 'employee' => $first['approverEmployeeId']])
                        : route('approvals.approve', $first['assignmentId']),
                    // Decline stays a per-checklist action — there's no
                    // "group decline" route, unlike Approve/Save Progress —
                    // so a genuinely combined multi-checklist card simply
                    // gets no Decline button at all (null here); only a
                    // card that resolves to exactly one checklist gets one.
                    'declineUrl' => $group->pluck('checklistTemplates')->flatten()->unique()->count() === 1
                        ? route('approvals.decline', $first['assignmentId'])
                        : null,
                    // Whether ANY checklist in this card is currently On
                    // Hold (see `ApprovalController::decline()`) — used to
                    // disable Approve/Decline and every task checkbox on
                    // the card, and to show the "Remove On Hold" action
                    // instead. `onHoldRemoveUrl` (and the reason/removal
                    // fields alongside it) follow `declineUrl`'s own rule:
                    // only a card that resolves to exactly one checklist
                    // gets one, since there's no single checklist to
                    // attribute a shared reason to otherwise.
                    'isOnHold' => $group->contains('rowStatus', 'on_hold'),
                    // Computed the SAME unconditional way `declineUrl` above
                    // is — never gated on the row already being on hold —
                    // since this is read from a page that was rendered
                    // BEFORE any decline happened; gating it on current
                    // status left it permanently null in every page's
                    // initial payload (nothing is ever on hold at render
                    // time), which meant the "Remove On Hold" button could
                    // never appear no matter what happened afterward.
                    'onHoldRemoveUrl' => $group->pluck('checklistTemplates')->flatten()->unique()->count() === 1
                        ? route('approvals.remove-hold', $first['assignmentId'])
                        : null,
                    'declineReason' => $group->firstWhere('rowStatus', 'on_hold')['declineReasonRaw'] ?? null,
                    'declinedAt' => $group->firstWhere('rowStatus', 'on_hold')['declinedAtRaw'] ?? null,
                    'onHoldRemovedAt' => $group->firstWhere('rowStatus', 'on_hold')['onHoldRemovedAtRaw'] ?? null,
                    'onHoldRemovedByName' => $group->firstWhere('rowStatus', 'on_hold')['onHoldRemovedByNameRaw'] ?? null,
                    'onHoldRemovalReason' => $group->firstWhere('rowStatus', 'on_hold')['onHoldRemovalReasonRaw'] ?? null,
                    // Whole-checklist reassignment (see
                    // `ChecklistDelegationController::reassignChecklist()`)
                    // — single-checklist cards only, same `count() === 1`
                    // rule `declineUrl`/`onHoldRemoveUrl` already use, since
                    // there's no single checklist to reassign otherwise.
                    // Reuses the pool already computed for the bulk "Assign
                    // Checklist" picker (`poolOption.assignableEmployees`,
                    // via `eligibleAssigneesForPool()`) rather than a second
                    // identical query — this action targets exactly one
                    // employee, but draws from the same eligible pool.
                    'reassignChecklistUrl' => $group->pluck('checklistTemplates')->flatten()->unique()->count() === 1
                        ? route('approvals.reassign-checklist', $first['assignmentId'])
                        : null,
                    'reassignEligibleEmployees' => $first['poolOption']['assignableEmployees'] ?? [],
                    'assignUrl' => $useGroupRoutes
                        ? route('approvals.group.assign', ['offboardingRequest' => $first['offboardingRequestId'], 'employee' => $first['approverEmployeeId']])
                        : ($first['approverEmployeeId'] ? route('approvals.assign', $first['assignmentId']) : null),
                    'assignPoolUrl' => $useGroupRoutes
                        ? route('approvals.group.assign-pool', ['offboardingRequest' => $first['offboardingRequestId'], 'employee' => $first['approverEmployeeId']])
                        : null,
                    'saveProgressUrl' => $useGroupRoutes
                        ? route('approvals.group.save-progress', ['offboardingRequest' => $first['offboardingRequestId'], 'employee' => $first['approverEmployeeId']])
                        : route('approvals.save-progress', $first['assignmentId']),
                    'timeline' => $first['timeline'],
                ];
            })
            ->values();
    }

    /**
     * The pool offered in the "Employee / Approver" (whole-checklist
     * delegate) and "Assign To" (per-item reassign) pickers on the
     * Approvals page — restricted to employees under this checklist's own
     * Clearance Signatory, i.e. members of the same Employee Master group
     * this checklist's `employee_group_id` points to, flagged
     * `is_task_assignee`, excluding the Clearance Signatory/Department
     * Head themselves — the exact same eligibility rule
     * `ChecklistTemplateController::eligibleSignatoryIds()` already
     * enforces server-side for the Task Assignee picker on the checklist
     * template's own create/edit form, applied here to these two
     * delegation pickers instead.
     *
     * An Immediate Head/Department Head checklist has no `employee_group_id`
     * of its own — its Clearance Signatory is resolved per-REQUEST (whoever
     * `OffboardingRequest::immediate_head_id` names), never a fixed template
     * config — so it's delegated to `eligibleAssigneesForImmediateHeadChecklist()`
     * instead of falling through to the unrestricted "every active employee"
     * list every other groupless (legacy, pre-group) checklist still falls
     * back to.
     *
     * @return array<int, array{id: string, name: string, code: string, department: ?string}>
     */
    private function eligibleAssigneesFor(OffboardingRequestApprover $assignment): array
    {
        $template = $assignment->checklistTemplate;

        if ($template?->is_immediate_head_checklist) {
            return $this->eligibleAssigneesForImmediateHeadChecklist($assignment);
        }

        $query = ($template && $template->employee_group_id)
            ? Employee::where('employee_group_id', $template->employee_group_id)
                ->where('is_task_assignee', true)
                ->where('id', '!=', $template->department_head_id)
            : Employee::query();

        return $query->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'employee_code', 'department'])
            ->map(fn (Employee $employee) => [
                'id' => (string) $employee->id,
                'name' => $employee->name,
                'code' => $employee->employee_code,
                'department' => $employee->department,
            ])
            ->values()
            ->all();
    }

    /**
     * `eligibleAssigneesFor()`'s branch for an Immediate Head/Department
     * Head checklist. This template has no Clearance-Signatory group of its
     * own to filter to (its approver is resolved per-request, not
     * per-template), so eligibility here is instead computed against the
     * CURRENT request's actual Clearance Signatory (`$assignment->employee_id`
     * — whoever the offboardee's immediate head genuinely is on THIS
     * request), never the logged-in user or any other assumption: active
     * employees under that Clearance Signatory's own Employee Group — the
     * same "Group Head → members" relationship
     * `OffboardingRequestApprover::eligiblePoolAssigneeIds()` already uses
     * elsewhere, so "under the Clearance Signatory" means the same thing in
     * both places. Deliberately strict: someone already working on another
     * checklist elsewhere on this same request is NOT included here purely
     * on that basis — only membership in the signatory's own group
     * qualifies.
     *
     * Never includes the Clearance Signatory themselves (delegating a
     * checklist to yourself is meaningless and separately rejected by
     * `assign()`/`assignGroup()`).
     *
     * @return array<int, array{id: string, name: string, code: string, department: ?string}>
     */
    private function eligibleAssigneesForImmediateHeadChecklist(OffboardingRequestApprover $assignment): array
    {
        $signatoryId = $assignment->employee_id;

        $groupIds = EmployeeGroup::where('group_head_employee_id', $signatoryId)->pluck('id');

        return Employee::where('status', 'active')
            ->where('id', '!=', $signatoryId)
            ->whereIn('employee_group_id', $groupIds)
            ->orderBy('name')
            ->get(['id', 'name', 'employee_code', 'department'])
            ->map(fn (Employee $employee) => [
                'id' => (string) $employee->id,
                'name' => $employee->name,
                'code' => $employee->employee_code,
                'department' => $employee->department,
            ])
            ->values()
            ->all();
    }

    /**
     * The employee pool offered by the bulk "Assign Checklist" modal for
     * one specific assignment — unlike `eligibleAssigneesFor()` above
     * (restricted to the CHECKLIST TEMPLATE's own `employee_group_id`, with
     * a company-wide fallback when a template has none), this shapes
     * `OffboardingRequestApprover::eligiblePoolAssigneeIds()` for display —
     * see that method's docblock for why it's anchored on the assignment's
     * own owner rather than the template.
     *
     * @return array<int, array{id: string, name: string, code: string, department: ?string}>
     */
    private function eligibleAssigneesForPool(OffboardingRequestApprover $assignment): array
    {
        return Employee::whereIn('id', $assignment->eligiblePoolAssigneeIds())
            ->orderBy('name')
            ->get(['id', 'name', 'employee_code', 'department'])
            ->map(fn (Employee $employee) => [
                'id' => (string) $employee->id,
                'name' => $employee->name,
                'code' => $employee->employee_code,
                'department' => $employee->department,
            ])
            ->values()
            ->all();
    }

    /**
     * Same priority order the old single-row `displayStatus` used —
     * overdue, then approved/on-hold/declined, then ready-for-approval,
     * then delegation status — generalized across every checklist in the
     * group: overdue if ANY member still outstanding is overdue; approved
     * only once EVERY member is approved; on_hold if ANY member is on
     * hold (see `ApprovalController::decline()`); declined if ANY member
     * still carries the legacy `'declined'` status (rows declined before
     * this behavior changed to On Hold); ready-for-approval once every
     * not-yet-approved member has satisfied its own completion gate;
     * otherwise falls back to the first member's delegation status
     * (identical across members whenever only one is delegated, which is
     * the common case) or "pending".
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $group
     */
    private function aggregateDisplayStatus($group): string
    {
        if ($group->contains('isOverdue', true)) {
            return 'overdue';
        }

        if ($group->every(fn (array $row) => $row['rowStatus'] === 'approved')) {
            return 'approved';
        }

        if ($group->contains('rowStatus', 'on_hold')) {
            return 'on_hold';
        }

        if ($group->contains('rowStatus', 'declined')) {
            return 'declined';
        }

        $outstanding = $group->reject(fn (array $row) => $row['rowStatus'] === 'approved');

        if ($outstanding->isNotEmpty() && $outstanding->every(fn (array $row) => $row['isReadyForApproval'])) {
            return 'ready_for_approval';
        }

        return $group->first()['delegationStatusRaw'] ?? 'pending';
    }

    public function approve(Request $request, OffboardingRequestApprover $offboardingRequestApprover): RedirectResponse
    {
        $this->authorizeAssignment($offboardingRequestApprover);

        abort_if(
            $offboardingRequestApprover->offboardingRequest->isReadOnly(),
            422,
            'This offboarding request has been retracted and can no longer be actioned.'
        );

        if (! auth()->user()->hasUsableSignature()) {
            return $this->redirectToUploadSignature();
        }

        abort_unless(
            in_array($offboardingRequestApprover->status, ['pending', 'viewed'], true),
            422,
            'This has already been actioned.'
        );

        // Trimmed BEFORE validation so a whitespace-only remark ("   ")
        // fails the `required` rule below exactly like an empty one would —
        // `required` alone only rejects null/"", not a string of blanks.
        if ($request->has('remarks')) {
            $request->merge(['remarks' => trim((string) $request->input('remarks'))]);
        }

        $validated = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.checklist_item_id' => ['required', 'exists:checklist_items,id'],
            'items.*.is_checked' => ['nullable', 'boolean'],
            'items.*.remark' => ['nullable', 'string'],
            // Whole-checklist "reason for the delay" remark — REQUIRED the
            // moment this checklist has reached/passed its own due date
            // (never trusted from the client's own "hasReachedDueDate" flag
            // in `checklist-modal.blade.php`: recomputed here from
            // `due_at`, the same source of truth `wasCompletedLate()` uses,
            // so a direct/manipulated request can't skip it just by
            // omitting the field). Still optional while the checklist is
            // on time, unchanged from before.
            'remarks' => [
                $offboardingRequestApprover->hasReachedDueDate() ? 'required' : 'nullable',
                'string',
                'max:2000',
            ],
        ], [
            'remarks.required' => 'A remark is required explaining why this checklist was not completed by its due date.',
        ]);

        // The Department Head may check items themselves right up to the
        // moment of Submit — including items originally assigned to a
        // different signatory, since they can take over any item directly.
        // Persist that state BEFORE checking completeness below, since a
        // per-item-approver checklist may be finished off by the Department
        // Head's own checkbox in this very action.
        ChecklistItemProgress::syncForAssignment($offboardingRequestApprover, $validated['items'] ?? [], auth()->id());

        if ($offboardingRequestApprover->requiresAllItemsCompletedBeforeApproval() && ! $offboardingRequestApprover->allItemsCompleted()) {
            abort(422, 'All checklist items must be checked before this checklist can be approved.');
        }

        $this->finalizeGroupApproval(collect([$offboardingRequestApprover]), auth()->user(), $validated['remarks'] ?? null);

        return back()->with('success', $offboardingRequestApprover->offboardingRequest->employee->name . '\'s offboarding request was approved.');
    }

    /**
     * The combined-card equivalent of `approve()`: submits every checklist
     * this employee is the assigned approver for on this request in ONE
     * action, exactly what the Approvals page's combined card now posts to.
     * Re-derives group membership from the database (never trusts a
     * client-supplied list of which checklists to approve), splits the
     * submitted items by which member checklist each one actually belongs
     * to, persists progress per member, then requires EVERY member to
     * individually satisfy its own existing completion gate before any of
     * them are approved — the whole submission is atomic, so a single
     * still-incomplete checklist in the group blocks approval of all of
     * them, never a partial approve.
     */
    public function approveGroup(Request $request, OffboardingRequest $offboardingRequest, Employee $employee): RedirectResponse
    {
        $this->authorizeGroupPrimary($employee);

        if (! auth()->user()->hasUsableSignature()) {
            return $this->redirectToUploadSignature();
        }

        // Same "required the moment ANY member has reached its own due
        // date" rule `approve()` applies to a single checklist — mirrors
        // the frontend's own group aggregation (`$group->contains('dueDateReached', true)`
        // in `checklist-modal.blade.php`) rather than trusting it, since a
        // direct/manipulated request could otherwise omit the remark
        // entirely. A lightweight, unlocked read is enough here (this is
        // only deciding a validation rule, not mutating anything) — the
        // transaction below re-fetches these same rows `lockForUpdate()`.
        $anyMemberOverdue = OffboardingRequestApprover::where('offboarding_request_id', $offboardingRequest->id)
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'viewed'])
            ->get()
            ->contains(fn (OffboardingRequestApprover $member) => $member->hasReachedDueDate());

        if ($request->has('remarks')) {
            $request->merge(['remarks' => trim((string) $request->input('remarks'))]);
        }

        $validated = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.checklist_item_id' => ['required', 'exists:checklist_items,id'],
            'items.*.is_checked' => ['nullable', 'boolean'],
            'items.*.remark' => ['nullable', 'string'],
            'remarks' => [$anyMemberOverdue ? 'required' : 'nullable', 'string', 'max:2000'],
        ], [
            'remarks.required' => 'A remark is required explaining why this checklist was not completed by its due date.',
        ]);

        DB::transaction(function () use ($offboardingRequest, $employee, $validated) {
            $members = OffboardingRequestApprover::where('offboarding_request_id', $offboardingRequest->id)
                ->where('employee_id', $employee->id)
                ->whereIn('status', ['pending', 'viewed'])
                ->lockForUpdate()
                ->get();

            abort_if($members->isEmpty(), 422, 'This has already been actioned.');

            $itemToAssignmentId = [];

            foreach ($members as $member) {
                $member->loadMissing('checklistTemplate.items');

                foreach ($member->checklistTemplate->items as $item) {
                    $itemToAssignmentId[$item->id] = $member->id;
                }
            }

            $itemsByAssignmentId = collect($validated['items'] ?? [])
                ->filter(fn (array $row) => isset($itemToAssignmentId[$row['checklist_item_id']]))
                ->groupBy(fn (array $row) => $itemToAssignmentId[$row['checklist_item_id']]);

            foreach ($members as $member) {
                ChecklistItemProgress::syncForAssignment($member, $itemsByAssignmentId->get($member->id, collect())->all(), auth()->id());
            }

            foreach ($members as $member) {
                abort_if(
                    $member->requiresAllItemsCompletedBeforeApproval() && ! $member->allItemsCompleted(),
                    422,
                    'All checklist items must be checked before this checklist can be approved.'
                );
            }

            $this->finalizeGroupApproval($members, auth()->user(), $validated['remarks'] ?? null);
        });

        return back()->with('success', $employee->name . '\'s assigned checklists were approved.');
    }

    /**
     * The message shown for each possible state of an emailed approval
     * link — shared verbatim between the confirmation page (`showEmailApproval()`)
     * and the JSON the actual approval endpoint returns
     * (`confirmEmailApproval()`), so both always agree on the wording.
     */
    private const EMAIL_APPROVAL_MESSAGES = [
        'invalid' => 'This approval link is invalid or has expired.',
        'already_approved' => 'This checklist has already been approved.',
        'not_actionable' => 'This checklist can no longer be approved from this link.',
        'not_ready' => 'This checklist is not yet ready for approval — not all items have been completed.',
        'remarks_required' => 'This checklist has reached its due date and requires a remark explaining the delay before it can be approved. Log in to your account and approve it from there.',
        'no_signature' => self::MISSING_SIGNATURE_MESSAGE . ' Log in to your account, upload it from your Profile page, then use this link again.',
        'confirm' => 'Please confirm to approve this checklist.',
        'approved' => 'The checklist was successfully approved.',
    ];

    /**
     * Sends the acting user to their Profile page (where the Electronic
     * Signature card lives) with a clear, flashed reason why their approval
     * didn't go through — the in-app equivalent of the emailed link's
     * `no_signature` state below, for the same missing-e-signature rule.
     */
    private function redirectToUploadSignature(): RedirectResponse
    {
        return redirect()->route('profile')->with('error', self::MISSING_SIGNATURE_MESSAGE);
    }

    /**
     * Public confirmation page for the "Approve" link embedded in the
     * Checklist Ready for Department Head Approval email — reachable while
     * logged out (no `auth` middleware, no redirect to `/signin`). Only
     * VALIDATES and DISPLAYS the checklist's current state here; nothing is
     * approved yet — the page itself asks the Department Head to confirm
     * (via a SweetAlert dialog showing the checklist details), and only
     * that explicit confirmation triggers `confirmEmailApproval()` below.
     */
    public function showEmailApproval(int $id, string $token): View
    {
        [$state, $members] = $this->resolveEmailApprovalState($id, $token);
        $first = $members->first();

        return view('pages.approvals.email-confirm', [
            'title' => 'Checklist Approval',
            'state' => $state,
            'message' => self::EMAIL_APPROVAL_MESSAGES[$state],
            'confirmUrl' => route('approval.confirm', ['id' => $id, 'token' => $token]),
            'offboardeeName' => $first?->offboardingRequest->employee->name,
            'offboardeeEmployeeCode' => $first?->offboardingRequest->employee->employee_code,
            // Every checklist this token's group currently covers — a
            // comma-joined list when there's more than one, same as the
            // consolidated notification/approval emails.
            'checklistTitle' => $members->pluck('checklistTemplate.title')->filter()->implode(', '),
            'departmentHeadName' => $first?->employee?->name,
        ]);
    }

    /**
     * The actual approval, triggered only once the Department Head confirms
     * on the page above — never on the bare GET link itself, so simply
     * clicking (or an email client prefetching) the link can never approve
     * anything by accident.
     *
     * Re-validates the link and re-derives the assignment's state from
     * scratch (never trusting whatever `showEmailApproval()` rendered
     * moments earlier — state can change in between), then re-checks it
     * AGAIN inside a row-locked transaction immediately before approving,
     * so two near-simultaneous confirmations (a genuine double-click, or
     * two browser tabs on the same link) can never both pass the "still
     * pending" check — the second always lands on `already_approved`
     * instead of re-running the approval. Every branch — invalid link,
     * already approved, no longer actionable, not ready, or a fresh
     * approval — returns the exact same JSON shape the confirmation page
     * already knows how to render as a SweetAlert.
     */
    public function confirmEmailApproval(int $id, string $token): JsonResponse
    {
        [$state, $members] = $this->resolveEmailApprovalState($id, $token);

        if ($state === 'confirm' && $members->isNotEmpty()) {
            $departmentHead = $members->first()->employee;
            $offboardingRequestId = $members->first()->offboarding_request_id;
            $employeeId = $members->first()->employee_id;

            // The Department Head might never have logged into the app
            // before — they only need an email address to have received
            // this link, not an account — so their account is guaranteed
            // to exist here (same convention used everywhere else an
            // approver's identity needs to be attributed), rather than
            // silently attributing the approval to no one.
            $actor = $departmentHead ? User::findOrCreateApprover($departmentHead) : null;

            $state = DB::transaction(function () use ($offboardingRequestId, $employeeId, $actor) {
                // Re-expand to whatever this employee's current full group
                // is at click time — never just the members that existed
                // when the email was sent — so a checklist added to the
                // same approver afterward is swept into the same one-click
                // approval too, consistent with the combined-card behavior.
                $locked = OffboardingRequestApprover::where('offboarding_request_id', $offboardingRequestId)
                    ->where('employee_id', $employeeId)
                    ->whereIn('status', ['pending', 'viewed'])
                    ->lockForUpdate()
                    ->get();

                if ($locked->isEmpty()) {
                    $allApproved = OffboardingRequestApprover::where('offboarding_request_id', $offboardingRequestId)
                        ->where('employee_id', $employeeId)
                        ->get()
                        ->every(fn (OffboardingRequestApprover $m) => $m->status === 'approved');

                    return $allApproved ? 'already_approved' : 'not_actionable';
                }

                $notReady = $locked->contains(
                    fn (OffboardingRequestApprover $m) => $m->requiresAllItemsCompletedBeforeApproval() && ! $m->allItemsCompleted()
                );

                if ($notReady) {
                    return 'not_ready';
                }

                // The one-click email link has no remarks field to collect
                // an overdue explanation at all — rather than silently
                // approving without one (the exact loophole this whole
                // feature exists to close), send the approver to the app's
                // own modal, which does collect and require it.
                if ($locked->contains(fn (OffboardingRequestApprover $m) => $m->hasReachedDueDate())) {
                    return 'remarks_required';
                }

                $this->finalizeGroupApproval($locked, $actor);

                return 'approved';
            });
        }

        return response()->json([
            'state' => $state,
            'message' => self::EMAIL_APPROVAL_MESSAGES[$state],
        ]);
    }

    /**
     * Validates the emailed link itself (hash-verified against `$token`,
     * not expired — exactly `ForgotPasswordController`'s established
     * convention for an emailed, unauthenticated action link) and, if
     * valid, resolves the token's origin row's CURRENT full group — every
     * checklist this employee is still the assigned approver for on this
     * request, re-derived fresh rather than limited to whatever existed
     * when the email was sent. The token's own
     * `offboarding_request_approver_id` is the ONLY source of which
     * request/employee pair this resolves to — never a value the caller
     * could supply separately — so a valid link can only ever affect the
     * group it was generated for. Shared by both the GET confirmation page
     * and the POST that actually approves, so they always agree on the
     * current state.
     *
     * @return array{0: string, 1: \Illuminate\Support\Collection<int, OffboardingRequestApprover>}
     */
    private function resolveEmailApprovalState(int $id, string $token): array
    {
        $approvalToken = ChecklistApprovalToken::find($id);

        if (! $approvalToken || ! $approvalToken->isValid() || ! Hash::check($token, $approvalToken->token)) {
            return ['invalid', collect()];
        }

        $originRow = $approvalToken->assignment;

        if (! $originRow) {
            return ['invalid', collect()];
        }

        $members = OffboardingRequestApprover::where('offboarding_request_id', $originRow->offboarding_request_id)
            ->where('employee_id', $originRow->employee_id)
            ->with(['checklistTemplate.items', 'itemProgress', 'offboardingRequest.employee', 'employee'])
            ->get();

        if ($members->isEmpty()) {
            return ['invalid', collect()];
        }

        if ($members->every(fn (OffboardingRequestApprover $m) => $m->status === 'approved')) {
            return ['already_approved', $members];
        }

        $outstanding = $members->reject(fn (OffboardingRequestApprover $m) => $m->status === 'approved');

        if ($outstanding->contains(fn (OffboardingRequestApprover $m) => ! in_array($m->status, ['pending', 'viewed'], true))) {
            return ['not_actionable', $members];
        }

        $notReady = $outstanding->contains(
            fn (OffboardingRequestApprover $m) => $m->requiresAllItemsCompletedBeforeApproval() && ! $m->allItemsCompleted()
        );

        if ($notReady) {
            return ['not_ready', $members];
        }

        // Same e-signature gate `approve()`/`approveGroup()` enforce for the
        // in-app flow, applied here too so the emailed link can never
        // finalize an approval without one either — shared by BOTH this GET
        // confirmation state and the actual POST in `confirmEmailApproval()`
        // (which calls this same method), so there is exactly one place
        // that decides this, never two definitions that could drift apart.
        // Looked up by username rather than `User::findOrCreateApprover()`
        // deliberately: this runs on every page view of the link (including
        // an unauthenticated GET), and an account that doesn't exist yet
        // can't possibly have a signature uploaded to it, so there's no
        // need to actually create one just to answer that question.
        $departmentHead = $members->first()->employee;
        $hasSignature = $departmentHead
            && (User::firstWhere('username', $departmentHead->employee_code_digits)?->hasUsableSignature() ?? false);

        if (! $hasSignature) {
            return ['no_signature', $members];
        }

        return ['confirm', $members];
    }

    /**
     * Marks a single checklist row approved — status, timestamp, the
     * optional whole-checklist delay remark, and overdue-notification
     * cleanup only. The completion cascade and activity/notification are
     * deliberately NOT here — see `finalizeGroupApproval()`, which calls
     * this once per member of a group and only runs those once, for the
     * group as a whole.
     *
     * `$remarks` is stored on EVERY member of a combined-card group approval
     * (see `finalizeGroupApproval()`) — a single remark the approver left
     * covering the one action that approved them all, same as the single
     * "Approved checklists: ..." activity already recorded for the group.
     */
    private function markApproved(OffboardingRequestApprover $offboardingRequestApprover, ?string $remarks = null): void
    {
        $offboardingRequestApprover->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approval_remarks' => $remarks,
        ]);

        $this->resolveOverdueNotifications($offboardingRequestApprover);
    }

    /**
     * The actual state change behind approving a checklist — or, now, an
     * entire combined group of them in one action: every member is marked
     * approved, ONE activity/admin-notification is recorded for the whole
     * group (not one per checklist), the regular/final-pay completion
     * cascade runs once per relevant kind, and the request's creator gets
     * ONE confirmation email covering every checklist just approved.
     * Shared by the authenticated `approve()`/`approveGroup()` actions and
     * the emailed `confirmEmailApproval()` link so all three produce the
     * exact same result; the only difference between them is how each
     * establishes WHO is approving (`auth()->user()` vs. the emailed
     * link's own identified Department Head) — that's resolved by the
     * caller and passed in here as `$actor`, never read from `auth()`
     * directly, so this method behaves identically regardless of whether
     * there's an active session at all. A single-row `approve()` call is
     * simply a group of one — no special-casing needed here.
     *
     * @param  \Illuminate\Support\Collection<int, OffboardingRequestApprover>  $members
     */
    private function finalizeGroupApproval($members, ?User $actor, ?string $remarks = null): void
    {
        $members->each(fn (OffboardingRequestApprover $row) => $this->markApproved($row, $remarks));

        $offboardingRequest = $members->first()->offboardingRequest;
        $employeeId = $members->first()->employee_id;

        $checklistTitles = $members->pluck('checklistTemplate.title')->filter()->implode(', ');
        $this->recordActivityAndNotify($offboardingRequest, 'approved', 'Approved checklists: ' . $checklistTitles, $actor);

        $completionService = app(ChecklistCompletionService::class);

        if ($members->contains(fn (OffboardingRequestApprover $row) => ! $row->checklistTemplate->is_final_pay_checklist)) {
            $completionService->checkRegularChecklistsCompletion($offboardingRequest);
        }

        if ($members->contains(fn (OffboardingRequestApprover $row) => $row->checklistTemplate->is_final_pay_checklist)) {
            $completionService->checkFinalPayCompletion($offboardingRequest);
        }

        app(ChecklistApprovalNotifier::class)->notifyRequestCreatorOfGroupApproval($offboardingRequest, $employeeId, $members);
    }

    /**
     * Declining a checklist places it On Hold (`status = 'on_hold'`) — NOT
     * a completed signatory action. It never advances the offboarding
     * request (no `ChecklistCompletionService` cascade), records no
     * signature, and blocks every further task/approve/decline action on
     * this checklist until the SAME signatory calls `removeHold()` below.
     * No signature is required to decline — unlike `approve()`, declining
     * isn't a final signed-off decision, just a pause — but a reason is
     * still mandatory, both to place the hold and (separately) to remove
     * it later. The checklist stays fully visible on this signatory's own
     * Approvals page throughout (never removed), showing "On Hold" plus
     * the decline reason, exactly per spec.
     */
    public function decline(Request $request, OffboardingRequestApprover $offboardingRequestApprover): RedirectResponse|JsonResponse
    {
        $this->authorizeAssignment($offboardingRequestApprover);

        if ($offboardingRequestApprover->offboardingRequest->isReadOnly()) {
            return $request->wantsJson()
                ? response()->json(['message' => 'This offboarding request has been retracted and can no longer be actioned.'], 422)
                : abort(422, 'This offboarding request has been retracted and can no longer be actioned.');
        }

        abort_unless(
            in_array($offboardingRequestApprover->status, ['pending', 'viewed'], true),
            422,
            'This has already been actioned.'
        );

        if ($request->has('comment')) {
            $request->merge(['comment' => trim((string) $request->input('comment'))]);
        }

        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ], [
            'comment.required' => 'A reason is required to decline this checklist.',
        ]);

        $comment = $validated['comment'];
        $actor = auth()->user();

        $offboardingRequestApprover->update([
            'status' => 'on_hold',
            'declined_at' => now(),
            'decline_reason' => $comment,
        ]);

        // Per-row only — every OTHER still-outstanding assignment on this
        // request is entirely unaffected by this hold, so their own overdue
        // notices (if any) must stay exactly as they are.
        $this->resolveOverdueNotifications($offboardingRequestApprover);

        $offboardingRequest = $offboardingRequestApprover->offboardingRequest;

        // Deliberately NO `ChecklistCompletionService` call here — a hold
        // must never advance Secondary/Final-Pay attachment or overall
        // completion. The request only keeps moving once this checklist is
        // genuinely approved (see `approve()`), after the hold is removed.
        $this->recordActivityAndNotify($offboardingRequest, 'declined', $comment, $actor, $offboardingRequestApprover->id);

        $this->sendDeclineNotification($offboardingRequestApprover, $actor, $comment, 'Clearance Signatory');

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Checklist placed on hold.',
                'declinedAt' => $offboardingRequestApprover->declined_at->format('M d, Y g:i A'),
            ]);
        }

        return back()->with('success', 'Checklist for ' . $offboardingRequest->employee->name . ' was placed on hold.');
    }

    /**
     * The SAME signatory who declined a checklist resumes it — the only
     * way out of "On Hold" (see `decline()` above). Requires its own
     * mandatory reason, independent of the original decline reason (both
     * are preserved permanently as separate audit-trail entries — see
     * `on_hold_removed_at`/`on_hold_removal_reason`). Resets `status` to
     * `'pending'` rather than a dedicated "resumed" value: every existing
     * display (`clearanceStatusLabel()`, the Offboarding Status badge)
     * already correctly re-derives "In Progress" the moment any real
     * activity (a checked item, a prior view) exists on the row, exactly
     * matching the spec's "In Progress/Pending Approval, based on the
     * application's existing status terminology" wording with no new
     * status value needed. No completion-cascade call — reopening a
     * checklist for action is not itself a resolution.
     */
    public function removeHold(Request $request, OffboardingRequestApprover $offboardingRequestApprover): RedirectResponse|JsonResponse
    {
        $this->authorizeAssignment($offboardingRequestApprover);

        abort_unless(
            $offboardingRequestApprover->status === 'on_hold',
            422,
            'This checklist is not on hold.'
        );

        if ($request->has('reason')) {
            $request->merge(['reason' => trim((string) $request->input('reason'))]);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ], [
            'reason.required' => 'A reason is required to remove this hold.',
        ]);

        $reason = $validated['reason'];
        $actor = auth()->user();

        $offboardingRequestApprover->update([
            'status' => 'pending',
            'on_hold_removed_at' => now(),
            'on_hold_removed_by' => $actor->id,
            'on_hold_removal_reason' => $reason,
        ]);

        $offboardingRequest = $offboardingRequestApprover->offboardingRequest;

        $this->recordActivityAndNotify($offboardingRequest, 'on_hold_removed', $reason, $actor, $offboardingRequestApprover->id);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Hold removed. The checklist is available for review again.',
                'status' => $offboardingRequestApprover->status,
                'onHoldRemovedAt' => $offboardingRequestApprover->on_hold_removed_at->format('M d, Y g:i A'),
            ]);
        }

        return back()->with('success', 'Checklist for ' . $offboardingRequest->employee->name . ' is available for review again.');
    }

    /**
     * Emails the Admin/HR user who CREATED this offboarding request — and
     * only them, not a broader admin broadcast — that a Clearance Signatory
     * or General Signatory has declined (placed On Hold) a checklist, per
     * spec. Shared shape with
     * `GeneralSignatoryApprovalController::sendDeclineNotification()`
     * (kept as separate copies, same convention as this controller and that
     * one already being entirely independent elsewhere). Never lets a mail
     * failure look like the decline itself failed — the decline has already
     * committed by the time this runs.
     */
    private function sendDeclineNotification(OffboardingRequestApprover $offboardingRequestApprover, User $actor, string $declineReason, string $signatoryType): void
    {
        $offboardingRequest = $offboardingRequestApprover->offboardingRequest;
        $offboardee = $offboardingRequest->employee;
        $template = $offboardingRequestApprover->checklistTemplate;

        $creator = $offboardingRequest->creator;

        if (! $creator || ! $creator->email || ! filter_var($creator->email, FILTER_VALIDATE_EMAIL)) {
            Log::warning('Offboarding request creator has no usable email — checklist decline notification was not sent.', [
                'offboarding_request_approver_id' => $offboardingRequestApprover->id,
                'offboarding_request_id' => $offboardingRequest->id,
            ]);

            return;
        }

        $recipients = collect([$creator]);

        $emailTemplate = EmailTemplate::where('is_active', true)
            ->where('template_name', 'Checklist Signatory Declined')
            ->latest('updated_at')
            ->first();

        if (! $emailTemplate) {
            Log::warning('No "Checklist Signatory Declined" email template found — decline notifications were not sent.', [
                'offboarding_request_approver_id' => $offboardingRequestApprover->id,
            ]);

            return;
        }

        $declinedAt = $offboardingRequestApprover->declined_at->format('M d, Y g:i A');
        $checklistType = $template?->is_final_pay_checklist
            ? 'Final Pay'
            : ($template?->sequence_type === ChecklistTemplate::SEQUENCE_TYPE_SECONDARY ? 'Secondary' : 'Core/Primary');

        foreach ($recipients as $recipient) {
            try {
                [$subject, $body] = $emailTemplate->render(
                    approverName: $recipient->name,
                    offboardeeName: $offboardee->name,
                    employeeNumber: $offboardee->employee_code,
                    department: $offboardee->department,
                    position: $offboardee->designation,
                    separationDate: $offboardingRequest->last_working_day?->format('M d, Y'),
                    reason: $offboardingRequest->reason,
                    checklistName: $template?->title,
                    checklistType: $checklistType,
                    dueDate: $offboardingRequestApprover->due_at?->format('M d, Y'),
                    checklistStatus: $offboardingRequestApprover->clearanceStatusLabel(includeOverdue: false),
                    offboardingRequestId: (string) $offboardingRequest->id,
                    declinedBy: $actor->name,
                    declinedAt: $declinedAt,
                    declineReason: $declineReason,
                    signatoryType: $signatoryType,
                );

                Mail::to($recipient->email)->send(new ChecklistSignatoryAnnouncementMail($subject, $body));
            } catch (\Throwable $e) {
                Log::error('Failed to send checklist decline notification.', [
                    'offboarding_request_approver_id' => $offboardingRequestApprover->id,
                    'recipient' => $recipient->email,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * HR/Admin-only reminder for an approver who hasn't approved/declined
     * yet. Uses the existing email template mechanism (fixed-name lookup,
     * same convention as the initial announcement) rather than hard-coding
     * the message. Once the checklist has reached or passed its due date,
     * `OVERDUE_TEMPLATE` is used instead of `REMINDER_TEMPLATE`, with the
     * extra overdue-specific placeholders filled in — see
     * `OffboardingRequestApprover::daysOverdue()`/`itemsStatusTableHtml()`/
     * `clearanceStatusLabel()`. Authorized here explicitly (not just via
     * route middleware), and every reminder is logged as its own timeline
     * activity — who sent it and who it went to — not just the inline
     * `reminder_sent_at` timestamp on the assignment.
     */
    public function remind(Request $request, OffboardingRequestApprover $offboardingRequestApprover): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        abort_if(
            $offboardingRequestApprover->offboardingRequest->isReadOnly(),
            422,
            'This offboarding request has been retracted and can no longer be actioned.'
        );

        abort_if(
            $offboardingRequestApprover->status === 'approved',
            422,
            'This approver has already acted — no reminder needed.'
        );

        // "Use Task Assignee as Clearance Signatory": this checklist has no
        // single Clearance Signatory at all — `$offboardingRequestApprover->employee`
        // is always null for this kind, which previously crashed below
        // reading `->email` off it. Remind every still-pending Task
        // Assignee instead; see `remindTaskAssignees()`.
        if ($offboardingRequestApprover->checklistTemplate?->use_task_assignee_as_signatory) {
            return $this->remindTaskAssignees($request, $offboardingRequestApprover);
        }

        // Optional per-click override from the Offboarding Status/Timeline
        // "Select Email Template" picker — applies ONLY to this one send,
        // never persisted anywhere and never touching the global default.
        // Left unset (the normal case — the admin didn't open the picker,
        // or left it on the pre-selected default), this falls back to
        // today's exact original behavior: resolving by fixed name below.
        // Scoped to `is_active` templates only, same as every other
        // template lookup in this app — an inactive template was never a
        // pickable option in the UI to begin with.
        $validated = $request->validate([
            'email_template_id' => ['nullable', Rule::exists('email_templates', 'id')->where('is_active', true)],
        ]);

        $offboardingRequest = $offboardingRequestApprover->offboardingRequest;
        $offboardee = $offboardingRequest->employee;
        $approverEmployee = $offboardingRequestApprover->employee;
        $isOverdue = $offboardingRequestApprover->isOverdue();
        $templateName = $isOverdue ? self::OVERDUE_TEMPLATE : self::REMINDER_TEMPLATE;

        $emailTemplate = $this->resolveReminderEmailTemplate($validated['email_template_id'] ?? null, $templateName);

        if (! $emailTemplate) {
            return back()->with('error', 'No "' . $templateName . '" email template found. Please create one first.');
        }

        if (! $approverEmployee->email || ! filter_var($approverEmployee->email, FILTER_VALIDATE_EMAIL)) {
            return back()->with('error', 'This approver has no valid email address on file.');
        }

        $checklistNotifier = app(ChecklistApprovalNotifier::class);

        // `checklistSummary`/`approveButton` are always populated (not just
        // for the "Checklist Ready for Department Head Approval" template)
        // since an unreferenced token is simply never touched by
        // `render()` — this lets an admin pick that template from this same
        // "Notify Approver" picker (every active template is selectable
        // here, see the picker's own docblock) to manually follow up once a
        // checklist is ready, with a genuinely working one-click Approve
        // link, without needing a separate button/endpoint just for that
        // one template. Clicking Approve before every item is actually
        // checked is still safe — `confirmEmailApproval()` already handles
        // "not ready yet" gracefully instead of approving early.
        [$subject, $body] = $emailTemplate->render(
            approverName: $approverEmployee->name,
            offboardeeName: $offboardee->name,
            creatorName: auth()->user()->name,
            employeeNumber: $offboardee->employee_code,
            checklistName: $offboardingRequestApprover->checklistTemplate?->title,
            dueDate: $offboardingRequestApprover->due_at?->format('M d, Y'),
            department: $offboardee->department,
            position: $offboardee->designation,
            daysOverdue: $isOverdue ? (string) $offboardingRequestApprover->daysOverdue() : null,
            pendingItems: $isOverdue ? $offboardingRequestApprover->itemsStatusTableHtml() : null,
            checklistStatus: $isOverdue ? $offboardingRequestApprover->clearanceStatusLabel() : null,
            checklistSummary: $offboardingRequestApprover->checkedItemsSummaryHtml(),
            approveButton: $checklistNotifier->buildApproveButtonHtml(
                $checklistNotifier->createChecklistApprovalUrl($offboardingRequestApprover)
            ),
        );

        try {
            Mail::to($approverEmployee->email)->send(new ChecklistSignatoryAnnouncementMail($subject, $body));
            $offboardingRequestApprover->update(['reminder_sent_at' => now()]);

            $offboardingRequest->activities()->create([
                'user_id' => auth()->id(),
                'offboarding_request_approver_id' => $offboardingRequestApprover->id,
                'action' => 'reminder_sent',
                'status' => $offboardingRequest->status,
                'comment' => 'Sent to: ' . $approverEmployee->name,
            ]);

            return redirect()
                ->route('offboardees.index', ['offboardee' => $offboardingRequest->id])
                ->with('success', 'Reminder sent to ' . $approverEmployee->name . '.');
        } catch (\Throwable $e) {
            Log::error('Failed to send offboarding reminder email.', [
                'offboarding_request_approver_id' => $offboardingRequestApprover->id,
                'recipient' => $approverEmployee->email,
                'exception' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to send the reminder email.');
        }
    }

    /**
     * `remind()`'s equivalent for a "Use Task Assignee as Clearance
     * Signatory" checklist — there is no single Clearance Signatory row to
     * send one email to, so this sends one to EVERY Task Assignee who
     * hasn't finished their own item(s) yet
     * (`OffboardingRequestApprover::pendingTaskAssigneeEmployees()`), and
     * none at all to one who's already cleared their own work. If every
     * Task Assignee has already finished (nothing left to remind), no email
     * is sent and the admin sees why instead of a raw error. A partial
     * failure (e.g. one recipient has no usable email on file, or the mail
     * send itself throws) never blocks the others — `reminder_sent_at` and
     * the activity log only reflect who a reminder was actually sent to.
     */
    private function remindTaskAssignees(Request $request, OffboardingRequestApprover $offboardingRequestApprover): RedirectResponse
    {
        $recipients = $offboardingRequestApprover->pendingTaskAssigneeEmployees();

        if ($recipients->isEmpty()) {
            return back()->with('error', 'There are no pending assignees to remind — every Task Assignee on this checklist has already completed their own item(s).');
        }

        $validated = $request->validate([
            'email_template_id' => ['nullable', Rule::exists('email_templates', 'id')->where('is_active', true)],
        ]);

        $offboardingRequest = $offboardingRequestApprover->offboardingRequest;
        $offboardee = $offboardingRequest->employee;
        $isOverdue = $offboardingRequestApprover->isOverdue();
        $templateName = $isOverdue ? self::OVERDUE_TEMPLATE : self::REMINDER_TEMPLATE;

        $emailTemplate = $this->resolveReminderEmailTemplate($validated['email_template_id'] ?? null, $templateName);

        if (! $emailTemplate) {
            return back()->with('error', 'No "' . $templateName . '" email template found. Please create one first.');
        }

        $sentNames = [];

        foreach ($recipients as $approverEmployee) {
            if (! $approverEmployee->email || ! filter_var($approverEmployee->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            [$subject, $body] = $emailTemplate->render(
                approverName: $approverEmployee->name,
                offboardeeName: $offboardee->name,
                creatorName: auth()->user()->name,
                employeeNumber: $offboardee->employee_code,
                checklistName: $offboardingRequestApprover->checklistTemplate?->title,
                dueDate: $offboardingRequestApprover->due_at?->format('M d, Y'),
                department: $offboardee->department,
                position: $offboardee->designation,
                daysOverdue: $isOverdue ? (string) $offboardingRequestApprover->daysOverdue() : null,
                pendingItems: $isOverdue ? $offboardingRequestApprover->itemsStatusTableHtml() : null,
                checklistStatus: $isOverdue ? $offboardingRequestApprover->clearanceStatusLabel() : null,
            );

            try {
                Mail::to($approverEmployee->email)->send(new ChecklistSignatoryAnnouncementMail($subject, $body));
                $sentNames[] = $approverEmployee->name;
            } catch (\Throwable $e) {
                Log::error('Failed to send offboarding reminder email.', [
                    'offboarding_request_approver_id' => $offboardingRequestApprover->id,
                    'recipient' => $approverEmployee->email,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        if (empty($sentNames)) {
            return back()->with('error', 'Failed to send the reminder email — no pending assignee has a valid email address on file.');
        }

        $offboardingRequestApprover->update(['reminder_sent_at' => now()]);

        $offboardingRequest->activities()->create([
            'user_id' => auth()->id(),
            'offboarding_request_approver_id' => $offboardingRequestApprover->id,
            'action' => 'reminder_sent',
            'status' => $offboardingRequest->status,
            'comment' => 'Sent to: ' . implode(', ', $sentNames),
        ]);

        return redirect()
            ->route('offboardees.index', ['offboardee' => $offboardingRequest->id])
            ->with('success', 'Reminder sent to ' . implode(', ', $sentNames) . '.');
    }

    /**
     * "Extend Due" — the Offboardee Page's single bulk button (there is no
     * longer a per-checklist "Extend Due" anywhere on the Offboarding
     * Status/Timeline tabs — see `OffboardingRequestApprover::canExtendDue()`/
     * `extendDueTo()`, which writes the permanent audit row). Extends the
     * OFFBOARDEE'S OWN Last Working Day to an admin-picked date, THEN
     * recalculates every still-applicable checklist's due date from that
     * new Last Working Day using ITS OWN template's `due_in_days` offset —
     * never a single shared date applied uniformly. A checklist configured
     * for 0 extra days lands exactly on the new Last Working Day; one
     * configured for 5 lands 5 days after it — each checklist keeps using
     * its own configured offset, exactly like at creation time (see
     * `ChecklistApprovalNotifier::attachAndNotify()`), just recomputed from
     * the NEW Last Working Day instead of the original one.
     *
     * Visible/reachable any time AT LEAST ONE applicable checklist exists
     * (not yet approved/declined, and actually carrying a due date) — see
     * the `abort_unless` below, which mirrors `OffboardeeController::index()`'s
     * own `canBulkExtendDue` gate exactly. No checklist needs to have
     * actually REACHED its due date first; the HR/Admin team can extend the
     * Last Working Day proactively, at any time, per spec.
     *
     * A checklist template with no `due_in_days` configured (a normal,
     * common config in this app — several fixed-name templates ship with
     * none) never gets a `due_at` at all, so it can never "reach" a due
     * date it doesn't have — it's excluded from the applicable set
     * entirely rather than counting toward or blocking this gate. Its own
     * effective offset for the recalculation below is simply 0 days (i.e.
     * it lands exactly on the new Last Working Day) via `?? 0`.
     *
     * The picked date must be later than the CURRENT Last Working Day —
     * moving it backward would be a genuine data correction, not an
     * "extension", and isn't what this action is for.
     *
     * A reason is REQUIRED (trimmed, rejecting whitespace-only same as the
     * overdue-checklist-approval remark elsewhere in this controller) —
     * this is a whole-request-level extension the HR/Admin team needs to
     * be able to explain later, not a per-checklist technicality. Stored
     * in TWO places for full traceability: the `last_working_day_extended`
     * Timeline activity's own `comment` (the one entry per click), and
     * each individual checklist's own `ChecklistDueDateExtension.reason`
     * column (already existed for this — just never populated from here
     * before).
     *
     * Wrapped in a single transaction: either the Last Working Day AND
     * every applicable checklist's recalculated due date (each with its
     * own audit-trail `ChecklistDueDateExtension` row and Timeline
     * activity) all get set, or — on any failure — none of it does, per
     * spec ("do not partially update the checklists"). The per-recipient
     * notification emails fire only after that transaction commits, same
     * non-fatal best-effort handling as before.
     *
     * Returns the fresh Last Working Day / button-visibility / checklist
     * breakdown in its JSON response (mirroring
     * `OffboardeeController::index()`'s own `lastWorkingDay`/
     * `canBulkExtendDue`/`extendDueChecklists`/`extendDueMinSelectableDateIso`
     * fields exactly) so the Offboardee Page can update that one card's
     * Alpine state in place and re-open the modal with correct data next
     * time, instead of reloading the whole page.
     */
    public function extendAllDue(Request $request, OffboardingRequest $offboardingRequest): RedirectResponse|JsonResponse
    {
        abort_unless(auth()->user()->can('checklists.extend-due'), 403);

        $offboardingRequest->loadMissing('approvers.checklistTemplate', 'generalSignatoryApprovals.generalSignatory');

        $applicableApprovers = $offboardingRequest->extendDueApplicableApprovers();
        // Recalculated alongside checklists below — a Clearance Signing Due
        // Date isn't itself required for the button to be available (that
        // gate stays checklist-only, unchanged), but once ANY checklist is
        // being extended, every applicable General Signatory's OWN deadline
        // is kept in step with the SAME new Last Working Day too.
        $applicableGeneralSignatories = $offboardingRequest->extendDueApplicableGeneralSignatories();

        // The HR/Admin can extend the Last Working Day at any time — no
        // checklist needs to have actually REACHED its due date first (that
        // requirement was removed; `canExtendDue()` stays in use, unchanged,
        // purely for the informational "Due"/"Not Due" badge below). Still
        // requires at least one applicable checklist to exist at all —
        // otherwise there is genuinely nothing left to recalculate a due
        // date for.
        abort_unless(
            $applicableApprovers->isNotEmpty(),
            422,
            'No checklist on this offboarding request is eligible for a due date extension.'
        );

        $currentLastWorkingDay = $offboardingRequest->last_working_day;

        if ($request->has('reason')) {
            $request->merge(['reason' => trim((string) $request->input('reason'))]);
        }

        $validated = $request->validate([
            'new_last_working_day' => ['required', 'date', 'after:' . $currentLastWorkingDay->toDateString()],
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'new_last_working_day.after' => 'The new Last Working Day must be later than the current Last Working Day (' . $currentLastWorkingDay->format('M d, Y') . ').',
            'reason.required' => 'A reason is required to extend the Last Working Day.',
        ]);

        $newLastWorkingDay = Carbon::parse($validated['new_last_working_day'])->startOfDay();
        $reason = $validated['reason'];

        $extensions = DB::transaction(function () use ($applicableApprovers, $applicableGeneralSignatories, $newLastWorkingDay, $currentLastWorkingDay, $offboardingRequest, $reason) {
            $offboardingRequest->update(['last_working_day' => $newLastWorkingDay]);

            // Clearance Signing Due Date — recalculated for every
            // applicable General Signatory here, alongside the checklists'
            // own `due_at` below, both from this SAME new Last Working Day.
            // No separate audit-trail row for this (General Signatories
            // have no existing due-date/extension infrastructure to extend,
            // and this is purely additive display data, not itself a
            // gate) — the fresh value is simply reflected below in the
            // response the Offboardee card patches into place.
            $applicableGeneralSignatories->each(
                fn (OffboardingRequestGeneralSignatory $assignment) => $assignment->recalculateClearanceSigningDueDate($newLastWorkingDay)
            );

            $offboardingRequest->activities()->create([
                'user_id' => auth()->id(),
                'action' => 'last_working_day_extended',
                'status' => $offboardingRequest->status,
                'comment' => sprintf(
                    'Last Working Day extended from %s to %s. Reason: %s',
                    $currentLastWorkingDay->format('M d, Y'),
                    $newLastWorkingDay->format('M d, Y'),
                    $reason,
                ),
            ]);

            return $applicableApprovers->map(function (OffboardingRequestApprover $approver) use ($newLastWorkingDay, $offboardingRequest, $reason) {
                $previousDueDate = $approver->due_at;
                // ->endOfDay() (23:59:59) — same "due until the END of the
                // day, not the start of it" reasoning as the initial
                // attachment in ChecklistApprovalNotifier::attachAndNotify().
                $newDueDate = $newLastWorkingDay->copy()->addDays($approver->checklistTemplate?->due_in_days ?? 0)->endOfDay();
                $extension = $approver->extendDueTo($newDueDate, auth()->id(), $reason);
                // Clearance Signing Due Date — recalculated alongside
                // `due_at` above from this SAME new Last Working Day, but
                // independently (its own `clearance_signing_deadline_days`,
                // no shared audit row).
                $approver->recalculateClearanceSigningDueDate($newLastWorkingDay);

                $this->resolveOverdueNotifications($approver);

                $days = $previousDueDate->diffInDays($extension->new_due_date);

                $offboardingRequest->activities()->create([
                    'user_id' => auth()->id(),
                    'offboarding_request_approver_id' => $approver->id,
                    'checklist_due_date_extension_id' => $extension->id,
                    'action' => 'due_date_extended',
                    'status' => $offboardingRequest->status,
                    'comment' => sprintf(
                        'Due date extended from %s to %s (+%d day%s). Reason: %s',
                        $previousDueDate->format('M d, Y'),
                        $extension->new_due_date->format('M d, Y'),
                        $days,
                        $days === 1 ? '' : 's',
                        $reason,
                    ),
                ]);

                return [$approver, $previousDueDate, $extension];
            });
        });

        // Shared across every checklist's own notification call below — see
        // `notifyClearanceSignatoriesOfExtension()`'s own docblock for why:
        // the same person responsible for more than one extended checklist
        // must still only receive ONE email for this whole bulk action.
        $alreadyNotifiedEmployeeIds = [];

        // Fetched once here, not once per checklist inside the loop below —
        // the same "Checklist Due Date Extended" template applies to every
        // extension in this one bulk action regardless of which checklist
        // triggered it, so re-querying it per checklist was a pure,
        // redundant repeat of an identical lookup.
        $extensionEmailTemplate = EmailTemplate::where('is_active', true)
            ->where('template_name', 'Checklist Due Date Extended')
            ->latest('updated_at')
            ->first();

        foreach ($extensions as [$approver, $previousDueDate, $extension]) {
            try {
                $this->notifyClearanceSignatoriesOfExtension($approver, $previousDueDate, $extension, $alreadyNotifiedEmployeeIds, $extensionEmailTemplate);
            } catch (\Throwable $e) {
                Log::error('Failed to send checklist due date extension notification.', [
                    'offboarding_request_approver_id' => $approver->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $message = 'Last Working Day extended to ' . $newLastWorkingDay->format('M d, Y') . ' — recalculated due dates for ' . $extensions->count() . ' checklist' . ($extensions->count() === 1 ? '' : 's') . '.';

        if ($request->wantsJson()) {
            // No reload needed: `$applicableApprovers`' own model instances
            // ARE `$offboardingRequest->approvers`'s cached ones (the
            // `->reject()->filter()` above returns a new Collection of the
            // SAME object references) — `extendDueTo()` already mutated
            // their `due_at` in place, so re-deriving from the relation
            // again here already reflects the fresh values.
            $freshApplicable = $offboardingRequest->extendDueApplicableApprovers();

            return response()->json([
                'success' => true,
                'message' => $message,
                'lastWorkingDay' => $newLastWorkingDay->format('M d, Y'),
                // Offboardee Status modal's "Extended Last Working Day"
                // display (see `status-timeline-modal.blade.php`) — sent
                // here so the card's dispatch to that modal reflects the
                // extension immediately, with no page reload.
                'originalLastWorkingDay' => $offboardingRequest->original_last_working_day?->format('M d, Y'),
                'isLastWorkingDayExtended' => $offboardingRequest->isLastWorkingDayExtended(),
                // The Offboardee card's own status badge (Pending/In
                // Progress/Overdue/Completed) — an extension can genuinely
                // change this (e.g. a checklist that was Overdue no longer
                // is, now that its due date has moved forward), so it must
                // be refreshed here too rather than left stale until the
                // next full page load. Same `displayStatus()` call
                // `OffboardeeController::index()` itself uses.
                'status' => $offboardingRequest->displayStatus(),
                // "Retract Offboarding" on the Offboardee page — an
                // extension can push the Last Working Day back into the
                // future (reopening this) just as easily as it can leave it
                // unchanged; re-derived fresh here so the card's button
                // reacts immediately, same convention as every other field
                // in this response. See `OffboardingRequest::isBeforeLastWorkingDay()`.
                'canRetractOffboarding' => $offboardingRequest->isBeforeLastWorkingDay(),
                'canBulkExtendDue' => $freshApplicable->isNotEmpty(),
                'extendDueChecklists' => $freshApplicable->map(fn (OffboardingRequestApprover $approver) => [
                    'title' => $approver->checklistTemplate?->title,
                    'dueDate' => $approver->due_at?->format('M d, Y'),
                    'hasReachedDueDate' => $approver->canExtendDue(),
                ])->values()->all(),
                'extendDueMinSelectableDateIso' => $newLastWorkingDay->copy()->addDay()->format('Y-m-d'),
                // The Offboarding Status/Timeline modal's own tab content —
                // per-checklist due dates, the "Due Date Extended"/"Last
                // Working Day Extended" activity entries this action just
                // created, everything — is driven entirely by this one
                // field (see `OffboardeeController::index()`'s identical
                // `'timeline' => ...->approverActivityTimeline()` and
                // `status-timeline-modal.blade.php`'s `richSteps()`), never
                // by the handful of top-level fields above. Without sending
                // a freshly re-derived copy here too, the offboardee card's
                // own reactive `timeline` would stay exactly as it was at
                // the page's original load — correct for the pinned
                // header's Last Working Day (updated above), but stale for
                // every checklist's own due date and missing this
                // extension's own new activity entries entirely, until the
                // next full page reload.
                'timeline' => $offboardingRequest->approverActivityTimeline(),
            ]);
        }

        return redirect()
            ->route('offboardees.index', ['offboardee' => $offboardingRequest->id])
            ->with('success', $message);
    }

    /**
     * Emails every Clearance Signatory actually responsible for this
     * checklist — the assigned employee for a normal, single-owner
     * checklist, or every Task Assignee still holding at least one
     * unchecked item on a "Use Task Assignee as Clearance Signatory"
     * checklist (`pendingTaskAssigneeEmployees()`, the same recipient set
     * `remindTaskAssignees()` above already uses for the equivalent
     * "Notify Approver" case) — informing them the due date moved and they
     * must finish before the new one. Silently does nothing if the
     * "Checklist Due Date Extended" template ($emailTemplate — looked up
     * ONCE by the caller for the whole bulk action, not re-queried per
     * checklist here) is missing/inactive/null, or a recipient has no
     * usable email, exactly like `remind()`'s own graceful degradation,
     * since this is a secondary notice about an already-successful
     * extension, never a reason to fail the request.
     *
     * A checklist already `approved`/`declined` never reaches this method
     * at all — `extendAllDue()`'s own `$applicableApprovers` excludes it
     * from extension entirely — so a fully completed checklist's Clearance
     * Signatory is never notified about ITS due date, per spec. What this
     * method alone can't guarantee is the OTHER half of that same spec:
     * the SAME person responsible for more than one checklist being
     * extended in this one bulk action must still only get ONE email, not
     * one per checklist. `$alreadyNotifiedEmployeeIds` is that guard — a
     * by-reference set shared across every call in `extendAllDue()`'s own
     * loop, checked before sending and appended to after, so whichever
     * checklist a shared recipient happens to be resolved from FIRST is
     * the one email they actually receive.
     */
    private function notifyClearanceSignatoriesOfExtension(
        OffboardingRequestApprover $offboardingRequestApprover,
        Carbon $previousDueDate,
        ChecklistDueDateExtension $extension,
        array &$alreadyNotifiedEmployeeIds,
        ?EmailTemplate $emailTemplate,
    ): void {
        if (! $emailTemplate) {
            return;
        }

        $recipients = $offboardingRequestApprover->checklistTemplate?->use_task_assignee_as_signatory
            ? $offboardingRequestApprover->pendingTaskAssigneeEmployees()
            : collect([$offboardingRequestApprover->employee])->filter();

        $recipients = $recipients->reject(
            fn (Employee $recipient) => in_array($recipient->id, $alreadyNotifiedEmployeeIds, true)
        );

        if ($recipients->isEmpty()) {
            return;
        }

        $offboardingRequest = $offboardingRequestApprover->offboardingRequest;
        $offboardee = $offboardingRequest->employee;

        foreach ($recipients as $recipient) {
            if (! $recipient->email || ! filter_var($recipient->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            [$subject, $body] = $emailTemplate->render(
                approverName: $recipient->name,
                offboardeeName: $offboardee->name,
                employeeNumber: $offboardee->employee_code,
                checklistName: $offboardingRequestApprover->checklistTemplate?->title,
                originalDueDate: $previousDueDate->format('M d, Y'),
                extensionDays: (string) $extension->additional_extension_days,
                extendedDueDate: $extension->new_due_date->format('M d, Y'),
                clearanceSignatoryName: $recipient->name,
            );

            Mail::to($recipient->email)->send(new ChecklistSignatoryAnnouncementMail($subject, $body));

            $alreadyNotifiedEmployeeIds[] = $recipient->id;
        }
    }

    /**
     * Resolves which email template a reminder send uses — an explicit
     * per-click override from the Offboarding Status/Timeline "Select Email
     * Template" picker when one was chosen, otherwise the fixed-name
     * default (reminder vs. overdue). Scoped to `is_active` templates only,
     * same as every other template lookup in this app. Shared verbatim by
     * `remind()` and `remindTaskAssignees()` so both resolve identically.
     */
    private function resolveReminderEmailTemplate(?int $overrideTemplateId, string $templateName): ?EmailTemplate
    {
        return $overrideTemplateId
            ? EmailTemplate::find($overrideTemplateId)
            : EmailTemplate::where('is_active', true)
                ->where('template_name', $templateName)
                ->latest('updated_at')
                ->first();
    }

    /**
     * Marks any still-unread "checklist overdue" notifications tied to this
     * assignment as read, now that it's been submitted/approved — they stop
     * counting toward the bell's unread badge and stop showing as active.
     * A no-op when the assignment was never actually overdue (or the
     * notifications were already read), so this is always safe to call
     * unconditionally from `approve()`.
     */
    private function resolveOverdueNotifications(OffboardingRequestApprover $offboardingRequestApprover): void
    {
        DatabaseNotification::where('type', 'checklist_overdue')
            ->where('data->offboarding_request_approver_id', $offboardingRequestApprover->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Same as `resolveOverdueNotifications()`, but for every assignment on
     * the given request — used on decline, since cancelling the whole
     * request makes every other still-outstanding assignment's overdue
     * notice moot too, not just the one that was declined.
     */
    private function resolveOverdueNotificationsForRequest(OffboardingRequest $offboardingRequest): void
    {
        $assignmentIds = $offboardingRequest->approvers()->pluck('id');

        if ($assignmentIds->isEmpty()) {
            return;
        }

        DatabaseNotification::where('type', 'checklist_overdue')
            ->whereIn('data->offboarding_request_approver_id', $assignmentIds)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Blocks approve/decline on an assignment the user isn't the assigned
     * approver for, even if they reach it by guessing/typing the URL.
     */
    private function authorizeAssignment(OffboardingRequestApprover $offboardingRequestApprover): void
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return;
        }

        abort_unless(
            $offboardingRequestApprover->employee_id === $user->employee?->id,
            403
        );
    }

    /**
     * Blocks the group approve/save-progress/assign endpoints for anyone
     * who isn't literally this group's primary approver (or admin) — a
     * delegate or item-signatory never gets Submit authority, matching
     * `authorizeAssignment()`'s single-row rule exactly.
     */
    private function authorizeGroupPrimary(Employee $employee): void
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return;
        }

        abort_unless($employee->id === $user->employee?->id, 403);
    }

    /**
     * Records who approved/declined the request (and why, for declines) and
     * notifies every admin. Never allowed to affect the already-saved
     * approve/decline outcome if something here fails.
     *
     * `$actor` defaults to the current session's user — `approve()` and
     * `decline()` both run under an authenticated session, so they never
     * need to pass it explicitly. `approveViaEmail()` is the one caller
     * with no session at all; it resolves and passes the Department Head's
     * own account explicitly instead, so the activity log/notification
     * still correctly attribute to a real person rather than "Unknown".
     */
    private function recordActivityAndNotify(OffboardingRequest $offboardingRequest, string $action, ?string $comment, ?User $actor = null, ?int $offboardingRequestApproverId = null): void
    {
        $actor ??= auth()->user();

        try {
            $offboardingRequest->activities()->create([
                'user_id' => $actor?->id,
                'offboarding_request_approver_id' => $offboardingRequestApproverId,
                'action' => $action,
                'status' => $offboardingRequest->status,
                'comment' => $comment,
            ]);

            if ($actor) {
                Notification::send(
                    User::role(User::ROLE_ADMIN)->get(),
                    new OffboardingApprovalUpdated($offboardingRequest, $actor, $action, $comment)
                );
            }
        } catch (\Throwable $e) {
            Log::error('Failed to record offboarding activity/notification.', [
                'offboarding_request_id' => $offboardingRequest->id,
                'action' => $action,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
