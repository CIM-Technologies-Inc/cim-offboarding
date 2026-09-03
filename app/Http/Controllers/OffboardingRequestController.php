<?php

namespace App\Http\Controllers;

use App\Models\ChecklistItemScheduledSend;
use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\FinalApprover;
use App\Models\OffboardingRequest;
use App\Models\User;
use App\Services\ChecklistApprovalNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OffboardingRequestController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'immediate_head_id' => ['nullable', 'exists:employees,id', 'different:employee_id'],
            'notice_date' => ['required', 'date'],
            'last_working_day' => ['required', 'date', 'after_or_equal:notice_date'],
            'resignation_type' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'in:resignation,termination,retirement,layoff,other'],
            // Per-request overrides for the 3 fixed-name email templates
            // that fire at creation time — see the matching Select fields
            // on the New Offboarding Request modal, and
            // `ChecklistApprovalNotifier`'s use of these once stored.
            'approver_notification_template_id' => ['nullable', 'exists:email_templates,id'],
            'offboardee_notification_template_id' => ['nullable', 'exists:email_templates,id'],
            'general_signatory_notification_template_id' => ['nullable', 'exists:email_templates,id'],
        ]);

        // Every request must have a real, currently-active Final Approver
        // configured system-wide — checked here so a misconfigured system
        // fails loudly at creation time rather than silently producing a
        // Clearance Form with a blank final signatory later. The resolved
        // row's `employee_id` is snapshotted below purely as a HISTORICAL
        // record of who was active at creation time; the Clearance Form
        // itself never reads that snapshot — it always resolves whichever
        // `FinalApprover` is active live, at render time (see
        // `ClearanceFormController::buildData()`), so this same check
        // passing here is not what makes a later Clearance Form correct —
        // it's a defensive guard against the system ever having zero
        // active Final Approvers at all.
        $activeFinalApprover = FinalApprover::where('is_active', true)->first();

        if (! $activeFinalApprover) {
            return back()->withErrors([
                'final_approver' => 'No active Final Approver is configured. Please set one on the Offboarding Checklist page before creating an offboarding request.',
            ])->withInput();
        }

        $employee = Employee::findOrFail($validated['employee_id']);

        // Fallback: if the admin didn't manually pick an Immediate Head,
        // use the offboardee's Employee Master Group Head instead — the
        // same "group's registered head" concept `Employee::departmentHead()`
        // already uses for checklist template department heads, applied
        // here to the per-request Immediate Head field. A manually-picked
        // Immediate Head is never overridden (this only runs when the field
        // came in empty), and an employee with no group (or a group with no
        // registered head) simply gets no fallback, same as today.
        if (empty($validated['immediate_head_id'])) {
            $groupHead = $employee->employeeGroup?->groupHead;

            if ($groupHead && $groupHead->id !== $employee->id) {
                $validated['immediate_head_id'] = $groupHead->id;
            }
        }

        // No longer a user-facing choice on the New Offboarding Request form
        // — every request is created async, same as the form's own prior
        // default. Kept as a stored value (rather than dropping the column)
        // since ApprovalController still reads it for the Approvals page's
        // "Approval Mode" display.
        $offboardingRequest = OffboardingRequest::create($validated + [
            'status' => 'pending',
            'approval_mode' => 'async',
            'created_by' => $request->user()->id,
            'final_approver_employee_id' => $activeFinalApprover->employee_id,
        ]);

        $employee->update(['status' => 'offboarding']);

        // Every offboardee gets their own login the moment their request is
        // created, so they can track their own process from day one — see
        // `ChecklistApprovalNotifier::notifyOffboardee()` below, which emails
        // these exact credentials only when the account is genuinely new.
        // Never promotes/downgrades an existing account's role (see
        // `User::findOrCreateEmployee()`), so an employee who already has an
        // account (e.g. as someone else's approver) keeps that role and
        // simply never receives credentials in this email.
        $existingEmployeeUser = User::firstWhere('username', $employee->employee_code_digits);
        $employeeUser = $existingEmployeeUser ?? User::findOrCreateEmployee($employee);
        $isNewEmployeeAccount = $existingEmployeeUser === null;

        // The Immediate Head is an additional authorized signatory on the
        // Clearance Form, outside the checklist approval workflow — they
        // still need a login account to access whatever offboarding/
        // clearance functions they're granted, same convention as any other
        // approver account (username/password = employee_code_digits).
        if (! empty($validated['immediate_head_id'])) {
            User::findOrCreateApprover(Employee::findOrFail($validated['immediate_head_id']));
        }

        $successMessage = 'Offboarding request submitted.';

        try {
            $this->notifyDepartmentHeads($offboardingRequest, $request->user(), $employeeUser, $isNewEmployeeAccount);
        } catch (\Throwable $e) {
            Log::error('Failed to process offboarding approver notifications.', [
                'offboarding_request_id' => $offboardingRequest->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return back()->with('success', $successMessage);
    }

    /**
     * Wipes every checklist/approval progress artifact this offboarding
     * request has accumulated and reinitializes it exactly the way `store()`
     * initializes a brand-new one — same `notifyDepartmentHeads()` call,
     * against whatever checklist templates/General Signatories are active
     * and applicable RIGHT NOW (not whatever was attached originally), so a
     * template added/retired since the original submission is correctly
     * reflected. The request row itself (id, employee, notice/last-working-
     * day dates, reason, immediate head, `created_at`/`created_by`) is left
     * untouched — this restarts the WORKFLOW, not the request's own
     * identity/history — and nothing here ever touches `Employee` fields
     * beyond the same `status` flip `store()` already makes. Gated by the
     * `offboarding-requests.reset` permission at the route level (Admin-only
     * by default), so only an authorized admin ever reaches this action,
     * including via a direct request.
     *
     * `completed` is a final, locked state — enforced HERE, not just by
     * hiding the button in the view, so a direct POST/API call against a
     * completed request is rejected exactly the same way regardless of how
     * it was triggered. Checked against the real `status` column (never
     * `displayStatus()`, which can read "overdue"/"in_progress" for other
     * reasons but only ever reflects `completed` when the column itself
     * genuinely is).
     */
    public function reset(Request $request, OffboardingRequest $offboardingRequest): RedirectResponse
    {
        abort_if(
            $offboardingRequest->status === 'completed',
            422,
            'This offboarding request is already completed and cannot be reset.'
        );

        // Re-resolved fresh purely to refresh the historical snapshot
        // column (see `OffboardingRequest::finalApproverEmployee()`'s own
        // docblock) and to re-run the same defensive "must have one active"
        // guard `store()` applies — the Clearance Form itself never reads
        // this snapshot regardless, always resolving the live active
        // `FinalApprover` at render time, so this re-resolution doesn't
        // change anything about what a Clearance Form displays.
        $activeFinalApprover = FinalApprover::where('is_active', true)->first();

        abort_if(
            ! $activeFinalApprover,
            422,
            'No active Final Approver is configured. Please set one on the Offboarding Checklist page before resetting this offboarding request.'
        );

        $offboardingRequest->loadMissing('employee');
        $employee = $offboardingRequest->employee;
        $admin = $request->user();

        DB::transaction(function () use ($offboardingRequest, $employee, $admin, $activeFinalApprover) {
            // Deleting the approver rows cascades (DB-enforced) to every
            // per-checklist artifact keyed off them: item progress, holds,
            // delegations, item reassignments, approval tokens, and pool
            // members — see the matching `cascadeOnDelete()` migrations.
            $offboardingRequest->approvers()->delete();

            // Same cascade shape for the General Signatory track: deleting
            // these rows also removes their approval tokens.
            $offboardingRequest->generalSignatoryApprovals()->delete();

            $offboardingRequest->followUps()->delete();
            $offboardingRequest->scheduledEmailSends()->delete();
            ChecklistItemScheduledSend::where('offboarding_request_id', $offboardingRequest->id)->delete();

            // Clears the checklist-templates pivot entirely rather than
            // leaving stale entries for a template that's since been
            // deactivated/retired — `notifyDepartmentHeads()` below re-syncs
            // it from scratch against whatever is active right now.
            $offboardingRequest->checklistTemplates()->detach();

            $offboardingRequest->activities()->delete();

            $offboardingRequest->update([
                'status' => 'pending',
                'completed_at' => null,
                'final_pay_notified_at' => null,
                'remarks' => null,
                'final_approver_employee_id' => $activeFinalApprover->employee_id,
            ]);

            $employee->update(['status' => 'offboarding']);

            // The one thing that survives the wipe above — an explicit,
            // dated audit record of who reset this request and when, so
            // "the process restarted from scratch" is never silent even
            // though every prior progress/activity row is now gone.
            $offboardingRequest->activities()->create([
                'user_id' => $admin->id,
                'action' => 'offboarding_reset',
                'status' => 'pending',
                'comment' => "All checklist and approval progress was cleared by {$admin->name}; the offboarding process was restarted from the beginning.",
            ]);
        });

        $offboardingRequest->refresh();

        // Identical account-provisioning shape to `store()` — idempotent,
        // never promotes/downgrades an existing account, so re-running this
        // on reset never touches the offboardee's (or immediate head's)
        // existing login/role.
        $existingEmployeeUser = User::firstWhere('username', $employee->employee_code_digits);
        $employeeUser = $existingEmployeeUser ?? User::findOrCreateEmployee($employee);
        $isNewEmployeeAccount = $existingEmployeeUser === null;

        if (! empty($offboardingRequest->immediate_head_id)) {
            User::findOrCreateApprover(Employee::findOrFail($offboardingRequest->immediate_head_id));
        }

        try {
            $this->notifyDepartmentHeads($offboardingRequest, $admin, $employeeUser, $isNewEmployeeAccount);
        } catch (\Throwable $e) {
            Log::error('Failed to process offboarding approver notifications after reset.', [
                'offboarding_request_id' => $offboardingRequest->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return back()->with('success', "Offboarding request for {$employee->name} has been reset and restarted.");
    }

    /**
     * Attach every active, non-final-pay checklist template that applies to
     * this employee's department to this request — a template with no
     * `department` set applies to everyone as before; one WITH a
     * `department` set only attaches when it exactly matches the
     * offboardee's own `department` (Final Pay is a separate, later stage
     * handled by ApprovalController once these are all approved). The
     * dedicated "Offboarding Request Notification" email (see
     * `ChecklistApprovalNotifier::notifyDepartmentHeadsOfNewRequest()`) is
     * what actually informs each Department Head — the older, freely
     * admin-editable "default Offboarding Announcement" template is
     * deliberately NOT used here (passing `null` skips that email loop
     * entirely inside `attachAndNotify()`), since it's redundant with the
     * dedicated notification and no longer wanted on this trigger.
     *
     * An Immediate Head checklist (see `ChecklistApprovalNotifier::attachAndNotify()`)
     * attaches and notifies right alongside every other template here —
     * it is NOT a prerequisite for the rest. Every assigned Department Head
     * and signatory gets access to, and is notified about, their own
     * checklist immediately, independent of whether/when the Immediate Head
     * completes theirs. The Immediate Head checklist still only ever
     * assigns to that specific request's Immediate Head, and still requires
     * every item checked before it can be submitted (see
     * `OffboardingRequestApprover::requiresAllItemsCompletedBeforeApproval()`)
     * — none of that changes, only the "wait for it first" sequencing does.
     */
    private function notifyDepartmentHeads(OffboardingRequest $offboardingRequest, User $creator, User $employeeUser, bool $isNewEmployeeAccount): void
    {
        $templates = ChecklistTemplate::where('is_active', true)
            ->where('is_final_pay_checklist', false)
            ->applicableToDepartment($offboardingRequest->employee->department)
            ->with(['departmentHead', 'items.signatory'])
            ->get();

        app(ChecklistApprovalNotifier::class)->notifyDepartmentHeadsOfNewRequest($offboardingRequest, $templates);

        app(ChecklistApprovalNotifier::class)->attachAndNotify(
            $offboardingRequest,
            $templates,
            null,
            $creator->name
        );

        // Sent last so the checklist summary reflects the templates just
        // attached above (with their resolved due dates/signatories),
        // rather than the bare template list.
        app(ChecklistApprovalNotifier::class)->notifyOffboardee(
            $offboardingRequest,
            $employeeUser,
            $isNewEmployeeAccount,
            $isNewEmployeeAccount ? $offboardingRequest->employee->employee_code_digits : null,
        );

        // General Signatories are a completely independent, checklist-free
        // workflow (see `GeneralSignatory`'s own docblock) — every active
        // one is snapshotted onto this request and notified regardless of
        // department, Immediate Head, or any of the checklist logic above.
        app(ChecklistApprovalNotifier::class)->notifyGeneralSignatories($offboardingRequest);
    }
}
