<?php

namespace App\Services;

use App\Mail\ChecklistItemApproverAssignedMail;
use App\Mail\ChecklistReadyForApprovalMail;
use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ChecklistApprovalNotifier
{
    /**
     * Attaches the given checklist templates to the request, creates one
     * `OffboardingRequestApprover` assignment per template that has a
     * department head (with its due date computed from the template's
     * `due_in_days`, if set), and emails each unique department head using
     * the given, already-resolved email template (the caller decides how to
     * find it — e.g. by a fixed name, or by a "default" flag). Shared by the
     * initial submission announcement and the later Final Pay Checklist
     * notification, since both are the same "attach + assign + notify"
     * operation applied to a different set of templates.
     *
     * Separately (and unconditionally, regardless of whether an admin-
     * configured $emailTemplate exists — this notification isn't CMS
     * content), every checklist item whose own signatory differs from its
     * template's department head is notified too: see
     * `notifyItemApprovers()`. Department heads keep receiving exactly the
     * announcement email they always have — this is purely additive.
     *
     * @param  Collection<int, ChecklistTemplate>  $templates
     * @return array<int, string> names of department heads actually emailed
     */
    public function attachAndNotify(OffboardingRequest $offboardingRequest, Collection $templates, ?EmailTemplate $emailTemplate, string $creatorName = ''): array
    {
        if ($templates->isEmpty()) {
            return [];
        }

        $offboardingRequest->checklistTemplates()->syncWithoutDetaching($templates->pluck('id'));

        // Keyed by department_head_id so the announcement email below can
        // fill "{{checklist_name}}"/"{{due_date}}" for that specific head's
        // template without a second query — a department head who's the
        // head of several templates in this same batch gets whichever one
        // was attached first.
        $assignmentByDepartmentHead = [];

        foreach ($templates as $template) {
            if (! $template->department_head_id) {
                continue;
            }

            $assignment = $offboardingRequest->approvers()->firstOrCreate(
                ['checklist_template_id' => $template->id],
                [
                    'employee_id' => $template->department_head_id,
                    'status' => 'pending',
                    'assigned_at' => now(),
                    'due_at' => $template->due_in_days ? now()->addDays($template->due_in_days) : null,
                ]
            );

            $assignmentByDepartmentHead[$template->department_head_id] ??= [
                'template' => $template,
                'assignment' => $assignment,
            ];
        }

        $this->notifyItemApprovers($offboardingRequest, $templates);

        if (! $emailTemplate) {
            return [];
        }

        $offboardee = $offboardingRequest->employee;
        $departmentHeads = $templates->pluck('departmentHead')->filter()->unique('id');
        $notified = [];

        foreach ($departmentHeads as $approver) {
            if (! $approver->email || ! filter_var($approver->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $context = $assignmentByDepartmentHead[$approver->id] ?? null;

            [$subject, $body] = $emailTemplate->render(
                $approver->name,
                $offboardee->name,
                $creatorName,
                $offboardee->employee_code,
                $context['template']?->title,
                $context['assignment']?->due_at?->format('M d, Y'),
            );

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

    /**
     * For every checklist item across the given templates whose signatory
     * is set and differs from that template's own department head, ensures
     * the signatory has a login account (creating one — username = password
     * = employee code, hashed via the model's cast — only if none exists
     * for their employee code) and sends exactly one email per approver
     * covering every item assigned to them across this batch of templates,
     * so an approver assigned to several items in the same request gets a
     * single consolidated email rather than one per item. New-account
     * credentials are included in the email only when the account didn't
     * already exist; an approver who already had an account never sees
     * credentials, per spec.
     *
     * @param  Collection<int, ChecklistTemplate>  $templates
     */
    private function notifyItemApprovers(OffboardingRequest $offboardingRequest, Collection $templates): void
    {
        $offboardee = $offboardingRequest->employee;

        /** @var array<int, array{employee: Employee, items: array<int, array{checklistTitle: string, itemTitle: string, dueAt: ?string}>}> $byApprover */
        $byApprover = [];

        foreach ($templates as $template) {
            $dueAt = $template->due_in_days ? now()->addDays($template->due_in_days)->format('M d, Y') : null;

            foreach ($template->items as $item) {
                /** @var ChecklistItem $item */
                if (! $item->signatory_id || $item->signatory_id === $template->department_head_id) {
                    continue;
                }

                $byApprover[$item->signatory_id]['employee'] ??= $item->signatory;
                $byApprover[$item->signatory_id]['items'][] = [
                    'checklistTitle' => $template->title,
                    'itemTitle' => $item->title,
                    'dueAt' => $dueAt,
                ];
            }
        }

        foreach ($byApprover as $entry) {
            $employee = $entry['employee'];

            if (! $employee) {
                continue;
            }

            $existingUser = User::firstWhere('username', $employee->employee_code);
            $user = $existingUser ?? User::findOrCreateApprover($employee);

            $credentials = $existingUser ? null : [
                'username' => $employee->employee_code,
                'password' => $employee->employee_code,
            ];

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
                    credentials: $credentials,
                ));
            } catch (\Throwable $e) {
                Log::error('Failed to send checklist item approver assignment email.', [
                    'offboarding_request_id' => $offboardingRequest->id,
                    'recipient' => $employee->email,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Emails this assignment's Department Head once every checklist item
     * has been checked — the "ready for your final review and approval"
     * notice. Called only from `ChecklistCompletionService::checkReadyForDepartmentHeadApproval()`,
     * which already guards this to fire exactly once per assignment.
     */
    public function notifyDepartmentHeadReady(OffboardingRequestApprover $assignment): void
    {
        $assignment->loadMissing(
            'employee',
            'checklistTemplate.items',
            'itemProgress.checkedBy',
            'offboardingRequest.employee'
        );

        $departmentHead = $assignment->employee;

        if (! $departmentHead || ! $departmentHead->email || ! filter_var($departmentHead->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $offboardee = $assignment->offboardingRequest->employee;
        $progressByItemId = $assignment->itemProgress->keyBy('checklist_item_id');

        $items = $assignment->checklistTemplate->items->map(function (ChecklistItem $item) use ($progressByItemId) {
            $progress = $progressByItemId->get($item->id);

            return [
                'title' => $item->title,
                'checkedByName' => $progress?->checkedBy?->name,
                'checkedAt' => $progress?->checked_at?->format('M d, Y g:i A'),
                'remark' => $progress?->remark,
            ];
        })->all();

        try {
            Mail::to($departmentHead->email)->send(new ChecklistReadyForApprovalMail(
                departmentHeadName: $departmentHead->name,
                offboardeeName: $offboardee->name,
                offboardeeEmployeeCode: $offboardee->employee_code,
                checklistTitle: $assignment->checklistTemplate->title,
                items: $items,
                approvalUrl: route('approvals.index'),
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send checklist-ready-for-approval email.', [
                'offboarding_request_approver_id' => $assignment->id,
                'recipient' => $departmentHead->email,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
