<?php

namespace App\Http\Controllers;

use App\Models\ChecklistDelegation;
use App\Models\ChecklistItemProgress;
use App\Models\Employee;
use App\Models\OffboardingRequestApprover;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
     * Saves per-item checked state and remarks. Reachable by the delegate
     * (doing the actual work) or the primary approver (making corrections
     * before final approval) — never by an unrelated approver.
     */
    public function saveProgress(Request $request, OffboardingRequestApprover $offboardingRequestApprover): RedirectResponse
    {
        $this->authorizeDelegateOrPrimaryApprover($offboardingRequestApprover);

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

        ChecklistItemProgress::syncForAssignment($offboardingRequestApprover, $validated['items'] ?? [], auth()->id());

        if ($offboardingRequestApprover->isDelegated() && $offboardingRequestApprover->delegation_status === 'assigned') {
            $offboardingRequestApprover->update(['delegation_status' => 'in_progress']);
        }

        return back()->with('success', 'Checklist progress saved.');
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
     * The delegate (doing the work) or the primary approver (reviewing /
     * correcting) may save item progress — never an unrelated approver.
     */
    private function authorizeDelegateOrPrimaryApprover(OffboardingRequestApprover $offboardingRequestApprover): void
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return;
        }

        $employeeId = $user->employee?->id;

        abort_unless(
            $employeeId !== null
                && ($offboardingRequestApprover->employee_id === $employeeId
                    || $offboardingRequestApprover->delegated_employee_id === $employeeId),
            403
        );
    }
}
