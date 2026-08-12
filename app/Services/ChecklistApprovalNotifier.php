<?php

namespace App\Services;

use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\ChecklistTemplate;
use App\Models\EmailTemplate;
use App\Models\OffboardingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ChecklistApprovalNotifier
{
    /**
     * Attaches the given checklist templates to the request, creates one
     * `OffboardingRequestApprover` assignment per template that has a
     * department head, and emails each unique department head using the
     * given, already-resolved email template (the caller decides how to
     * find it — e.g. by a fixed name, or by a "default" flag). Shared by
     * the initial submission announcement and the later Final Pay Checklist
     * notification, since both are the same "attach + assign + notify"
     * operation applied to a different set of templates.
     *
     * @param  Collection<int, ChecklistTemplate>  $templates
     * @return array<int, string> names of approvers actually emailed
     */
    public function attachAndNotify(OffboardingRequest $offboardingRequest, Collection $templates, ?EmailTemplate $emailTemplate, string $creatorName = ''): array
    {
        if ($templates->isEmpty()) {
            return [];
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

        if (! $emailTemplate) {
            return [];
        }

        $offboardee = $offboardingRequest->employee;
        $approvers = $templates->pluck('departmentHead')->filter()->unique('email');
        $notified = [];

        foreach ($approvers as $approver) {
            if (! $approver->email || ! filter_var($approver->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            [$subject, $body] = $emailTemplate->render($approver->name, $offboardee->name, $creatorName);

            try {
                Mail::to($approver->email)->send(new ChecklistSignatoryAnnouncementMail($subject, $body));
                $notified[] = $approver->name;
            } catch (\Throwable $e) {
                Log::error('Failed to send checklist approval notification email.', [
                    'offboarding_request_id' => $offboardingRequest->id,
                    'email_template' => $emailTemplate->template_name,
                    'recipient' => $approver->email,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $notified;
    }
}
