<?php

namespace App\Services;

use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use App\Models\EmailTemplate;
use App\Models\EmailTemplateSchedule;
use App\Models\Employee;
use App\Models\FinalApprover;
use App\Models\GeneralSignatory;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use App\Models\OffboardingRequestGeneralSignatory;
use App\Models\SeparationType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Computes exactly what submitting the New Offboarding Request form RIGHT
 * NOW would produce — which checklists/General Signatories would attach,
 * who their resolved signatories/task assignees are, what due dates they'd
 * get, who the live Final Approver is, and which Email Notification
 * Schedules would fire and when — all WITHOUT writing anything to the
 * database. Used by `OffboardingRequestController::preview()` to power the
 * "Review Offboarding Request" step shown before the real submission.
 *
 * Deliberately reuses the exact same formulas the real creation path uses
 * (`ChecklistApprovalNotifier::resolveChecklistSignatoryId()`/
 * `computeChecklistDueDates()`/`computeGeneralSignatoryDueAt()`, and
 * `EmailTemplateScheduleDispatcher::computeScheduledDate()`/
 * `resolveRecipients()`) rather than re-implementing the math here — see
 * each of those methods' own docblocks for why they were made reusable.
 * The Email Notification Schedule section runs the dispatcher's own logic
 * against an UNSAVED, in-memory "shadow" `OffboardingRequest` built from
 * this preview's own resolved checklists/General Signatories (see
 * `buildShadowRequest()`) — a Final Pay schedule naturally and correctly
 * previews as "pending" this way, since no Final Pay checklist can exist
 * yet for a request that hasn't even been created.
 */
