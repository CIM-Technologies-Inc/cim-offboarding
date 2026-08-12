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
            'notice_date' => ['required', 'date'],
            'last_working_day' => ['required', 'date', 'after_or_equal:notice_date'],
            'resignation_type' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'in:resignation,termination,retirement,layoff,other'],
            'notice_period' => ['required', 'in:Immediate,15 Days,30 Days,60 Days,90 Days'],
            'approval_mode' => ['required', 'in:sync,async'],
        ]);

        $offboardingRequest = OffboardingRequest::create($validated + ['status' => 'pending']);

        $employee = Employee::findOrFail($validated['employee_id']);
        $employee->update(['status' => 'offboarding']);

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
     * Attach every active, non-final-pay checklist template to this request
     * (offboarding clearance spans every department, not just the
     * offboardee's own — Final Pay is a separate, later stage handled by
     * ApprovalController once these are all approved) and email each
     * attached template's department head an announcement, using whichever
     * active template is flagged as the default Offboarding Announcement
     * template — no manual template selection required.
     */
    private function notifyDepartmentHeads(OffboardingRequest $offboardingRequest, User $creator): void
    {
        $templates = ChecklistTemplate::where('is_active', true)
            ->where('is_final_pay_checklist', false)
            ->with('departmentHead')
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
