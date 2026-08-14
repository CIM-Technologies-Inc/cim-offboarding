<?php

namespace App\Http\Controllers;

use App\Mail\ChecklistItemApproverAssignedMail;
use App\Models\ChecklistDelegation;
use App\Models\ChecklistItem;
use App\Models\ChecklistItemProgress;
use App\Models\Employee;
use App\Models\OffboardingRequestApprover;
use App\Models\User;
use App\Services\ChecklistCompletionService;
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

        if ($offboardingRequestApprover->isDelegated() && $offboardingRequestApprover->delegation_status === 'assigned') {
            $offboardingRequestApprover->update(['delegation_status' => 'in_progress']);
        }

        // No-op for legacy/non-per-item assignments, so this is always safe
        // to call unconditionally. Notifies the Department Head once every
        // item is checked — the Department Head still has to review and
        // click Submit themselves; this does not approve anything.
        app(ChecklistCompletionService::class)->checkReadyForDepartmentHeadApproval($offboardingRequestApprover);

        return back()->with('success', 'Checklist progress saved.');
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
    public function holdItem(Request $request, OffboardingRequestApprover $offboardingRequestApprover, ChecklistItem $checklistItem): RedirectResponse
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
     * The delegate (doing the work), the primary approver (reviewing /
     * correcting), or an item's own assigned approver may save item
     * progress — never an unrelated approver. Returns the caller's allowed
     * item-id scope: `null` means unrestricted (admin, primary approver, or
     * delegate — same as today), an array restricts a bare item-approver to
     * only the item(s) they're actually assigned, PLUS any other item on
     * this same assignment that hasn't been checked yet ("Check This
     * List" — a peer item-approver may voluntarily take over an item that
     * isn't theirs, but never one someone has already completed).
     *
     * @return array<int, int>|null
     */
    private function authorizeItemAction(OffboardingRequestApprover $offboardingRequestApprover): ?array
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return null;
        }

        $employeeId = $user->employee?->id;
        abort_if($employeeId === null, 403);

        if ($offboardingRequestApprover->employee_id === $employeeId
            || $offboardingRequestApprover->delegated_employee_id === $employeeId) {
            return null;
        }

        $offboardingRequestApprover->loadMissing('checklistTemplate.items', 'itemProgress', 'itemAssignments.assignedEmployee');

        $ownedItemIds = $offboardingRequestApprover->checklistTemplate->items
            ->filter(fn (ChecklistItem $item) => $offboardingRequestApprover->effectiveSignatoryFor($item)?->id === $employeeId)
            ->pluck('id');

        // Must legitimately be an item-approver on THIS assignment somewhere
        // to be granted visibility/access at all — being an item-approver
        // elsewhere in the app doesn't count.
        abort_if($ownedItemIds->isEmpty(), 403);

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
     * Records an audit-trail activity whenever a checklist item transitions
     * from unchecked to checked by someone other than that item's own
     * assigned signatory — a peer item-approver "taking over" an item that
     * isn't theirs. Only fires on the genuine false->true transition (never
     * re-fires on a later remark edit or resave of an already-completed
     * item), and never fires when the rightful signatory checks their own
     * item. Must run BEFORE `ChecklistItemProgress::syncForAssignment()`
     * persists the new state, since it needs the PREVIOUS checked state to
     * detect the transition.
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
            $signatoryUserId = $effectiveSignatory?->user?->id;

            if (! $signatoryUserId || $signatoryUserId === $actingUserId) {
                continue;
            }

            $offboardingRequestApprover->offboardingRequest->activities()->create([
                'user_id' => $actingUserId,
                'offboarding_request_approver_id' => $offboardingRequestApprover->id,
                'action' => 'checklist_item_cleared_by_other',
                'status' => $offboardingRequestApprover->offboardingRequest->status,
                'comment' => "\"{$item->title}\" — originally assigned to {$effectiveSignatory->name}.",
            ]);
        }
    }
}