class OffboardingRequestPreviewService
{
    public function __construct(
        private ChecklistApprovalNotifier $checklistApprovalNotifier,
        private EmailTemplateScheduleDispatcher $emailTemplateScheduleDispatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  The exact same validated array `OffboardingRequestController::storeValidationRules()` produces.
     * @param  array<int, int>  $temporarySignatoryOverrides  `[checklist_template_id => replacement_employee_id]`, already resolved via `resolveTemporarySignatoryOverrides()`.
     * @param  ?int  $immediateHeadId  The ALREADY-resolved Immediate Head id — `store()`'s own "fall back to the offboardee's Employee Master Group Head" logic, applied by the caller before this is reached, so the preview reflects the exact same value the real request would be created with.
     */
    public function preview(Employee $employee, array $validated, array $temporarySignatoryOverrides, ?int $immediateHeadId): array
    {
        $separationType = SeparationType::find($validated['separation_type_id']);
        $lastWorkingDay = Carbon::parse($validated['last_working_day']);
        $noticeDate = Carbon::parse($validated['notice_date']);
        $approvalMode = $validated['approval_mode'] ?? 'async';
        $immediateHead = $immediateHeadId ? Employee::find($immediateHeadId) : null;

        $checklists = $this->resolveChecklists($employee, $lastWorkingDay, $approvalMode, $temporarySignatoryOverrides, $immediateHeadId);
        $generalSignatories = $this->resolveGeneralSignatories($lastWorkingDay, $approvalMode);
        $finalApprover = FinalApprover::where('is_active', true)->with('employee')->first();

        $shadowRequest = $this->buildShadowRequest($employee, $lastWorkingDay, $checklists, $generalSignatories);
        $scheduledNotifications = $this->resolveScheduledNotifications($shadowRequest);

        $warnings = $this->buildWarnings($employee, $checklists, $generalSignatories, $finalApprover, $temporarySignatoryOverrides);

        return [
            'employee' => [
                'employee_number' => $employee->employee_code,
                'employee_name' => $employee->name,
                'department' => $employee->department,
                'position' => $employee->designation,
                'separation_type' => $separationType ? [
                    'title' => $separationType->title,
                    'description' => $separationType->description,
                    'default_notice_period_days' => $separationType->default_notice_period_days,
                ] : null,
                'request_created_at' => now()->format('M d, Y g:i A'),
                'notice_date' => $noticeDate->format('M d, Y'),
                'last_working_day' => $lastWorkingDay->format('M d, Y'),
                'reason' => $separationType?->title,
                'immediate_head' => $immediateHead ? [
                    'id' => $immediateHead->id,
                    'name' => $immediateHead->name,
                    'code' => $immediateHead->employee_code,
                ] : null,
                'approval_mode' => $approvalMode,
            ],
            'checklists' => $checklists,
            'general_signatories' => $generalSignatories,
            'final_approver' => $finalApprover?->employee ? [
                'id' => $finalApprover->employee->id,
                'name' => $finalApprover->employee->name,
                'code' => $finalApprover->employee->employee_code,
            ] : null,
            'scheduled_notifications' => $scheduledNotifications,
            'warnings' => $warnings,
        ];
    }

    /**
     * Mirrors `OffboardingRequestController::notifyDepartmentHeads()`'s own
     * checklist selection query exactly (`applicableToDepartment()` +
     * `is_final_pay_checklist=false`, narrowed to `sequence_type=primary`
     * only in Sync mode) — see that method for why. Applies the exact same
     * skip `ChecklistApprovalNotifier::attachAndNotify()` itself uses: a
     * template resolving to no signatory and not `use_task_assignee_as_signatory`
     * is omitted entirely, since it wouldn't actually attach.
     *
     * @param  array<int, int>  $signatoryOverrides
     */
    private function resolveChecklists(Employee $employee, Carbon $lastWorkingDay, string $approvalMode, array $signatoryOverrides, ?int $immediateHeadId): array
    {
        $templatesQuery = ChecklistTemplate::where('is_active', true)
            ->where('is_final_pay_checklist', false)
            ->applicableToDepartment($employee->department)
            ->with(['departmentHead', 'items.signatory']);

        $templates = $approvalMode === 'sync'
            ? (clone $templatesQuery)->where('sequence_type', ChecklistTemplate::SEQUENCE_TYPE_PRIMARY)->get()
            : $templatesQuery->get();

        // A bare, unsaved stand-in purely so `resolveChecklistSignatoryId()`
        // can read `->immediate_head_id` the exact same way it reads it off
        // a real `OffboardingRequest` — never persisted, never used for
        // anything else.
        $pseudoRequest = new OffboardingRequest();
        $pseudoRequest->immediate_head_id = $immediateHeadId;

        $result = [];

        foreach ($templates as $template) {
            $signatoryId = $this->checklistApprovalNotifier->resolveChecklistSignatoryId($template, $pseudoRequest, $signatoryOverrides);

            if (! $signatoryId && ! $template->use_task_assignee_as_signatory) {
                continue;
            }

            $dueDates = $this->checklistApprovalNotifier->computeChecklistDueDates($template, $lastWorkingDay);
            $signatory = $signatoryId ? Employee::find($signatoryId) : null;

            $taskAssignees = $template->items->map(fn (ChecklistItem $item) => [
                'item_title' => $item->title,
                'assignee_name' => $item->signatory?->name,
            ])->values()->all();

            $result[] = [
                'id' => $template->id,
                'title' => $template->title,
                'department' => $template->department,
                'is_immediate_head_checklist' => $template->is_immediate_head_checklist,
                'use_task_assignee_as_signatory' => $template->use_task_assignee_as_signatory,
                'sequence_type' => $template->sequence_type,
                'signatory' => $signatory ? [
                    'id' => $signatory->id,
                    'name' => $signatory->name,
                    'code' => $signatory->employee_code,
                ] : null,
                'is_temporary_override' => array_key_exists($template->id, $signatoryOverrides),
                'due_at' => $dueDates['due_at']?->format('M d, Y g:i A'),
                // Review-step display: the Original Due Date is always the
                // offboardee's Last Working Day; the Extended Due Date is
                // that plus this checklist's configured "Due Date Extension
                // (Days)" (`due_in_days`) — the same date `due_at` above
                // resolves to — or null (shown as "No extension applies")
                // when the checklist has no extension configured.
                'original_due_at' => $lastWorkingDay->copy()->endOfDay()->format('M d, Y g:i A'),
                'due_date_extension_days' => $template->due_in_days ?: null,
                'extended_due_at' => $template->due_in_days ? $dueDates['due_at']?->format('M d, Y g:i A') : null,
                'clearance_signing_due_at' => $dueDates['clearance_signing_due_at']?->format('M d, Y g:i A'),
                'task_assignees' => $taskAssignees,
            ];
        }

        return $result;
    }

    /**
     * Mirrors `notifyDepartmentHeads()`'s own General Signatory selection
     * exactly — company-wide (no department scoping), `sequence_type=primary`
     * only in Sync mode. The real query also excludes General Signatories
     * already attached to the request (`whereNotIn(..., $offboardingRequest->generalSignatories()->...)`)
     * — omitted here since a not-yet-created request can't have anything
     * attached yet, so there's nothing to exclude.
     */
    private function resolveGeneralSignatories(Carbon $lastWorkingDay, string $approvalMode): array
    {
        $query = GeneralSignatory::where('is_active', true)
            ->where('is_final_pay_signatory', false)
            ->with(['clearanceSignatory', 'tasks.signatory']);

        $generalSignatories = $approvalMode === 'sync'
            ? (clone $query)->where('sequence_type', ChecklistTemplate::SEQUENCE_TYPE_PRIMARY)->get()
            : $query->get();

        return $generalSignatories->map(function (GeneralSignatory $generalSignatory) use ($lastWorkingDay) {
            $dueAt = $this->checklistApprovalNotifier->computeGeneralSignatoryDueAt($generalSignatory, $lastWorkingDay);

            return [
                'id' => $generalSignatory->id,
                'sequence_type' => $generalSignatory->sequence_type,
                'clearance_signatory' => $generalSignatory->clearanceSignatory ? [
                    'id' => $generalSignatory->clearanceSignatory->id,
                    'name' => $generalSignatory->clearanceSignatory->name,
                    'code' => $generalSignatory->clearanceSignatory->employee_code,
                ] : null,
                'due_at' => $dueAt?->format('M d, Y g:i A'),
                'tasks' => $generalSignatory->tasks->map(fn ($task) => [
                    'title' => $task->title,
                    'signatory_name' => $task->signatory?->name,
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * An unsaved, in-memory stand-in for the `OffboardingRequest` this form
     * would create, carrying unsaved `OffboardingRequestApprover`/
     * `OffboardingRequestGeneralSignatory` rows for every checklist/General
     * Signatory resolved above — fed straight into
     * `EmailTemplateScheduleDispatcher::computeScheduledDate()`/
     * `resolveRecipients()`, which only ever read these as plain loaded
     * relation collections (confirmed never via the `->approvers()` query-
     * builder relation, which would require a real id). `created_at` is set
     * via direct property assignment, not the constructor array — it is
     * NOT in `OffboardingRequest::$fillable`, so a constructor array would
     * silently drop it.
     */
    private function buildShadowRequest(Employee $employee, Carbon $lastWorkingDay, array $checklists, array $generalSignatories): OffboardingRequest
    {
        $shadowRequest = new OffboardingRequest(['last_working_day' => $lastWorkingDay]);
        $shadowRequest->created_at = now();
        $shadowRequest->setRelation('employee', $employee);

        $shadowApprovers = collect($checklists)->map(function (array $checklist) {
            $approver = new OffboardingRequestApprover([
                'checklist_template_id' => $checklist['id'],
                'employee_id' => $checklist['signatory']['id'] ?? null,
            ]);
            $approver->due_at = $checklist['due_at'] ? Carbon::parse($checklist['due_at']) : null;

            return $approver;
        });
        $shadowRequest->setRelation('approvers', $shadowApprovers);

        $shadowGsApprovals = collect($generalSignatories)->map(function (array $generalSignatory) {
            return new OffboardingRequestGeneralSignatory([
                'general_signatory_id' => $generalSignatory['id'],
            ]);
        });
        $shadowRequest->setRelation('generalSignatoryApprovals', $shadowGsApprovals);

        return $shadowRequest;
    }

    private function resolveScheduledNotifications(OffboardingRequest $shadowRequest): array
    {
        $schedules = EmailTemplateSchedule::where('is_active', true)
            ->whereHas('emailTemplate', fn ($q) => $q->where('is_active', true))
            ->with('emailTemplate', 'checklistTemplates:id', 'generalSignatories:id')
            ->get();

        return $schedules->flatMap(function (EmailTemplateSchedule $schedule) use ($shadowRequest) {
            if ($schedule->event !== 'clearance') {
                return [$this->scheduledNotificationRow($schedule, $shadowRequest, null, null)];
            }

            // Clearance Due Date: one row per checklist deadline group (each
            // checklist's own Clearance Signing Deadline), exactly as the
            // real scheduler will send them.
            $groups = $this->emailTemplateScheduleDispatcher->clearanceDeadlineGroups($schedule, $shadowRequest);

            if ($groups === []) {
                return [$this->scheduledNotificationRow($schedule, $shadowRequest, null, [])];
            }

            return collect($groups)->map(fn (array $ids, int $days) => $this->scheduledNotificationRow($schedule, $shadowRequest, $days, $ids))->values()->all();
        })->values()->all();
    }

    /**
     * @param  array<int, int>|null  $checklistTemplateIds  null = not a Clearance Due Date row; [] = Clearance Due Date with no checklist deadline configured.
     * @return array<string, mixed>
     */
    private function scheduledNotificationRow(EmailTemplateSchedule $schedule, OffboardingRequest $shadowRequest, ?int $clearanceDeadlineDays, ?array $checklistTemplateIds): array
    {
        $scheduledDate = $this->emailTemplateScheduleDispatcher->computeScheduledDate($schedule, $shadowRequest, $clearanceDeadlineDays);
        $recipients = $this->emailTemplateScheduleDispatcher->resolveRecipients($schedule, $shadowRequest, $checklistTemplateIds);
        $emailTemplate = $schedule->emailTemplate;

        return [
            'template_name' => $emailTemplate?->template_name,
            'event' => $schedule->event,
            'event_label' => EmailTemplateSchedule::EVENT_LABELS[$schedule->event] ?? $schedule->event,
            'timing' => $schedule->timing,
            'days' => $schedule->days,
            'clearance_signing_deadline_days' => $clearanceDeadlineDays,
            'checklist_titles' => $clearanceDeadlineDays !== null
                ? $shadowRequest->approvers->whereIn('checklist_template_id', $checklistTemplateIds)->map(fn ($approver) => $approver->checklistTemplate?->title)->filter()->unique()->implode(', ')
                : null,
            'scheduled_date' => $scheduledDate?->format('M d, Y'),
            'is_pending' => $scheduledDate === null,
            'channel' => $schedule->channel,
            'recipients' => $recipients->map(fn (Employee $recipient) => [
                'name' => $recipient->name,
                'email' => $recipient->email,
            ])->values()->all(),
            'cc_recipients' => $emailTemplate ? $emailTemplate->ccRecipientNames() : [],
        ];
    }

    /**
     * @param  array<int, int>  $temporarySignatoryOverrides
     */
    private function buildWarnings(Employee $employee, array $checklists, array $generalSignatories, ?FinalApprover $finalApprover, array $temporarySignatoryOverrides): array
    {
        $warnings = [];

        if (! $finalApprover) {
            $warnings[] = [
                'message' => 'No active Final Approver is configured. Please set one on the Offboarding Checklist page before creating this offboarding request.',
                'critical' => true,
            ];
        }

        // Same conflict check `store()` itself re-runs and rejects on —
        // surfaced here too so the review screen catches it before the
        // admin ever reaches Submit, not just after.
        $unresolvedConflicts = OffboardingRequestApprover::activeConflictsForEmployee($employee->id)
            ->reject(fn (array $conflict) => array_key_exists($conflict['template']->id, $temporarySignatoryOverrides));

        if ($unresolvedConflicts->isNotEmpty()) {
            $checklistTitles = $unresolvedConflicts->pluck('template.title')->unique()->implode(', ');

            $warnings[] = [
                'message' => "{$employee->name} is currently the Clearance Signatory of record on the following checklist(s): {$checklistTitles}. Please assign a replacement Clearance Signatory (Permanent or Temporary) for each before submitting this offboarding request.",
                'critical' => true,
            ];
        }

        if (empty($checklists) && empty($generalSignatories)) {
            $warnings[] = [
                'message' => 'No applicable checklists or General Signatories were found for this employee\'s department. This offboarding request would be created with no assigned approvers.',
                'critical' => false,
            ];
        }

        foreach ($checklists as $checklist) {
            if (! $checklist['signatory'] && ! $checklist['use_task_assignee_as_signatory']) {
                // Can't actually happen — resolveChecklists() already omits
                // this case — kept as a defensive, visible safety net in
                // case that invariant is ever changed without updating here.
                $warnings[] = [
                    'message' => "\"{$checklist['title']}\" has no Clearance Signatory configured.",
                    'critical' => false,
                ];
            }
        }

        foreach ($generalSignatories as $generalSignatory) {
            if (! $generalSignatory['clearance_signatory']) {
                $warnings[] = [
                    'message' => 'A General Signatory tier has no Clearance Signatory configured and will not be notified.',
                    'critical' => false,
                ];
            }
        }

        return $warnings;
    }
}
