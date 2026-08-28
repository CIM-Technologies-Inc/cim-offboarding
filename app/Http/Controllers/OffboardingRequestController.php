<?php

namespace App\Http\Controllers;

use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\OffboardingRequest;
use App\Models\User;
use App\Services\ChecklistApprovalNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
