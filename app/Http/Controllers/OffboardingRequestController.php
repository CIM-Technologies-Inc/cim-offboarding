<?php

namespace App\Http\Controllers;

use App\Models\ChecklistTemplate;
use App\Models\EmailTemplate;
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
        ]);

        // No longer a user-facing choice on the New Offboarding Request form
        // — every request is created async, same as the form's own prior
        // default. Kept as a stored value (rather than dropping the column)
        // since ApprovalController still reads it for the Approvals page's
        // "Approval Mode" display.
        $offboardingRequest = OffboardingRequest::create($validated + ['status' => 'pending', 'approval_mode' => 'async']);

        $employee = Employee::findOrFail($validated['employee_id']);
        $employee->update(['status' => 'offboarding']);

        // The Immediate Head is an additional authorized signatory on the
        // Clearance Form, outside the checklist approval workflow — they
        // still need a login account to access whatever offboarding/
        // clearance functions they're granted, same convention as any other
        // approver account (username/password = employee_code).
        if (! empty($validated['immediate_head_id'])) {
            User::findOrCreateApprover(Employee::findOrFail($validated['immediate_head_id']));
        }

        $successMessage = 'Offboarding request submitted.';

        try {
            if (! EmailTemplate::activeDefaultAnnouncement()) {
                $successMessage .= ' Warning: no active default Offboarding Announcement template is set, so approvers were not emailed — set one on the Email Templates page.';
            }

            $this->notifyDepartmentHeads($offboardingRequest, $request->user());
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
     * handled by ApprovalController once these are all approved) — and
     * email each attached template's department head an announcement,
     * using whichever active template is flagged as the default
     * Offboarding Announcement template — no manual template selection
     * required.
     */
    private function notifyDepartmentHeads(OffboardingRequest $offboardingRequest, User $creator): void
    {
        $templates = ChecklistTemplate::where('is_active', true)
            ->where('is_final_pay_checklist', false)
            ->applicableToDepartment($offboardingRequest->employee->department)
            ->with(['departmentHead', 'items.signatory'])
            ->get();

        $emailTemplate = EmailTemplate::activeDefaultAnnouncement();

        if (! $emailTemplate) {
            Log::warning('No active default Offboarding Announcement email template configured — approvers were not emailed.', [
                'offboarding_request_id' => $offboardingRequest->id,
            ]);
        }

        app(ChecklistApprovalNotifier::class)->attachAndNotify(
            $offboardingRequest,
            $templates,
            $emailTemplate,
            $creator->name
        );
    }
}
