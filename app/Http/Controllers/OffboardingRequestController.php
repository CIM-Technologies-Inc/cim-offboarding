<?php

namespace App\Http\Controllers;

use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\ChecklistTemplate;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\OffboardingRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OffboardingRequestController extends Controller
{
    /**
     * Fallback email template used for department-head notifications when the
     * request didn't explicitly pick one via the "Email Template" selector.
     */
    private const APPROVER_ANNOUNCEMENT_TEMPLATE = 'Offboarding Announcement';

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
            'email_template_id' => ['nullable', 'exists:email_templates,id'],
        ]);

        $offboardingRequest = OffboardingRequest::create($validated + ['status' => 'pending']);

        $employee = Employee::findOrFail($validated['employee_id']);
        $employee->update(['status' => 'offboarding']);

        try {
            $this->notifyDepartmentHeads($offboardingRequest, $employee, $request->user());
        } catch (\Throwable $e) {
            Log::error('Failed to process offboarding approver notifications.', [
                'offboarding_request_id' => $offboardingRequest->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return back()->with('success', 'Offboarding request submitted.');
    }

    /**
     * Attach every active checklist template to this request (offboarding
     * clearance spans every department, not just the offboardee's own),
     * excluding templates flagged as the Final Pay Checklist, and email each
     * attached template's department head an announcement, deduped by email
     * address.
     */
    private function notifyDepartmentHeads(OffboardingRequest $offboardingRequest, Employee $offboardee, User $creator): void
    {
        $templates = ChecklistTemplate::where('is_active', true)
            ->where('is_final_pay_checklist', false)
            ->with('departmentHead')
            ->get();

        if ($templates->isEmpty()) {
            return;
        }

        $offboardingRequest->checklistTemplates()->syncWithoutDetaching($templates->pluck('id'));

        foreach ($templates as $template) {
            if (! $template->department_head_id) {
                continue;
            }

            $offboardingRequest->approvers()->firstOrCreate(
                ['checklist_template_id' => $template->id],
                [
                    'employee_id' => $template->department_head_id,
                    'status' => 'pending',
                    'assigned_at' => now(),
                ]
            );
        }

        $emailTemplate = $offboardingRequest->emailTemplate
            ?? EmailTemplate::where('is_active', true)
                ->where('template_name', self::APPROVER_ANNOUNCEMENT_TEMPLATE)
                ->latest('updated_at')
                ->first();

        if (! $emailTemplate) {
            return;
        }

        $approvers = $templates->pluck('departmentHead')->filter()->unique('email');

        foreach ($approvers as $approver) {
            if (! $approver->email || ! filter_var($approver->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            [$subject, $body] = $emailTemplate->render($approver->name, $offboardee->name, $creator->name);

            try {
                Mail::to($approver->email)->send(new ChecklistSignatoryAnnouncementMail($subject, $body));
            } catch (\Throwable $e) {
                Log::error('Failed to send offboarding announcement email.', [
                    'offboarding_request_id' => $offboardingRequest->id,
                    'recipient' => $approver->email,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }
}
