<?php

namespace App\Http\Controllers;

use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\ChecklistApprovalToken;
use App\Models\ChecklistItemProgress;
use App\Models\ChecklistTemplate;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use App\Models\OffboardingRequestGeneralSignatory;
use App\Models\User;
use App\Notifications\OffboardingApprovalUpdated;
use App\Services\ChecklistApprovalNotifier;
use App\Services\ChecklistCompletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
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

    public function index(): View
    {
        $reasonLabels = [
            'resignation' => 'Resignation',
            'termination' => 'Termination',
            'retirement' => 'Retirement',
            'layoff' => 'Layoff',
            'other' => 'Other',
        ];

        $user = auth()->user();
        $employee = $user->employee;

        $assignments = OffboardingRequestApprover::query()
            ->whereIn('status', ['pending', 'viewed'])
            ->whereHas('offboardingRequest', fn ($q) => $q->where('status', 'pending'))
            ->visibleTo($user)
            ->with(['offboardingRequest.employee', 'checklistTemplate.items.signatory', 'employee', 'delegatedEmployee', 'itemProgress.checkedBy.employee', 'itemProgress.heldBy.employee', 'itemAssignments.assignedEmployee'])
            ->get()
            ->filter(fn (OffboardingRequestApprover $assignment) => $assignment->offboardingRequest?->employee);

        // Loading your own queue counts as "viewing" whatever's still pending in
        // it — but only for the primary approver. A delegate merely opening
        // their queue must never flip the primary assignment's status.
        if (! $user->isAdmin() && $employee) {
            $assignments->each(function (OffboardingRequestApprover $assignment) use ($employee) {
                if ($assignment->employee_id === $employee->id && ! $assignment->first_viewed_at) {
                    $assignment->update(['first_viewed_at' => now(), 'status' => 'viewed']);
                }
            });
        }

        $rows = $assignments
            ->map(function (OffboardingRequestApprover $assignment) use ($reasonLabels, $user, $employee) {
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
                $rowAssignableEmployees = $this->eligibleAssigneesFor($template);
                // Whether the list above is a REAL group restriction, as
                // opposed to the "no group configured" unrestricted
                // fallback — consulted by `groupIntoCombinedApprovals()` so
                // a combined card that merges a real, grouped checklist
                // with a groupless one (e.g. the same person is both the
                // Information Services Clearance Signatory AND this
                // request's Immediate Head) restricts the whole card's
                // delegate picker to the real group, instead of the
                // groupless checklist's unrestricted fallback silently
                // widening it back out to every active employee.
                $rowHasGroupRestriction = $template && $template->employee_group_id !== null;

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
                    'isReadyForApproval' => $isReadyForApproval,
                    'delegationStatusRaw' => $assignment->delegation_status,
                    'dueAtRaw' => $assignment->due_at,

                    'name' => $request->employee->name,
                    'employeeCode' => $request->employee->employee_code,
                    'department' => $request->employee->department,
                    'designation' => $request->employee->designation,
                    'status' => $request->status,
                    'reason' => $reasonLabels[$request->reason] ?? ucfirst($request->reason),
                    'resignationType' => $request->resignation_type,
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
                        ? $template->items->map(function ($item) use ($assignment, $template, $progressByItemId, $isPrimaryApprover, $isDelegate, $employee, $usesPerItemApprovers, $mustBeCheckedForSubmit, $rowAssignableEmployees) {
                            $progress = $progressByItemId->get($item->id);
                            $isChecked = (bool) ($progress?->is_checked ?? false);
                            $onHold = $progress?->status === 'hold' && ! $isChecked;
                            // The Department Head's live reassignment, if any,
                            // takes precedence over the template's own static
                            // signatory — items with neither fall back to the
                            // primary approver, same as before per-item
                            // approvers existed.
                            $effectiveSignatory = $assignment->effectiveSignatoryFor($item);
                            $isReassigned = $effectiveSignatory?->id !== $item->signatory_id;
                            $isOwnItem = $employee && $effectiveSignatory && $effectiveSignatory->id === $employee->id;
                            $editable = $isPrimaryApprover || $isDelegate || $isOwnItem;
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
                                // Set only when this item has actually been
                                // reassigned away from the template's own
                                // signatory — lets the UI show "was assigned
                                // to X" alongside the current assignee.
                                'originalApproverName' => $isReassigned ? $item->signatory?->name : null,
                                'originalApproverCode' => $isReassigned ? $item->signatory?->employee_code : null,
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
                                'canTakeOver' => ! $editable && ! $isChecked && ! $onHold,
                                'clearedByName' => $isChecked ? $progress?->checkedBy?->name : null,
                                'clearedByCode' => $isChecked ? $checkedByEmployee?->employee_code : null,
                                'clearedAt' => $isChecked ? $progress?->checked_at?->format('M d, Y g:i A') : null,
                                'onHold' => $onHold,
                                'heldByName' => $onHold ? $progress?->heldBy?->name : null,
                                'heldByCode' => $onHold ? $heldByEmployee?->employee_code : null,
                                'heldAt' => $onHold ? $progress?->held_at?->format('M d, Y g:i A') : null,
                                'holdUrl' => route('approvals.items.hold', [$assignment->id, $item->id]),
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
                            ];
                        })->values()->all()
                        : [],
                    'isPrimaryApprover' => $isPrimaryApprover,
                    'isAssignedApprover' => $isAssignedApprover,
                    'isDelegate' => $isDelegate,
                    'usesPerItemApprovers' => $usesPerItemApprovers,
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
            ->concat($this->buildGeneralSignatoryApprovals($user, $reasonLabels));

        return view('pages.approvals.index', [
            'title' => 'Approvals',
            'approvals' => $approvals,
            'combineChecklists' => $combineChecklists,
            'employees' => Employee::where('status', 'active')->orderBy('name')->get(['id', 'name', 'employee_code', 'department']),
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
     * @param  array<string, string>  $reasonLabels
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function buildGeneralSignatoryApprovals(User $user, array $reasonLabels)
    {
        return OffboardingRequestGeneralSignatory::query()
            ->where('status', 'pending')
            ->whereHas('offboardingRequest', fn ($q) => $q->where('status', 'pending'))
            ->visibleTo($user)
            ->with(['offboardingRequest.employee', 'generalSignatory.clearanceSignatory', 'generalSignatory.tasks.signatory'])
            ->get()
            ->filter(fn (OffboardingRequestGeneralSignatory $assignment) => $assignment->offboardingRequest?->employee)
            ->map(function (OffboardingRequestGeneralSignatory $assignment) use ($reasonLabels) {
                $request = $assignment->offboardingRequest;
                $generalSignatory = $assignment->generalSignatory;

                return [
                    'id' => 'general-signatory-' . $assignment->id,
                    'kind' => 'general_signatory',
                    'offboardingRequestId' => $request->id,
                    'name' => $request->employee->name,
                    'employeeCode' => $request->employee->employee_code,
                    'department' => $request->employee->department,
                    'designation' => $request->employee->designation,
                    'status' => $request->status,
                    'displayStatus' => 'pending',
                    'reason' => $reasonLabels[$request->reason] ?? ucfirst($request->reason),
                    'resignationType' => $request->resignation_type,
                    'noticeDate' => $request->notice_date?->format('M d, Y'),
                    'lastWorkingDay' => $request->last_working_day->format('M d, Y'),
                    'approvalMode' => $request->approval_mode === 'sync' ? 'Sync' : 'Async',
                    'checklistTemplates' => [],
                    'checklistItems' => [],
                    'isPrimaryApprover' => true,
                    'isAssignedApprover' => true,
                    'isDelegate' => false,
                    'usesPerItemApprovers' => false,
                    'isImmediateHeadChecklist' => false,
                    'allItemsCompleted' => true,
                    'dueAt' => null,
                    'isOverdue' => false,
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
                    'employeeCode' => $first['employeeCode'],
                    'department' => $first['department'],
                    'designation' => $first['designation'],
                    'status' => $first['status'],
                    'displayStatus' => $this->aggregateDisplayStatus($group),
                    'reason' => $first['reason'],
                    'resignationType' => $first['resignationType'],
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
                    'isDelegate' => $group->contains('isDelegate', true),
                    'usesPerItemApprovers' => $group->contains('usesPerItemApprovers', true),
                    'isImmediateHeadChecklist' => $group->contains('isImmediateHeadChecklist', true),
                    'allItemsCompleted' => $group->every(fn (array $row) => $row['allItemsCompleted']),
                    'dueAt' => $earliestDueAt?->format('M d, Y'),
                    'isOverdue' => $group->contains('isOverdue', true),
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
     * Falls back to every active employee (today's original, unrestricted
     * behavior) when this checklist has no group at all — an Immediate
     * Head checklist, or an older checklist with no Clearance Signatory
     * group configured — since there's no "employees under that Clearance
     * Signatory" set to filter to in that case.
     *
     * @return array<int, array{id: string, name: string, code: string, department: ?string}>
     */
    private function eligibleAssigneesFor(?ChecklistTemplate $template): array
    {
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
     * overdue, then approved/declined, then ready-for-approval, then
     * delegation status — generalized across every checklist in the group:
     * overdue if ANY member still outstanding is overdue; approved only
     * once EVERY member is approved; declined if ANY member was declined;
     * ready-for-approval once every not-yet-approved member has satisfied
     * its own completion gate; otherwise falls back to the first member's
     * delegation status (identical across members whenever only one is
     * delegated, which is the common case) or "pending".
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

        abort_unless(
            in_array($offboardingRequestApprover->status, ['pending', 'viewed'], true),
            422,
            'This has already been actioned.'
        );

        $validated = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.checklist_item_id' => ['required', 'exists:checklist_items,id'],
            'items.*.is_checked' => ['nullable', 'boolean'],
            'items.*.remark' => ['nullable', 'string'],
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

        $this->finalizeGroupApproval(collect([$offboardingRequestApprover]), auth()->user());

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

        $validated = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.checklist_item_id' => ['required', 'exists:checklist_items,id'],
            'items.*.is_checked' => ['nullable', 'boolean'],
            'items.*.remark' => ['nullable', 'string'],
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

            $this->finalizeGroupApproval($members, auth()->user());
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
        'confirm' => 'Please confirm to approve this checklist.',
        'approved' => 'The checklist was successfully approved.',
    ];

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

        return ['confirm', $members];
    }

    /**
     * Marks a single checklist row approved — status, timestamp, and
     * overdue-notification cleanup only. The completion cascade and
     * activity/notification are deliberately NOT here — see
     * `finalizeGroupApproval()`, which calls this once per member of a
     * group and only runs those once, for the group as a whole.
     */
    private function markApproved(OffboardingRequestApprover $offboardingRequestApprover): void
    {
        $offboardingRequestApprover->update(['status' => 'approved', 'approved_at' => now()]);

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
    private function finalizeGroupApproval($members, ?User $actor): void
    {
        $members->each(fn (OffboardingRequestApprover $row) => $this->markApproved($row));

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

    public function decline(Request $request, OffboardingRequestApprover $offboardingRequestApprover): RedirectResponse
    {
        $this->authorizeAssignment($offboardingRequestApprover);

        abort_unless(
            in_array($offboardingRequestApprover->status, ['pending', 'viewed'], true),
            422,
            'This has already been actioned.'
        );

        $comment = $request->string('comment')->trim()->value() ?: null;

        $offboardingRequestApprover->update([
            'status' => 'declined',
            'declined_at' => now(),
            'decline_reason' => $comment,
        ]);

        $offboardingRequest = $offboardingRequestApprover->offboardingRequest;

        // A decline from any single department is a hard stop for the whole request.
        $offboardingRequest->update(['status' => 'cancelled']);
        $offboardingRequest->employee()->update(['status' => 'active']);

        // Every other still-outstanding assignment on this request (if any)
        // is now moot too, so any overdue notices tied to them no longer
        // apply — not just the one that was just declined.
        $this->resolveOverdueNotificationsForRequest($offboardingRequest);

        $this->recordActivityAndNotify($offboardingRequest, 'declined', $comment);

        return back()->with('success', $offboardingRequest->employee->name . '\'s offboarding request was declined.');
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
            in_array($offboardingRequestApprover->status, ['approved', 'declined'], true),
            422,
            'This approver has already acted — no reminder needed.'
        );

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

        $emailTemplate = ! empty($validated['email_template_id'])
            ? EmailTemplate::find($validated['email_template_id'])
            : EmailTemplate::where('is_active', true)
                ->where('template_name', $templateName)
                ->latest('updated_at')
                ->first();

        if (! $emailTemplate) {
            return back()->with('error', 'No "' . $templateName . '" email template found. Please create one first.');
        }

        if (! $approverEmployee->email || ! filter_var($approverEmployee->email, FILTER_VALIDATE_EMAIL)) {
            return back()->with('error', 'This approver has no valid email address on file.');
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
            $offboardingRequestApprover->update(['reminder_sent_at' => now()]);

            $offboardingRequest->activities()->create([
                'user_id' => auth()->id(),
                'offboarding_request_approver_id' => $offboardingRequestApprover->id,
                'action' => 'reminder_sent',
                'status' => $offboardingRequest->status,
                'comment' => 'Sent to: ' . $approverEmployee->name,
            ]);

            return redirect()
                ->route('offboardees.index', ['offboardee' => $offboardee->id])
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
    private function recordActivityAndNotify(OffboardingRequest $offboardingRequest, string $action, ?string $comment, ?User $actor = null): void
    {
        $actor ??= auth()->user();

        try {
            $offboardingRequest->activities()->create([
                'user_id' => $actor?->id,
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
