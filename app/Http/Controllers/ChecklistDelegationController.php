<?php

namespace App\Http\Controllers;

use App\Mail\ChecklistItemApproverAssignedMail;
use App\Models\ChecklistDelegation;
use App\Models\ChecklistItem;
use App\Models\ChecklistItemProgress;
use App\Models\Employee;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use App\Models\User;
use App\Services\ChecklistCompletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ChecklistDelegationController extends Controller
{
    /**
     * The Department Head/primary approver delegates their checklist to
     * another employee, who can complete the items and add remarks but
     * never gives final approval. Reassigning supersedes (not deletes) the
     * previous delegation, preserving assignment history.
     */
    public function assign(Request $request, OffboardingRequestApprover $offboardingRequestApprover): RedirectResponse
    {
        $this->authorizePrimaryApprover($offboardingRequestApprover);

        abort_unless(
            in_array($offboardingRequestApprover->status, ['pending', 'viewed'], true),
            422,
            'This checklist has already been actioned.'
        );

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
        ]);

        abort_if(
            (int) $validated['employee_id'] === $offboardingRequestApprover->employee_id,
            422,
            'You cannot delegate a checklist to yourself.'
        );

        $employee = Employee::findOrFail($validated['employee_id']);

        DB::transaction(function () use ($offboardingRequestApprover, $employee) {
            $offboardingRequestApprover->delegations()
                ->where('status', 'active')
                ->update(['status' => 'superseded', 'superseded_at' => now()]);

            $delegateUser = User::findOrCreateApprover($employee);

            $offboardingRequestApprover->update([
                'delegated_employee_id' => $employee->id,
                'delegation_status' => 'assigned',
                'delegated_at' => now(),
                'delegate_completed_at' => null,
            ]);

            $offboardingRequestApprover->delegations()->create([
                'assigned_by_user_id' => auth()->id(),
                'delegated_employee_id' => $employee->id,
                'delegated_user_id' => $delegateUser->id,
                'status' => 'active',
                'assigned_at' => now(),
            ]);

            $offboardingRequestApprover->offboardingRequest->activities()->create([
                'user_id' => auth()->id(),
                'offboarding_request_approver_id' => $offboardingRequestApprover->id,
                'action' => 'checklist_assigned',
                'status' => $offboardingRequestApprover->offboardingRequest->status,
                'comment' => "Assigned to: {$employee->name} ({$employee->employee_code})",
            ]);
        });

        return back()->with('success', "Checklist assigned to {$employee->name}.");
    }

    /**
     * The combined-card equivalent of `assign()`: delegates EVERY checklist
     * this employee is the assigned approver for on this request to the
     * same delegate in one action, instead of one "Assign To" per
     * checklist. Group membership is re-derived from the database, never
     * trusted from the client.
     */
    public function assignGroup(Request $request, OffboardingRequest $offboardingRequest, Employee $employee): RedirectResponse
    {
        $this->authorizeGroupPrimary($employee);

        $members = OffboardingRequestApprover::where('offboarding_request_id', $offboardingRequest->id)
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'viewed'])
            ->get();

        abort_if($members->isEmpty(), 422, 'This checklist has already been actioned.');

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
        ]);

        abort_if(
            (int) $validated['employee_id'] === $employee->id,
            422,
            'You cannot delegate a checklist to yourself.'
        );

        $delegate = Employee::findOrFail($validated['employee_id']);

        DB::transaction(function () use ($members, $delegate) {
            $delegateUser = User::findOrCreateApprover($delegate);

            foreach ($members as $member) {
                $member->delegations()
                    ->where('status', 'active')
                    ->update(['status' => 'superseded', 'superseded_at' => now()]);

                $member->update([
                    'delegated_employee_id' => $delegate->id,
                    'delegation_status' => 'assigned',
                    'delegated_at' => now(),
                    'delegate_completed_at' => null,
                ]);

                $member->delegations()->create([
                    'assigned_by_user_id' => auth()->id(),
                    'delegated_employee_id' => $delegate->id,
                    'delegated_user_id' => $delegateUser->id,
                    'status' => 'active',
                    'assigned_at' => now(),
                ]);

                $member->offboardingRequest->activities()->create([
                    'user_id' => auth()->id(),
                    'offboarding_request_approver_id' => $member->id,
                    'action' => 'checklist_assigned',
                    'status' => $member->offboardingRequest->status,
                    'comment' => "Assigned to: {$delegate->name} ({$delegate->employee_code})",
                ]);
            }
        });

        return back()->with('success', "Checklist(s) assigned to {$delegate->name}.");
    }

    /**
     * Bulk "Assign Checklist": directly assigns every eligible item on one
     * or more of this employee's still undifferentiated checklists (see
     * `OffboardingRequestApprover::isEligibleForPoolAssignment()`) to the
     * selected employee(s) in one action, instead of reassigning items one
     * at a time via `assignItem()`. Reuses the EXACT same mechanism "Check
     * This List" (`takeOverItem()`) already uses — a real, active
     * `ChecklistItemAssignment` override per item — just applied to every
     * eligible item at once instead of one item a peer voluntarily claims
     * for themselves. When several employees are selected together,
     * eligible items on each checklist are distributed round-robin, one
     * real assignee per item (an item can only ever have one active
     * assignee, so a shared "pool" isn't meaningful once items are
     * genuinely being assigned rather than merely made visible). Because
     * every assignee immediately owns at least one real item, they
     * automatically get full visibility AND take-over rights over the rest
     * of that checklist via the exact same `scopeVisibleTo()`/
     * `authorizeItemAction()` paths any other item-approver already uses —
     * no separate visibility mechanism is needed on top. A checklist that
     * already has any real per-item ownership is never reopened here, and
     * already checked/held items are always skipped — only genuinely
     * eligible items are ever (re)assigned.
     */
    public function assignPool(Request $request, OffboardingRequest $offboardingRequest, Employee $employee): RedirectResponse
    {
        $this->authorizeGroupPrimary($employee);

        $validated = $request->validate([
            'checklist_template_ids' => ['required', 'array', 'min:1'],
            'checklist_template_ids.*' => ['integer', 'exists:checklist_templates,id'],
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
        ]);

        abort_if(
            in_array($employee->id, $validated['employee_ids'], true),
            422,
            'You cannot assign the checklist\'s own owner as one of its assignees.'
        );

        $assignments = OffboardingRequestApprover::where('offboarding_request_id', $offboardingRequest->id)
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'viewed'])
            ->whereIn('checklist_template_id', $validated['checklist_template_ids'])
            ->get();

        abort_if($assignments->isEmpty(), 422, 'No eligible checklists were selected.');

        foreach ($assignments as $assignment) {
            abort_if(
                ! $assignment->isEligibleForPoolAssignment(),
                422,
                "\"{$assignment->checklistTemplate->title}\" already has assigned signatories and can no longer be bulk-assigned."
            );
        }

        // Every assignment fetched above shares the same `employee_id`
        // (filtered on it just above), so `eligiblePoolAssigneeIds()` —
        // anchored on that owner, not on any one checklist's own template —
        // returns the identical pool regardless of which assignment it's
        // called on. Re-derived here rather than trusting the client's own
        // (identical) filtering, so a manually crafted request can never
        // assign someone outside the current Clearance Signatory/Immediate
        // Head's own scope.
        $eligibleIds = $assignments->first()->eligiblePoolAssigneeIds();

        abort_if(
            count(array_diff($validated['employee_ids'], $eligibleIds)) > 0,
            422,
            'One or more selected employees are not eligible to be assigned to this checklist.'
        );

        // Stable submitted order, so distribution is predictable and a
        // repeat submission with the same selection lands the same way.
        $assigneePool = Employee::whereIn('id', $validated['employee_ids'])->get()
            ->sortBy(fn (Employee $e) => array_search($e->id, $validated['employee_ids'], true))
            ->values();

        // Every item this action actually assigned, grouped by its new
        // assignee (and whether their account is brand new) — built inside
        // the transaction, consumed by the consolidated per-recipient email
        // afterward. Never includes another recipient's share of the same
        // checklist.
        $assignedByEmployeeId = [];

        DB::transaction(function () use ($assignments, $assigneePool, &$assignedByEmployeeId) {
            foreach ($assignments as $assignment) {
                $assignment->loadMissing('checklistTemplate.items', 'itemProgress');

                $checkedItemIds = $assignment->itemProgress->where('is_checked', true)->pluck('checklist_item_id');
                $heldItemIds = $assignment->itemProgress->where('status', 'hold')->pluck('checklist_item_id');

                // Only items with no real ownership and no reason to stay
                // untouched — `isEligibleForPoolAssignment()` above already
                // guarantees no item here has a distinct signatory, but the
                // PRIMARY approver may still have personally checked (or
                // held) some items directly even on a checklist that's
                // never had per-item approvers, so this is re-checked here.
                $eligibleItems = $assignment->checklistTemplate->items
                    ->reject(fn (ChecklistItem $item) => $checkedItemIds->contains($item->id) || $heldItemIds->contains($item->id))
                    ->values();

                if ($eligibleItems->isEmpty()) {
                    continue;
                }

                $assignedNames = [];
                $dueAt = $assignment->due_at?->format('M d, Y');

                foreach ($eligibleItems as $index => $item) {
                    /** @var Employee $assignee */
                    $assignee = $assigneePool[$index % $assigneePool->count()];

                    $isFirstTimeThisRun = ! isset($assignedByEmployeeId[$assignee->id]);
                    $existingUser = $isFirstTimeThisRun ? User::firstWhere('username', $assignee->employee_code) : null;
                    $assignedUser = User::findOrCreateApprover($assignee);

                    // Supersede any existing active override — always the
                    // "no signatory" snapshot row at this point, since
                    // `isEligibleForPoolAssignment()` already ruled out any
                    // item with a real distinct signatory — exactly the
                    // same supersede-then-create pattern `takeOverItem()`
                    // already uses for a single, voluntarily claimed item.
                    $assignment->itemAssignments()
                        ->where('checklist_item_id', $item->id)
                        ->where('status', 'active')
                        ->update(['status' => 'superseded', 'superseded_at' => now()]);

                    $assignment->itemAssignments()->create([
                        'checklist_item_id' => $item->id,
                        'assigned_by_user_id' => auth()->id(),
                        'assigned_employee_id' => $assignee->id,
                        'assigned_user_id' => $assignedUser->id,
                        'status' => 'active',
                        'assigned_at' => now(),
                    ]);

                    $assignedNames[$assignee->id] ??= "{$assignee->name} ({$assignee->employee_code})";
                    $assignedByEmployeeId[$assignee->id]['employee'] ??= $assignee;
                    if ($isFirstTimeThisRun) {
                        $assignedByEmployeeId[$assignee->id]['isNewAccount'] = $existingUser === null;
                    }
                    $assignedByEmployeeId[$assignee->id]['items'][] = [
                        'checklistTitle' => $assignment->checklistTemplate->title,
                        'itemTitle' => $item->title,
                        'dueAt' => $dueAt,
                    ];
                }

                $assignment->offboardingRequest->activities()->create([
                    'user_id' => auth()->id(),
                    'offboarding_request_approver_id' => $assignment->id,
                    'action' => 'checklist_pool_assigned',
                    'status' => $assignment->offboardingRequest->status,
                    'comment' => "\"{$assignment->checklistTemplate->title}\" ({$eligibleItems->count()} item(s)) assigned to: " . implode(', ', $assignedNames) . '.',
                ]);
            }
        });

        $this->notifyPoolAssignees($offboardingRequest, $assignedByEmployeeId);

        $assignedCount = count($assignedByEmployeeId);

        return back()->with('success', $assignedCount > 0
            ? "Checklist(s) assigned to {$assignedCount} employee(s)."
            : 'No eligible task lists were available to assign.');
    }

    /**
     * Sends the same "Offboarding Checklist Assigned to You" email
     * `notifyItemApprovers()` sends at creation time, consolidated per
     * recipient across every item they were just directly assigned in this
     * one bulk action (possibly across several checklists) — reused
     * completely unchanged, since a bulk-assigned employee is functionally
     * in the exact same position as any other newly-assigned item
     * approver. Listed items are ONLY the ones THIS recipient was actually
     * assigned — never a co-recipient's share of the same checklist.
     *
     * @param  array<int, array{employee: Employee, isNewAccount: bool, items: array<int, array{checklistTitle: string, itemTitle: string, dueAt: ?string}>}>  $assignedByEmployeeId
     */
    private function notifyPoolAssignees(OffboardingRequest $offboardingRequest, array $assignedByEmployeeId): void
    {
        $offboardee = $offboardingRequest->employee;

        foreach ($assignedByEmployeeId as $entry) {
            $employee = $entry['employee'];

            if (! $employee->email || ! filter_var($employee->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            try {
                Mail::to($employee->email)->send(new ChecklistItemApproverAssignedMail(
                    approverName: $employee->name,
                    offboardeeName: $offboardee->name,
                    offboardeeEmployeeCode: $offboardee->employee_code,
                    assignedItems: $entry['items'],
                    approvalUrl: route('approvals.index'),
                    credentials: $entry['isNewAccount'] ? [
                        'username' => $employee->employee_code,
                        'password' => $employee->employee_code,
                    ] : null,
                ));
            } catch (\Throwable $e) {
                Log::error('Failed to send checklist bulk assignment email.', [
                    'offboarding_request_id' => $offboardingRequest->id,
                    'employee_id' => $employee->id,
                    'recipient' => $employee->email,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * The Department Head reassigns a single checklist item to a different
     * employee — e.g. because the item's originally assigned signatory is
     * unavailable. Scoped to just this one item on this one offboarding
     * request; the shared checklist template's own `signatory_id` is never
     * touched, so every other request reusing the same template is
     * unaffected. The previous assignment is superseded, not deleted, so it
     * stays on record (mirrors how `assign()` handles whole-checklist
     * delegation above). Only the Department Head/primary approver (or
     * admin) may do this — never a delegate or another item's signatory.
     */
    public function assignItem(Request $request, OffboardingRequestApprover $offboardingRequestApprover, ChecklistItem $checklistItem): RedirectResponse
    {
        $this->authorizePrimaryApprover($offboardingRequestApprover);

        abort_unless($checklistItem->checklist_template_id === $offboardingRequestApprover->checklist_template_id, 404);

        abort_unless(
            in_array($offboardingRequestApprover->status, ['pending', 'viewed'], true),
            422,
            'This checklist has already been actioned.'
        );

        $existingProgress = $offboardingRequestApprover->itemProgress()
            ->where('checklist_item_id', $checklistItem->id)
            ->first();

        abort_if((bool) $existingProgress?->is_checked, 422, 'This checklist item has already been checked and cannot be reassigned.');

        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
        ]);

        $newEmployee = Employee::findOrFail($validated['employee_id']);
        $previousEmployee = $offboardingRequestApprover->effectiveSignatoryFor($checklistItem);

        abort_if(
            $previousEmployee && (int) $validated['employee_id'] === $previousEmployee->id,
            422,
            'This item is already assigned to that employee.'
        );

        // Resolved BEFORE the transaction so the caller knows, once it
        // commits, whether a brand-new account was created — that decides
        // whether the notification email includes login credentials.
        $existingUser = User::firstWhere('username', $newEmployee->employee_code);

        DB::transaction(function () use ($offboardingRequestApprover, $checklistItem, $newEmployee, $previousEmployee, $existingUser) {
            $offboardingRequestApprover->itemAssignments()
                ->where('checklist_item_id', $checklistItem->id)
                ->where('status', 'active')
                ->update(['status' => 'superseded', 'superseded_at' => now()]);

            $newUser = $existingUser ?? User::findOrCreateApprover($newEmployee);

            $offboardingRequestApprover->itemAssignments()->create([
                'checklist_item_id' => $checklistItem->id,
                'assigned_by_user_id' => auth()->id(),
                'assigned_employee_id' => $newEmployee->id,
                'assigned_user_id' => $newUser->id,
                'status' => 'active',
                'assigned_at' => now(),
            ]);

            $offboardingRequestApprover->offboardingRequest->activities()->create([
                'user_id' => auth()->id(),
                'offboarding_request_approver_id' => $offboardingRequestApprover->id,
                'action' => 'checklist_item_reassigned',
                'status' => $offboardingRequestApprover->offboardingRequest->status,
                'comment' => "\"{$checklistItem->title}\" reassigned from "
                    . ($previousEmployee ? "{$previousEmployee->employee_code} - {$previousEmployee->name}" : 'Unassigned')
                    . " to {$newEmployee->employee_code} - {$newEmployee->name}.",
            ]);
        });

        $this->notifyReassignedApprover($offboardingRequestApprover, $checklistItem, $newEmployee, $existingUser === null);

        return back()->with('success', "\"{$checklistItem->title}\" reassigned to {$newEmployee->name}.");
    }

    /**
     * A peer item-approver voluntarily accepts responsibility for a
     * different, not-yet-checked item on the same assignment — "Check This
     * List" on the Approvals page. Unlike `assignItem()` (Department
     * Head/admin reassigning someone ELSE), the acting user here claims the
     * item for THEMSELVES, so this only records a real
     * `ChecklistItemAssignment` override (exactly like `assignItem()`'s
     * effect, just self-targeted) — it deliberately never touches
     * `ChecklistItemProgress`/`is_checked`. Accepting the item must only
     * make it editable for its new signatory (checkbox/remark/Hold/Done all
     * unlock via `effectiveSignatoryFor()` once this row exists); the item
     * stays unchecked until the approver explicitly checks it and clicks
     * Done, or places it on Hold, themselves.
     */
    public function takeOverItem(Request $request, OffboardingRequestApprover $offboardingRequestApprover, ChecklistItem $checklistItem): JsonResponse
    {
        abort_unless($checklistItem->checklist_template_id === $offboardingRequestApprover->checklist_template_id, 404);

        abort_unless(
            in_array($offboardingRequestApprover->status, ['pending', 'viewed'], true),
            422,
            'This checklist has already been actioned.'
        );

        $itemScope = $this->authorizeItemAction($offboardingRequestApprover);

        // A bare item-approver's scope is their own item(s) plus every OTHER
        // not-yet-checked/held item on this assignment (see
        // `authorizeItemAction()`) — exactly the "Check This List" pool.
        // `null` (admin/primary approver/delegate) already has unrestricted
        // access to every item directly, so this endpoint is a no-op
        // authorization-wise for them.
        abort_if($itemScope !== null && ! in_array($checklistItem->id, $itemScope, true), 403);

        $existingProgress = $offboardingRequestApprover->itemProgress()
            ->where('checklist_item_id', $checklistItem->id)
            ->first();

        abort_if((bool) $existingProgress?->is_checked, 422, 'This checklist item has already been completed.');
        abort_if($existingProgress?->status === 'hold', 422, 'This checklist item is on Hold and can only be resolved by its assigned approver.');

        $employee = auth()->user()->employee;

        abort_if($employee === null, 403);

        $previousEmployee = $offboardingRequestApprover->effectiveSignatoryFor($checklistItem);

        if ($previousEmployee?->id === $employee->id) {
            // Already the effective signatory (e.g. a repeat click) — nothing to do,
            // but still return the current state so the UI can settle correctly.
            return $this->takeOverItemResponse($offboardingRequestApprover, $checklistItem, $employee);
        }

        DB::transaction(function () use ($offboardingRequestApprover, $checklistItem, $employee, $previousEmployee) {
            $offboardingRequestApprover->itemAssignments()
                ->where('checklist_item_id', $checklistItem->id)
                ->where('status', 'active')
                ->update(['status' => 'superseded', 'superseded_at' => now()]);

            $offboardingRequestApprover->itemAssignments()->create([
                'checklist_item_id' => $checklistItem->id,
                'assigned_by_user_id' => auth()->id(),
                'assigned_employee_id' => $employee->id,
                'assigned_user_id' => auth()->id(),
                'status' => 'active',
                'assigned_at' => now(),
            ]);

            $offboardingRequestApprover->offboardingRequest->activities()->create([
                'user_id' => auth()->id(),
                'offboarding_request_approver_id' => $offboardingRequestApprover->id,
                'action' => 'checklist_item_reassigned',
                'status' => $offboardingRequestApprover->offboardingRequest->status,
                'comment' => "\"{$checklistItem->title}\" accepted by {$employee->employee_code} - {$employee->name}"
                    . ($previousEmployee ? " (previously {$previousEmployee->employee_code} - {$previousEmployee->name})." : ' (previously unassigned).'),
            ]);
        });

        return $this->takeOverItemResponse($offboardingRequestApprover, $checklistItem, $employee);
    }

    /**
     * The JSON payload `takeOverItem()` returns on success — everything the
     * Approvals page's checklist modal needs to patch this one item (and the
     * whole assignment's per-item-approver/completion flags) into its live
     * Alpine state in place, so "Check This List" can unlock Hold/Done
     * immediately without a page reload. Mirrors exactly the same field
     * shape/derivation `ApprovalController::index()` uses when building the
     * initial `checklistItems` array, so the reactive item stays consistent
     * with what a fresh page load would have shown.
     */
    private function takeOverItemResponse(OffboardingRequestApprover $offboardingRequestApprover, ChecklistItem $checklistItem, Employee $employee): JsonResponse
    {
        $offboardingRequestApprover->load(['checklistTemplate.items', 'itemAssignments.assignedEmployee', 'itemProgress']);

        $isReassigned = $employee->id !== $checklistItem->signatory_id;

        return response()->json([
            'item' => [
                'id' => $checklistItem->id,
                'approverName' => $employee->name,
                'approverCode' => $employee->employee_code,
                'originalApproverName' => $isReassigned ? $checklistItem->signatory?->name : null,
                'originalApproverCode' => $isReassigned ? $checklistItem->signatory?->employee_code : null,
                'isReassigned' => $isReassigned,
                'editable' => true,
                'isOwnItem' => true,
                'canTakeOver' => false,
            ],
            'usesPerItemApprovers' => $offboardingRequestApprover->usesPerItemApprovers(),
            'mustBeCheckedForSubmit' => $offboardingRequestApprover->requiresAllItemsCompletedBeforeApproval(),
            'allItemsCompleted' => $offboardingRequestApprover->allItemsCompleted(),
        ]);
    }

    /**
     * Emails the newly assigned approver — reusing the same mailable and
     * consolidated-per-approver shape as the initial checklist-attach
     * notification (`ChecklistApprovalNotifier::notifyItemApprovers()`),
     * just for a single item triggered by a live reassignment instead.
     * Sent outside the DB transaction in `assignItem()` so a slow/failed
     * mail send can never roll back an otherwise-successful reassignment —
     * same pattern as `remind()` below. Credentials are included only when
     * `$isNewAccount` is true; an approver who already had an account never
     * has their (unchanged) password exposed here.
     */
    private function notifyReassignedApprover(OffboardingRequestApprover $offboardingRequestApprover, ChecklistItem $checklistItem, Employee $newEmployee, bool $isNewAccount): void
    {
        if (! $newEmployee->email || ! filter_var($newEmployee->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $offboardee = $offboardingRequestApprover->offboardingRequest->employee;

        try {
            Mail::to($newEmployee->email)->send(new ChecklistItemApproverAssignedMail(
                approverName: $newEmployee->name,
                offboardeeName: $offboardee->name,
                offboardeeEmployeeCode: $offboardee->employee_code,
                assignedItems: [[
                    'checklistTitle' => $offboardingRequestApprover->checklistTemplate->title,
                    'itemTitle' => $checklistItem->title,
                    'dueAt' => $offboardingRequestApprover->due_at?->format('M d, Y'),
                ]],
                approvalUrl: route('approvals.index'),
                credentials: $isNewAccount ? [
                    'username' => $newEmployee->employee_code,
                    'password' => $newEmployee->employee_code,
                ] : null,
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send checklist item reassignment email.', [
                'offboarding_request_approver_id' => $offboardingRequestApprover->id,
                'checklist_item_id' => $checklistItem->id,
                'recipient' => $newEmployee->email,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Saves per-item checked state and remarks. Reachable by the delegate
     * (doing the actual work), the primary approver (making corrections
     * before final approval), an item's own assigned approver (per-item
     * approvers), or — new — a peer item-approver voluntarily taking over
     * a *different*, not-yet-checked item on the same assignment ("Check
     * This List") — never an unrelated approver, and never an already
     * -completed item that isn't theirs.
     */
    public function saveProgress(Request $request, OffboardingRequestApprover $offboardingRequestApprover): RedirectResponse
    {
        $itemScope = $this->authorizeItemAction($offboardingRequestApprover);

        abort_unless(
            in_array($offboardingRequestApprover->status, ['pending', 'viewed'], true),
            422,
            'This checklist has already been actioned.'
        );

        $validated = $request->validate([
            'items' => ['array'],
            'items.*.checklist_item_id' => ['required', 'exists:checklist_items,id'],
            'items.*.is_checked' => ['nullable', 'boolean'],
            'items.*.remark' => ['nullable', 'string'],
        ]);

        $items = collect($validated['items'] ?? []);

        // A bare item-approver (not the primary/delegate/admin) is scoped
        // to only the item(s) they actually own — never trust the client
        // to only submit items it's allowed to touch.
        if ($itemScope !== null) {
            $items = $items->whereIn('checklist_item_id', $itemScope);
        }

        $this->logTakeoverActivity($offboardingRequestApprover, $items->all());

        ChecklistItemProgress::syncForAssignment($offboardingRequestApprover, $items->all(), auth()->id());

        // At least one item was actually checked/completed in this save —
        // the checklist has now genuinely been acted upon, so its status
        // should read "In Progress" rather than sit at "Pending" until the
        // Department Head separately views or approves it.
        if ($items->contains(fn (array $row) => ! empty($row['is_checked']))) {
            $offboardingRequestApprover->markInProgressIfPending();
        }

        if ($offboardingRequestApprover->isDelegated() && $offboardingRequestApprover->delegation_status === 'assigned') {
            $offboardingRequestApprover->update(['delegation_status' => 'in_progress']);
        }

        // No-op for legacy/non-per-item assignments, so this is always safe
        // to call unconditionally. Notifies the Department Head once every
        // checklist in this employee's whole group for this request is
        // ready — not just this one — the Department Head still has to
        // review and click Submit themselves; this does not approve
        // anything.
        app(ChecklistCompletionService::class)->checkGroupReadyForApproval(
            $offboardingRequestApprover->offboardingRequest,
            $offboardingRequestApprover->employee_id
        );

        return back()->with('success', 'Checklist progress saved.');
    }

    /**
     * The combined-card equivalent of `saveProgress()`: persists item
     * progress across EVERY checklist this employee is the assigned
     * approver for on this request in one submission — what the Approvals
     * page's combined card's Save Progress button now posts to. Scoped to
     * `OffboardingRequestApprover::visibleTo()` so a delegate or item-
     * signatory who only has rights on SOME of the group's checklists can
     * never touch the rest of it through this endpoint — the true primary
     * approver (or admin) always sees their own full group already (see
     * that scope's docblock), so no member is silently skipped for them.
     */
    public function saveProgressGroup(Request $request, OffboardingRequest $offboardingRequest, Employee $employee): RedirectResponse|JsonResponse
    {
        $members = OffboardingRequestApprover::visibleTo(auth()->user())
            ->where('offboarding_request_id', $offboardingRequest->id)
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'viewed'])
            ->get();

        abort_if($members->isEmpty(), 403);

        $validated = $request->validate([
            'items' => ['array'],
            'items.*.checklist_item_id' => ['required', 'exists:checklist_items,id'],
            'items.*.is_checked' => ['nullable', 'boolean'],
            'items.*.remark' => ['nullable', 'string'],
        ]);

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
            // Every member row here already passed `visibleTo()`, which
            // covers exactly the same ground `authorizeItemAction()` checks
            // (primary/delegate, or an owned item via the template's static
            // signatory or an active per-request override) — so this never
            // actually aborts for a row reached through this method; it
            // still returns the correct item-id scope for a bare
            // item-approver, same as the single-row `saveProgress()`.
            $itemScope = $this->authorizeItemAction($member);
            $items = collect($itemsByAssignmentId->get($member->id, collect())->all());

            if ($itemScope !== null) {
                $items = $items->whereIn('checklist_item_id', $itemScope);
            }

            $this->logTakeoverActivity($member, $items->all());

            ChecklistItemProgress::syncForAssignment($member, $items->all(), auth()->id());

            // Same "genuinely acted upon" bump as the single-row
            // `saveProgress()` above, per member of the combined group.
            if ($items->contains(fn (array $row) => ! empty($row['is_checked']))) {
                $member->markInProgressIfPending();
            }

            if ($member->isDelegated() && $member->delegation_status === 'assigned') {
                $member->update(['delegation_status' => 'in_progress']);
            }
        }

        app(ChecklistCompletionService::class)->checkGroupReadyForApproval($offboardingRequest, $employee->id);

        // The "Done" button on the Approvals page's checklist modal submits
        // here via `fetch()` (not a real form navigation) precisely so the
        // dialog never closes/reloads on a single item's completion — it
        // asks for JSON and patches just the now-checked item(s) into its
        // own live Alpine state instead. A real browser form submission
        // (Save Progress) never sends this header, so that flow is
        // completely unaffected — same redirect-with-flash as always.
        if ($request->wantsJson()) {
            return response()->json([
                'items' => $this->checkedItemPatches($members),
                'message' => 'Task list successfully checked.',
            ]);
        }

        return back()->with('success', 'Checklist progress saved.');
    }

    /**
     * The fresh, post-save state of every currently-checked item across the
     * given assignments — shape-compatible with a single checklist item
     * entry on the Approvals page (`checked`/`clearedByName`/`clearedByCode`/
     * `clearedAt`), so the "Done" button's `fetch()` response can
     * `Object.assign()` it straight onto the matching item in the modal's
     * live state with no further transformation. Scoped to only checked
     * items (never held/pending ones) since this exists purely to reflect
     * what a `saveProgress()`/`saveProgressGroup()` submission just
     * persisted back to the client without a page reload.
     *
     * @param  \Illuminate\Support\Collection<int, OffboardingRequestApprover>  $members
     * @return array<int, array{id: int, checked: bool, onHold: bool, editable: bool, clearedByName: ?string, clearedByCode: ?string, clearedAt: ?string}>
     */
    private function checkedItemPatches($members): array
    {
        $patches = [];

        foreach ($members as $member) {
            // A forced `load()`, not `loadMissing()` — `authorizeItemAction()`
            // already cached this same relation (empty, pre-sync) earlier in
            // this same request for every member, so `loadMissing()` here
            // would silently keep serving that stale, empty snapshot instead
            // of the rows `ChecklistItemProgress::syncForAssignment()` just
            // created/updated moments ago.
            $member->load('checklistTemplate.items', 'itemProgress.checkedBy.employee');
            $progressByItemId = $member->itemProgress->keyBy('checklist_item_id');

            foreach ($member->checklistTemplate->items as $item) {
                $progress = $progressByItemId->get($item->id);

                if (! $progress?->is_checked) {
                    continue;
                }

                $checkedByEmployee = $progress->checkedBy?->employee;

                $patches[] = [
                    'id' => $item->id,
                    'checked' => true,
                    'onHold' => false,
                    // Already checked — never editable again, matching the
                    // template's own `x-if="item.editable && !item.checked"`
                    // gate that hides Hold/Done the moment `checked` is true.
                    'editable' => false,
                    'clearedByName' => $progress->checkedBy?->name,
                    'clearedByCode' => $checkedByEmployee?->employee_code,
                    'clearedAt' => $progress->checked_at?->format('M d, Y g:i A'),
                ];
            }
        }

        return $patches;
    }

    /**
     * Places a single checklist item on Hold — reachable only by that
     * item's own assigned approver (or the primary approver/delegate/admin,
     * who already have broad edit rights over every item). Remarks are
     * required, since they're the reason the Department Head sees for why
     * the item is being held. Re-holding an already-held item just updates
     * the remark/holder without logging a second audit entry — only the
     * genuine not-held -> held transition is recorded.
     */
    public function holdItem(Request $request, OffboardingRequestApprover $offboardingRequestApprover, ChecklistItem $checklistItem): RedirectResponse|JsonResponse
    {
        abort_unless($checklistItem->checklist_template_id === $offboardingRequestApprover->checklist_template_id, 404);

        $this->authorizeItemEditor($offboardingRequestApprover, $checklistItem);

        abort_unless(
            in_array($offboardingRequestApprover->status, ['pending', 'viewed'], true),
            422,
            'This checklist has already been actioned.'
        );

        $validated = $request->validate([
            'remark' => ['required', 'string', 'max:2000'],
        ]);

        $existing = $offboardingRequestApprover->itemProgress()
            ->where('checklist_item_id', $checklistItem->id)
            ->first();

        abort_if((bool) $existing?->is_checked, 422, 'This checklist item has already been completed and cannot be put on hold.');

        $wasAlreadyOnHold = $existing?->status === 'hold';

        ChecklistItemProgress::updateOrCreate(
            [
                'offboarding_request_approver_id' => $offboardingRequestApprover->id,
                'checklist_item_id' => $checklistItem->id,
            ],
            [
                'is_checked' => false,
                'remark' => $validated['remark'],
                'status' => 'hold',
                'held_by_user_id' => auth()->id(),
                'held_at' => now(),
            ]
        );

        if (! $wasAlreadyOnHold) {
            $offboardingRequestApprover->offboardingRequest->activities()->create([
                'user_id' => auth()->id(),
                'offboarding_request_approver_id' => $offboardingRequestApprover->id,
                'action' => 'checklist_item_held',
                'status' => $offboardingRequestApprover->offboardingRequest->status,
                'comment' => "\"{$checklistItem->title}\" — Reason: {$validated['remark']}",
            ]);
        }

        // Placing an item on Hold is itself being acted upon — the
        // checklist should read "In Progress" rather than "Pending" from
        // this point on, same as actually checking an item does.
        $offboardingRequestApprover->markInProgressIfPending();

        // The checklist modal's Hold button submits here via `fetch()` (not
        // a real form navigation) precisely so the dialog never
        // closes/reloads on a successful hold — same convention as the
        // "Done" button's `saveProgressGroup()` JSON branch. Deliberately
        // does NOT mark the item non-editable or checked — per spec, a held
        // item must stay fully available so the assignee can still clear it
        // later; only `onHold`/`heldByName`/`heldByCode`/`heldAt` change.
        if ($request->wantsJson()) {
            $heldByEmployee = auth()->user()?->employee;

            return response()->json([
                'item' => [
                    'id' => $checklistItem->id,
                    'onHold' => true,
                    'heldByName' => $heldByEmployee?->name ?? auth()->user()?->name,
                    'heldByCode' => $heldByEmployee?->employee_code,
                    'heldAt' => now()->format('M d, Y g:i A'),
                ],
                'message' => "\"{$checklistItem->title}\" successfully placed on Hold.",
            ]);
        }

        return back()->with('success', "\"{$checklistItem->title}\" placed on Hold.");
    }

    /**
     * Only the Department Head/primary approver (or admin) may assign a
     * checklist to a delegate.
     */
    private function authorizePrimaryApprover(OffboardingRequestApprover $offboardingRequestApprover): void
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return;
        }

        abort_unless($offboardingRequestApprover->employee_id === $user->employee?->id, 403);
    }

    /**
     * The group equivalent of `authorizePrimaryApprover()`: only the true
     * primary approver (or admin) may bulk-delegate a whole combined group
     * to someone else.
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
     * The delegate (doing the work), the primary approver (reviewing /
     * correcting), an item's own assigned approver, or a flagged Task
     * Assignee of the Clearance Signatory's own Employee Master group (see
     * `OffboardingRequestApprover::scopeVisibleTo()`'s matching clause) may
     * save item progress — never an unrelated approver. Returns the
     * caller's allowed item-id scope: `null` means unrestricted (admin,
     * primary approver, or delegate — same as today), an array restricts a
     * bare item-approver to only the item(s) they're actually assigned,
     * PLUS any other item on this same assignment that hasn't been checked
     * yet ("Check This List" — a peer item-approver may voluntarily take
     * over an item that isn't theirs, but never one someone has already
     * completed). A group-flagged Task Assignee with no item of their own
     * yet simply gets an empty owned set merged with that same take-over-
     * eligible pool — identical to any other item-approver's first visit,
     * before they've claimed anything.
     *
     * @return array<int, int>|null
     */
    private function authorizeItemAction(OffboardingRequestApprover $offboardingRequestApprover): ?array
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return null;
        }

        $employee = $user->employee;
        abort_if($employee === null, 403);
        $employeeId = $employee->id;

        if ($offboardingRequestApprover->employee_id === $employeeId
            || $offboardingRequestApprover->delegated_employee_id === $employeeId) {
            return null;
        }

        $offboardingRequestApprover->loadMissing('checklistTemplate.items', 'itemProgress', 'itemAssignments.assignedEmployee');

        $ownedItemIds = $offboardingRequestApprover->checklistTemplate->items
            ->filter(fn (ChecklistItem $item) => $offboardingRequestApprover->effectiveSignatoryFor($item)?->id === $employeeId)
            ->pluck('id');

        // Same "flagged Task Assignee of this checklist's own group"
        // predicate as `scopeVisibleTo()` — someone who reaches this
        // assignment only through that clause (not yet the effective
        // signatory of any specific item) must still be let through, with
        // an empty owned set, rather than 403'd outright.
        $template = $offboardingRequestApprover->checklistTemplate;
        $isGroupTaskAssignee = $employee->is_task_assignee
            && $employee->employee_group_id !== null
            && $template->employee_group_id === $employee->employee_group_id
            && $template->department_head_id !== $employeeId;

        // Must legitimately be an item-approver on THIS assignment
        // somewhere, OR a flagged group Task Assignee of it, to be granted
        // visibility/access at all — being an item-approver elsewhere in
        // the app doesn't count. Someone reached via the bulk "Assign
        // Checklist" action already owns at least one item by the time they
        // get here (it assigns real `ChecklistItemAssignment` rows, exactly
        // like "Check This List" does), so `$ownedItemIds` is never empty
        // for them — no separate carve-out needed.
        abort_if($ownedItemIds->isEmpty() && ! $isGroupTaskAssignee, 403);

        $checkedItemIds = $offboardingRequestApprover->itemProgress
            ->where('is_checked', true)
            ->pluck('checklist_item_id');

        // An item its own approver has explicitly put on Hold is a
        // deliberate pause, not up for grabs — only that approver (or the
        // primary approver/delegate/admin) can resolve it.
        $heldItemIds = $offboardingRequestApprover->itemProgress
            ->where('status', 'hold')
            ->pluck('checklist_item_id');

        $takeoverEligibleIds = $offboardingRequestApprover->checklistTemplate->items
            ->pluck('id')
            ->diff($checkedItemIds)
            ->diff($heldItemIds);

        return $ownedItemIds->merge($takeoverEligibleIds)->unique()->values()->all();
    }

    /**
     * Authorizes an action scoped to exactly one checklist item: the item's
     * own assigned signatory, or the primary approver/delegate/admin (who
     * already have broad edit rights over every item on the assignment) —
     * mirrors the same "editable" boundary shown in the UI.
     */
    private function authorizeItemEditor(OffboardingRequestApprover $offboardingRequestApprover, ChecklistItem $checklistItem): void
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return;
        }

        $employeeId = $user->employee?->id;
        abort_if($employeeId === null, 403);

        $isPrimaryOrDelegate = $offboardingRequestApprover->employee_id === $employeeId
            || $offboardingRequestApprover->delegated_employee_id === $employeeId;

        abort_unless(
            $isPrimaryOrDelegate || $offboardingRequestApprover->effectiveSignatoryFor($checklistItem)?->id === $employeeId,
            403
        );
    }

    /**
     * Records an audit-trail activity whenever a checklist item that has an
     * effective signatory (its own assigned/reassigned approver — see
     * `effectiveSignatoryFor()`) transitions from unchecked to checked —
     * whether that's the rightful signatory completing their own item via
     * Done, or a peer item-approver/Department Head "taking over" an item
     * that isn't theirs. Only fires on the genuine false->true transition
     * (never re-fires on a later remark edit or resave of an already-
     * completed item). Items with no effective signatory at all (a plain
     * legacy checklist item the Department Head checks directly) are left
     * alone, as before — that's covered by the whole-checklist Approve/
     * Decline activity instead, not per item. Must run BEFORE
     * `ChecklistItemProgress::syncForAssignment()` persists the new state,
     * since it needs the PREVIOUS checked state to detect the transition.
     *
     * @param  array<int, array{checklist_item_id: int, is_checked?: bool, remark?: ?string}>  $items
     */
    private function logTakeoverActivity(OffboardingRequestApprover $offboardingRequestApprover, array $items): void
    {
        $actingUserId = auth()->id();

        if (! $actingUserId) {
            return;
        }

        $offboardingRequestApprover->loadMissing('checklistTemplate.items.signatory.user', 'itemProgress', 'itemAssignments.assignedEmployee.user');

        $itemsById = $offboardingRequestApprover->checklistTemplate->items->keyBy('id');
        $existingProgress = $offboardingRequestApprover->itemProgress->keyBy('checklist_item_id');

        foreach ($items as $row) {
            if (empty($row['is_checked'])) {
                continue;
            }

            $wasAlreadyChecked = (bool) ($existingProgress->get($row['checklist_item_id'])?->is_checked ?? false);

            if ($wasAlreadyChecked) {
                continue;
            }

            $item = $itemsById->get($row['checklist_item_id']);
            $effectiveSignatory = $item ? $offboardingRequestApprover->effectiveSignatoryFor($item) : null;

            if (! $effectiveSignatory) {
                continue;
            }

            $wasClearedByOwnSignatory = $effectiveSignatory->user?->id === $actingUserId;

            $offboardingRequestApprover->offboardingRequest->activities()->create([
                'user_id' => $actingUserId,
                'offboarding_request_approver_id' => $offboardingRequestApprover->id,
                'action' => 'checklist_item_cleared_by_other',
                'status' => $offboardingRequestApprover->offboardingRequest->status,
                'comment' => $wasClearedByOwnSignatory
                    ? "\"{$item->title}\" completed by its assigned approver."
                    : "\"{$item->title}\" — originally assigned to {$effectiveSignatory->name}.",
            ]);
        }
    }
}
