<?php

namespace App\Services;

use App\Mail\ChecklistGroupApprovedMail;
use App\Mail\ChecklistItemApproverAssignedMail;
use App\Mail\ChecklistReadyForApprovalMail;
use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\ChecklistApprovalToken;
use App\Models\ChecklistFollowUp;
use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\EmployeeGroup;
use App\Models\GeneralSignatory;
use App\Models\GeneralSignatoryApprovalToken;
use App\Models\GeneralSignatoryTask;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use App\Models\OffboardingRequestGeneralSignatory;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ChecklistApprovalNotifier
{
    /**
     * Fixed-name email template for the dedicated "a new offboarding
     * request needs your attention" notice sent to every Department Head —
     * independent of whatever template the admin may have flagged as the
     * default Offboarding Announcement (see `attachAndNotify()`'s own
     * `$emailTemplate` param), so this specific, structured notice always
     * goes out regardless of that separate, optional setting.
     */
    private const REQUEST_NOTIFICATION_TEMPLATE = 'Offboarding Request Notification';

    /**
     * Fixed-name email template for the "your offboarding process has
     * started" notice sent to the offboardee themselves — see
     * `notifyOffboardee()`.
     */
    private const OFFBOARDEE_NOTIFICATION_TEMPLATE = 'Offboarding Details Notification – Employee';

    /**
     * Fixed-name email template for the employee-initiated "I'm following up
     * on this pending checklist" notice sent to the offboarding request's
     * creator — see `notifyFollowUp()`.
     */
    private const FOLLOW_UP_TEMPLATE = 'Employee Offboarding Follow-Up Notification';

    /**
     * Fixed-name email template for the "you're a Clearance Signatory on
     * this offboarding request" notice sent to each General Signatory —
     * see `notifyGeneralSignatories()`. Deliberately its own dedicated
     * template rather than reusing "Offboarding Checklist Assigned to You"
     * (that one is a hardcoded Mailable tied to checklist-item semantics —
     * assigned items, due dates — none of which apply to a General
     * Signatory, who has no checklist). A General Signatory IS still a
     * Clearance Signatory/Approver though: this template's rendered body
     * includes a one-click "{{approve_button}}" (see
     * `buildApproveButtonHtml()`) that clears their assignment the same way
     * Submit on the Approvals page does — see
     * `GeneralSignatoryApprovalController`.
     */
    private const GENERAL_SIGNATORY_NOTIFICATION_TEMPLATE = 'General Signatory Offboarding Notification';

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
        $assignmentByTemplateId = [];

        // Employee ids for whom `autoAssignGroupSignatories()` just created
        // a brand-new login account below. By the time `notifyItemApprovers()`
        // runs right after this loop, that account already exists — so its
        // own "does this approver already have an account" check would
        // otherwise wrongly treat it as pre-existing and never surface the
        // new credentials. This set is how it's told the truth.
        $newlyCreatedAccountEmployeeIds = [];

        foreach ($templates as $template) {
            // An Immediate Head checklist has no department head of its own —
            // it's assigned exclusively to whichever Immediate Head was
            // picked on THIS specific offboarding request, frozen at
            // creation time exactly like every other assignment here. If the
            // request has no Immediate Head selected, the checklist stays
            // attached (recorded above) but unassigned to anyone — same as
            // today's behavior for a normal checklist with no department
            // head configured.
            $approverEmployeeId = $template->is_immediate_head_checklist
                ? $offboardingRequest->immediate_head_id
                : $template->department_head_id;

            // A "Use Task Assignee as Clearance Signatory" checklist has NO
            // owner at all by design — its Task Assignees are themselves the
            // signatories (see `ChecklistCompletionService::autoApproveIfHeadless()`,
            // which auto-approves this row once every item is done, since
            // there's nobody to click Submit). Every OTHER kind still skips
            // creating a row entirely when it resolves to no owner — that
            // behavior is unchanged.
            if (! $approverEmployeeId && ! $template->use_task_assignee_as_signatory) {
                continue;
            }

            $dueAtBasisDate = $offboardingRequest->last_working_day;

            // A template with no `due_in_days` configured at all (several
            // fixed-name templates ship without one) still gets a due
            // date — it defaults to the offboardee's own Last Working Day
            // itself (i.e. +0 days), never left blank, so every checklist
            // always has SOME due date to be checked against/extended.
            $assignment = $offboardingRequest->approvers()->firstOrCreate(
                ['checklist_template_id' => $template->id],
                [
                    'employee_id' => $approverEmployeeId,
                    'status' => 'pending',
                    'assigned_at' => now(),
                    // ->endOfDay() (23:59:59) — a checklist is due UNTIL
                    // THE END of its due date, never the very start of it
                    // (see the Offboarding Status/Timeline tabs' own "Until
                    // End of the Day" label, and `OffboardingRequestApprover::canExtendDue()`,
                    // which compares `now()` against this exact value to
                    // decide overdue status). Without this, a checklist
                    // would already read as overdue the instant its due
                    // date's calendar day BEGAN, a full day earlier than
                    // that label promises.
                    'due_at' => $dueAtBasisDate
                        ? $dueAtBasisDate->copy()->addDays($template->due_in_days ?? 0)->endOfDay()
                        : null,
                    // Clearance Signing Due Date — a separate, independent
                    // deadline from `due_at` above (see
                    // `ChecklistTemplate.clearance_signing_deadline_days`'s
                    // own migration/docblock). Unlike `due_at`, a template
                    // with none configured gets no Clearance Signing Due
                    // Date at all (null) rather than defaulting to the Last
                    // Working Day itself — this field is purely additive,
                    // not something every checklist is assumed to need.
                    'clearance_signing_due_at' => ($dueAtBasisDate && $template->clearance_signing_deadline_days !== null)
                        ? $dueAtBasisDate->copy()->addDays($template->clearance_signing_deadline_days)->endOfDay()
                        : null,
                ]
            );

            // A headless (Task-Assignee-as-Signatory) template has no
            // department head to key this by — it's never looked up below
            // anyway, since `$departmentHeads` (built further down from each
            // template's own `departmentHead`/`immediateHead` relation) never
            // contains anyone for a template with no owner.
            if ($approverEmployeeId) {
                $assignmentByDepartmentHead[$approverEmployeeId] ??= [
                    'template' => $template,
                    'assignment' => $assignment,
                ];
            }
            $assignmentByTemplateId[$template->id] = $assignment;

            array_push($newlyCreatedAccountEmployeeIds, ...$this->snapshotItemSignatories($assignment, $template));
        }

        $this->notifyItemApprovers($offboardingRequest, $templates, $assignmentByTemplateId, $newlyCreatedAccountEmployeeIds);

        if (! $emailTemplate) {
            return [];
        }

        $offboardee = $offboardingRequest->employee;
        $departmentHeads = $templates
            ->map(fn (ChecklistTemplate $template) => $template->is_immediate_head_checklist
                ? $offboardingRequest->immediateHead
                : $template->departmentHead)
            ->filter()
            ->unique('id');
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
     * Sends the dedicated "Offboarding Request Notification" email to every
     * approver of an active, non-Final-Pay checklist template applicable to
     * this request — one email per approver even when they're responsible
     * for several templates (department-head-led AND/OR the Immediate Head
     * checklist), listing every checklist they're responsible for
     * reviewing. Never sent for Final Pay templates (a distinct, later
     * stage handled by its own "Final Pay Checklist Approval"
     * notification) — excluded by the `$eligible` filter below.
     *
     * Called ONCE, directly by `OffboardingRequestController::notifyDepartmentHeads()`
     * right after the request is saved, alongside — not from inside —
     * `attachAndNotify()`. It must stay a separate call: `attachAndNotify()`
     * is also reused later for the Final Pay stage, and this notification
     * must never fire there (it's already excluded by the `$eligible`
     * filter below since Final Pay templates are always
     * `is_final_pay_checklist = true`, but keeping the call sites separate
     * means that exclusion isn't the only thing preventing a duplicate).
     * Every assigned approver is notified and given access to their
     * checklist(s) at the same moment, immediately on submission — there is
     * no "wait for the Immediate Head first" sequencing here or anywhere
     * else in this method; the Immediate Head checklist attaches and
     * notifies alongside every other template in the very same
     * `attachAndNotify()` call.
     */
    public function notifyDepartmentHeadsOfNewRequest(OffboardingRequest $offboardingRequest, Collection $templates): void
    {
        // Prefers the admin's per-request override (picked on the New
        // Offboarding Request modal), captured once at creation and never
        // re-checked for `is_active` afterward — a frozen snapshot, exactly
        // like the General Signatory assignment on this same request. Falls
        // back to today's live-default lookup only when no override was
        // stored (the normal "admin left it as default" case).
        $emailTemplate = $offboardingRequest->approverNotificationTemplate ?? EmailTemplate::where('is_active', true)
            ->where('template_name', self::REQUEST_NOTIFICATION_TEMPLATE)
            ->latest('updated_at')
            ->first();

        if (! $emailTemplate) {
            Log::warning('No "' . self::REQUEST_NOTIFICATION_TEMPLATE . '" email template found — approvers were not sent the offboarding request notification.', [
                'offboarding_request_id' => $offboardingRequest->id,
            ]);

            return;
        }

        // Resolved the same way `attachAndNotify()` resolves each
        // template's assigned approver — an Immediate Head checklist has no
        // department head of its own, it's assigned to whichever Immediate
        // Head was picked on THIS request.
        $eligible = $templates
            ->filter(fn (ChecklistTemplate $template) => ! $template->is_final_pay_checklist)
            ->map(fn (ChecklistTemplate $template) => [
                'template' => $template,
                'approverEmployeeId' => $template->is_immediate_head_checklist
                    ? $offboardingRequest->immediate_head_id
                    : $template->department_head_id,
            ])
            ->filter(fn (array $entry) => $entry['approverEmployeeId']);

        if ($eligible->isEmpty()) {
            return;
        }

        $offboardee = $offboardingRequest->employee;

        foreach ($eligible->groupBy('approverEmployeeId') as $approverEmployeeId => $entriesForApprover) {
            /** @var Collection<int, array{template: ChecklistTemplate, approverEmployeeId: int}> $entriesForApprover */
            $templatesForApprover = $entriesForApprover->pluck('template');
            $approver = $templatesForApprover->first()->is_immediate_head_checklist
                ? $offboardingRequest->immediateHead
                : $templatesForApprover->first()->departmentHead;

            $approver ??= Employee::find($approverEmployeeId);

            if (! $approver || ! $approver->email || ! filter_var($approver->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            // Same `due_in_days` math `attachAndNotify()` uses to actually
            // persist each checklist's `due_at` (always counted from the
            // offboardee's own Last Working Day) — computed independently
            // here rather than read off an `OffboardingRequestApprover`
            // row, since THIS notification fires before that row exists
            // (`OffboardingRequestController::notifyDepartmentHeads()`
            // calls this method first, `attachAndNotify()` second). One
            // date per template, in the same order as `checklistName`'s own
            // comma-joined list above, so an approver responsible for
            // several checklists at once (e.g. their own department
            // checklist AND the Immediate Head one) still sees which due
            // date belongs to which — a template with no `due_in_days`
            // configured defaults to the offboardee's own Last Working Day
            // (+0 days), same fallback `attachAndNotify()` persists onto
            // the actual `due_at` column, never a separate "No due date"
            // placeholder.
            $dueDates = $templatesForApprover->map(function (ChecklistTemplate $template) use ($offboardingRequest) {
                return $offboardingRequest->last_working_day
                    ? $offboardingRequest->last_working_day->copy()->addDays($template->due_in_days ?? 0)->format('M d, Y')
                    : 'No due date';
            });

            [$subject, $body] = $emailTemplate->render(
                approverName: $approver->name,
                offboardeeName: $offboardee->name,
                employeeNumber: $offboardee->employee_code,
                checklistName: $templatesForApprover->pluck('title')->implode(', '),
                dueDate: $dueDates->implode(', '),
                department: $offboardee->department,
                position: $offboardee->designation,
                dateHired: $offboardee->date_of_joining?->format('M d, Y'),
                separationDate: $offboardingRequest->last_working_day?->format('M d, Y'),
                reason: ucfirst($offboardingRequest->reason),
            );

            try {
                Mail::to($approver->email)->send(new ChecklistSignatoryAnnouncementMail($subject, $body));
            } catch (\Throwable $e) {
                Log::error('Failed to send offboarding request notification email.', [
                    'offboarding_request_id' => $offboardingRequest->id,
                    'recipient' => $approver->email,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Emails the offboardee themselves that their offboarding process has
     * started — offboarding/employment details, every checklist currently
     * attached to their request (department/area, assigned signatory, due
     * date, current status), and, only when a brand-new Employee-role
     * account was just created for them, their login username and
     * temporary password. Called once, directly from
     * `OffboardingRequestController::notifyDepartmentHeads()`, after
     * `attachAndNotify()` so the checklist summary reflects the templates
     * actually attached (with resolved due dates/signatories) rather than
     * the bare template list. A no-op (logged, not fatal) if the fixed-name
     * template hasn't been created/activated, same convention as every
     * other fixed-name notification in this class.
     */
    public function notifyOffboardee(OffboardingRequest $offboardingRequest, User $employeeUser, bool $isNewAccount, ?string $temporaryPassword): void
    {
        $offboardee = $offboardingRequest->employee;

        if (! $offboardee->email || ! filter_var($offboardee->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        // Same override-first, frozen-snapshot precedence as
        // `notifyDepartmentHeadsOfNewRequest()` above.
        $emailTemplate = $offboardingRequest->offboardeeNotificationTemplate ?? EmailTemplate::where('is_active', true)
            ->where('template_name', self::OFFBOARDEE_NOTIFICATION_TEMPLATE)
            ->latest('updated_at')
            ->first();

        if (! $emailTemplate) {
            Log::warning('No "' . self::OFFBOARDEE_NOTIFICATION_TEMPLATE . '" email template found — the offboardee was not sent the offboarding details notification.', [
                'offboarding_request_id' => $offboardingRequest->id,
            ]);

            return;
        }

        $offboardingRequest->loadMissing('approvers.checklistTemplate', 'approvers.employee', 'generalSignatoryApprovals');

        [$subject, $body] = $emailTemplate->render(
            approverName: $offboardee->name,
            offboardeeName: $offboardee->name,
            employeeNumber: $offboardee->employee_code,
            department: $offboardee->department,
            position: $offboardee->designation,
            dateHired: $offboardee->date_of_joining?->format('M d, Y'),
            separationDate: $offboardingRequest->last_working_day?->format('M d, Y'),
            requestDate: $offboardingRequest->created_at->format('M d, Y'),
            offboardingStatus: ucfirst(str_replace('_', ' ', $offboardingRequest->displayStatus())),
            username: $isNewAccount ? $employeeUser->username : null,
            temporaryPassword: $isNewAccount ? $temporaryPassword : null,
            checklistSummary: $this->buildChecklistSummaryHtml($offboardingRequest->approvers),
        );

        try {
            Mail::to($offboardee->email)->send(new ChecklistSignatoryAnnouncementMail($subject, $body));
        } catch (\Throwable $e) {
            Log::error('Failed to send offboarding details notification email.', [
                'offboarding_request_id' => $offboardingRequest->id,
                'recipient' => $offboardee->email,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Pre-renders the "{{checklist_summary}}" placeholder as an HTML table
     * — one row per currently-attached checklist — the same technique
     * `OffboardingRequestApprover::itemsStatusTableHtml()` already uses for
     * the overdue-reminder email's "{{pending_items}}" placeholder, since
     * `EmailTemplate::render()` only does flat string substitution and has
     * no loop/array placeholder of its own.
     *
     * @param  \Illuminate\Support\Collection<int, OffboardingRequestApprover>  $approvers
     */
    private function buildChecklistSummaryHtml($approvers): string
    {
        if ($approvers->isEmpty()) {
            return '<p>No checklists have been assigned yet.</p>';
        }

        $rows = $approvers->map(function (OffboardingRequestApprover $approver) {
            return '<tr>'
                .'<td style="padding:6px 10px;border:1px solid #e5e7eb;">'.e($approver->checklistTemplate?->title ?? '—').'</td>'
                .'<td style="padding:6px 10px;border:1px solid #e5e7eb;">'.e($approver->department() ?? '—').'</td>'
                .'<td style="padding:6px 10px;border:1px solid #e5e7eb;">'.e($approver->employee?->name ?? 'Unassigned').'</td>'
                .'<td style="padding:6px 10px;border:1px solid #e5e7eb;">'.e($approver->due_at?->format('M d, Y') ?? '—').'</td>'
                .'<td style="padding:6px 10px;border:1px solid #e5e7eb;">'.e($approver->clearanceStatusLabel()).'</td>'
                .'</tr>';
        })->implode('');

        return '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
            .'<tr>'
            .'<th style="padding:6px 10px;border:1px solid #e5e7eb;text-align:left;background:#f3f4f6;">Checklist</th>'
            .'<th style="padding:6px 10px;border:1px solid #e5e7eb;text-align:left;background:#f3f4f6;">Department/Area</th>'
            .'<th style="padding:6px 10px;border:1px solid #e5e7eb;text-align:left;background:#f3f4f6;">Assigned Signatory</th>'
            .'<th style="padding:6px 10px;border:1px solid #e5e7eb;text-align:left;background:#f3f4f6;">Due Date</th>'
            .'<th style="padding:6px 10px;border:1px solid #e5e7eb;text-align:left;background:#f3f4f6;">Status</th>'
            .'</tr>'
            .$rows
            .'</table>';
    }

    /**
     * Attaches + notifies the given General Signatories onto this
     * offboarding request (via `generalSignatories()`) so they appear on
     * its Clearance Form as additional, checklist-independent signatories —
     * see `GeneralSignatory`'s own docblock for why this feature
     * deliberately never touches `ChecklistTemplate`/`OffboardingRequestApprover`
     * at all: no checklist is ever created just to carry a General
     * Signatory. The caller decides WHICH General Signatories to pass —
     * exactly like `attachAndNotify()` takes an explicit `$templates`
     * collection for checklists — so this method itself has no opinion on
     * Sync/Async staging; see `OffboardingRequestController::notifyDepartmentHeads()`
     * and `ChecklistCompletionService::checkPrimaryGeneralSignatoriesCompletion()`/
     * `attachFinalPayGeneralSignatoriesIfReady()` for where that staging
     * decision is made.
     *
     * Each attached row snapshots that General Signatory's CURRENT
     * `sequence_type`/`is_final_pay_signatory` onto the pivot row at this
     * exact moment — frozen from then on, so a later edit to the General
     * Signatory's classification never changes the workflow of a request
     * it's already attached to. `firstOrCreate()`d by the
     * `(offboarding_request_id, general_signatory_id)` pair (same
     * uniqueness the schema already enforces), so the same General
     * Signatory can never be double-attached or double-notified for the
     * same request even if this method somehow ran twice with overlapping
     * input.
     *
     * For each newly-attached General Signatory: ensures they have a
     * login account — Approver role only if they're a registered Employee
     * Master Group Head with at least one active member, Employee role
     * otherwise (see `User::findOrCreateGeneralSignatory()`); an
     * already-existing account's role is never touched either way — then
     * emails them the fixed-name "General Signatory Offboarding
     * Notification" with the offboardee's details and — only when
     * configured — their own Task List. Account creation and the
     * Clearance Form snapshot both happen regardless of whether the email
     * template is configured or the recipient has a usable email address;
     * only the email send itself is skipped in that case.
     *
     * @param  Collection<int, GeneralSignatory>  $generalSignatories
     */
    public function notifyGeneralSignatories(OffboardingRequest $offboardingRequest, Collection $generalSignatories): void
    {
        if ($generalSignatories->isEmpty()) {
            return;
        }

        $generalSignatories->loadMissing('clearanceSignatory', 'tasks.signatory');

        foreach ($generalSignatories as $generalSignatory) {
            OffboardingRequestGeneralSignatory::firstOrCreate(
                [
                    'offboarding_request_id' => $offboardingRequest->id,
                    'general_signatory_id' => $generalSignatory->id,
                ],
                [
                    'sequence_type' => $generalSignatory->sequence_type,
                    'is_final_pay_signatory' => $generalSignatory->is_final_pay_signatory,
                    'status' => 'pending',
                    // Clearance Signing Due Date — the General Signatory
                    // equivalent of the Core/Primary checklist's own
                    // `clearance_signing_due_at` above. Null (no deadline
                    // shown) when this General Signatory has none
                    // configured, same "purely additive" convention.
                    'due_at' => $generalSignatory->due_in_days !== null
                        ? $offboardingRequest->last_working_day->copy()->addDays($generalSignatory->due_in_days)->endOfDay()
                        : null,
                ]
            );
        }

        // Same override-first, frozen-snapshot precedence as
        // `notifyDepartmentHeadsOfNewRequest()` above.
        $emailTemplate = $offboardingRequest->generalSignatoryNotificationTemplate ?? EmailTemplate::where('is_active', true)
            ->where('template_name', self::GENERAL_SIGNATORY_NOTIFICATION_TEMPLATE)
            ->latest('updated_at')
            ->first();

        if (! $emailTemplate) {
            Log::warning('No "' . self::GENERAL_SIGNATORY_NOTIFICATION_TEMPLATE . '" email template found — General Signatories were not emailed.', [
                'offboarding_request_id' => $offboardingRequest->id,
            ]);
        }

        $offboardee = $offboardingRequest->employee;

        foreach ($generalSignatories as $generalSignatory) {
            $clearanceSignatory = $generalSignatory->clearanceSignatory;

            if (! $clearanceSignatory) {
                continue;
            }

            $hasActiveGroupMembers = EmployeeGroup::where('group_head_employee_id', $clearanceSignatory->id)
                ->whereHas('employees', fn ($q) => $q->where('status', 'active'))
                ->exists();

            User::findOrCreateGeneralSignatory($clearanceSignatory, $hasActiveGroupMembers);

            if (! $emailTemplate || ! $clearanceSignatory->email || ! filter_var($clearanceSignatory->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            // The per-request approval-state row `firstOrCreate()`d above —
            // the same row the Approvals page's Submit button and
            // `GeneralSignatoryApprovalController` both act on. Only a
            // still-pending assignment gets an Approve button; one already
            // cleared (e.g. this notification is being re-sent) gets none,
            // so the email never offers a dead action.
            $assignment = OffboardingRequestGeneralSignatory::where('offboarding_request_id', $offboardingRequest->id)
                ->where('general_signatory_id', $generalSignatory->id)
                ->first();

            [$subject, $body] = $emailTemplate->render(
                approverName: $clearanceSignatory->name,
                offboardeeName: $offboardee->name,
                employeeNumber: $offboardee->employee_code,
                department: $offboardee->department,
                position: $offboardee->designation,
                dateHired: $offboardee->date_of_joining?->format('M d, Y'),
                separationDate: $offboardingRequest->last_working_day?->format('M d, Y'),
                requestDate: $offboardingRequest->created_at->format('M d, Y'),
                generalSignatoryTasks: $this->buildGeneralSignatoryTasksHtml($generalSignatory),
                approveButton: $assignment && $assignment->status === 'pending'
                    ? $this->buildApproveButtonHtml($this->createGeneralSignatoryApprovalUrl($assignment))
                    : '',
            );

            try {
                Mail::to($clearanceSignatory->email)->send(new ChecklistSignatoryAnnouncementMail($subject, $body));
            } catch (\Throwable $e) {
                Log::error('Failed to send General Signatory offboarding notification email.', [
                    'offboarding_request_id' => $offboardingRequest->id,
                    'general_signatory_id' => $generalSignatory->id,
                    'recipient' => $clearanceSignatory->email,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Resends the General Signatory notification email for an EXISTING
     * assignment — the "Notify Approver" action on the Offboarding Status/
     * Timeline's General Signatory section, for when the original email was
     * lost/deleted. Reuses the exact same subject/body assembly
     * `notifyGeneralSignatories()` uses when a request is first created
     * (same task list HTML, same one-click Approve button convention — a
     * FRESH token/link every send, never reusing or invalidating a prior
     * one, exactly like `notifyDepartmentHeadReady()`'s reminder emails
     * already do), just re-run against the row already attached instead of
     * attaching a new one. Never creates or touches an
     * `OffboardingRequestGeneralSignatory` row — only ever reads `$assignment`
     * and sends mail — so a resend can never duplicate the General
     * Signatory's existing assignment or spawn a second approval task.
     *
     * @return bool whether the email was actually sent (false = no valid
     *   recipient email on file, or the send itself threw) — the caller
     *   decides how to surface that to the admin.
     */
    public function resendGeneralSignatoryNotification(OffboardingRequestGeneralSignatory $assignment, EmailTemplate $emailTemplate): bool
    {
        $assignment->loadMissing('offboardingRequest.employee', 'generalSignatory.clearanceSignatory', 'generalSignatory.tasks.signatory');

        $generalSignatory = $assignment->generalSignatory;
        $clearanceSignatory = $generalSignatory->clearanceSignatory;

        if (! $clearanceSignatory || ! $clearanceSignatory->email || ! filter_var($clearanceSignatory->email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $offboardingRequest = $assignment->offboardingRequest;
        $offboardee = $offboardingRequest->employee;

        [$subject, $body] = $emailTemplate->render(
            approverName: $clearanceSignatory->name,
            offboardeeName: $offboardee->name,
            employeeNumber: $offboardee->employee_code,
            department: $offboardee->department,
            position: $offboardee->designation,
            dateHired: $offboardee->date_of_joining?->format('M d, Y'),
            separationDate: $offboardingRequest->last_working_day?->format('M d, Y'),
            requestDate: $offboardingRequest->created_at->format('M d, Y'),
            generalSignatoryTasks: $this->buildGeneralSignatoryTasksHtml($generalSignatory),
            approveButton: $assignment->status === 'pending'
                ? $this->buildApproveButtonHtml($this->createGeneralSignatoryApprovalUrl($assignment))
                : '',
        );

        Mail::to($clearanceSignatory->email)->send(new ChecklistSignatoryAnnouncementMail($subject, $body));

        return true;
    }

    /**
     * Generates the one-click "Approve" link for a General Signatory's
     * pending assignment and returns its full URL — the General Signatory
     * equivalent of `notifyDepartmentHeadReady()`'s
     * `ChecklistApprovalToken` generation below, using the same hashed-
     * token-plus-expiry convention (`GeneralSignatoryApprovalToken` mirrors
     * `ChecklistApprovalToken` exactly) and the same
     * `APPROVAL_TOKEN_LIFETIME_DAYS` lifetime.
     */
    private function createGeneralSignatoryApprovalUrl(OffboardingRequestGeneralSignatory $assignment): string
    {
        $rawToken = Str::random(64);

        $approvalToken = GeneralSignatoryApprovalToken::create([
            'offboarding_request_general_signatory_id' => $assignment->id,
            'token' => Hash::make($rawToken),
            'expires_at' => now()->addDays(self::APPROVAL_TOKEN_LIFETIME_DAYS),
        ]);

        return route('general-signatory-approval.show', ['id' => $approvalToken->id, 'token' => $rawToken]);
    }

    /**
     * Pre-renders the "{{approve_button}}" placeholder as a single styled
     * `<a>` tag, matching `checklist-ready-for-approval.blade.php`'s
     * Approve button styling — the same "pre-render raw HTML for an
     * unescaped placeholder" pattern `buildGeneralSignatoryTasksHtml()`
     * below already uses for that token.
     */
    public function buildApproveButtonHtml(string $url): string
    {
        return '<p style="margin:24px 0 0;">'
            .'<a href="'.e($url).'" style="display:inline-block;background-color:#145a3a;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:bold;">'
            .'Approve'
            .'</a>'
            .'</p>';
    }

    /**
     * Pre-renders the "{{general_signatory_tasks}}" placeholder as a full
     * HTML section (heading + table), or an empty string when this General
     * Signatory has no tasks configured at all — so a template referencing
     * this token never shows a dangling "Task List" heading with nothing
     * under it, per spec.
     */
    private function buildGeneralSignatoryTasksHtml(GeneralSignatory $generalSignatory): string
    {
        if ($generalSignatory->tasks->isEmpty()) {
            return '';
        }

        $rows = $generalSignatory->tasks->map(function (GeneralSignatoryTask $task) {
            return '<tr>'
                .'<td style="padding:6px 10px;border:1px solid #e5e7eb;">'.e($task->title).'</td>'
                .'<td style="padding:6px 10px;border:1px solid #e5e7eb;">'.e($task->signatory?->name ?? 'Unassigned').'</td>'
                .'</tr>';
        })->implode('');

        return '<p style="margin:16px 0 8px;font-weight:bold;">Task List</p>'
            .'<table style="width:100%;border-collapse:collapse;font-size:13px;">'
            .'<tr>'
            .'<th style="padding:6px 10px;border:1px solid #e5e7eb;text-align:left;background:#f3f4f6;">Task Title</th>'
            .'<th style="padding:6px 10px;border:1px solid #e5e7eb;text-align:left;background:#f3f4f6;">Task Assignee</th>'
            .'</tr>'
            .$rows
            .'</table>';
    }

    /**
     * Emails the offboarding request's creator that the employee is
     * following up on one specific, still-outstanding checklist — called
     * once from `ChecklistFollowUpService::send()`, which has already
     * validated the daily-per-checklist cooldown and the request's shared
     * follow-up budget before this ever runs. Returns whether the email was
     * actually sent, so the caller can mark the tracking row `failed`
     * instead of `sent` when it wasn't (no template configured, no valid
     * recipient email, or the send itself threw) — the follow-up attempt
     * still counts against the employee's budget either way, since the
     * budget exists to bound how many times THEY can try, not to guarantee
     * delivery.
     */
    public function notifyFollowUp(OffboardingRequestApprover $assignment, ChecklistFollowUp $followUp, User $recipient): bool
    {
        if (! $recipient->email || ! filter_var($recipient->email, FILTER_VALIDATE_EMAIL)) {
            Log::warning('Could not send checklist follow-up email — recipient has no valid email address.', [
                'checklist_follow_up_id' => $followUp->id,
            ]);

            return false;
        }

        $emailTemplate = EmailTemplate::where('is_active', true)
            ->where('template_name', self::FOLLOW_UP_TEMPLATE)
            ->latest('updated_at')
            ->first();

        if (! $emailTemplate) {
            Log::warning('No "' . self::FOLLOW_UP_TEMPLATE . '" email template found — the follow-up was not emailed.', [
                'checklist_follow_up_id' => $followUp->id,
            ]);

            return false;
        }

        $assignment->loadMissing('checklistTemplate.items', 'itemProgress', 'itemAssignments.assignedEmployee', 'employee');
        $offboardingRequest = $assignment->offboardingRequest;
        $offboardee = $offboardingRequest->employee;

        $items = $assignment->checklistTemplate?->items ?? collect();
        $progressByItemId = $assignment->itemProgress->keyBy('checklist_item_id');
        $completedCount = $items->filter(fn (ChecklistItem $item) => (bool) ($progressByItemId->get($item->id)?->is_checked ?? false))->count();

        $signatoryNames = $items
            ->map(fn (ChecklistItem $item) => $assignment->effectiveSignatoryFor($item)?->name)
            ->filter()
            ->unique()
            ->values();

        if ($signatoryNames->isEmpty() && $assignment->employee) {
            $signatoryNames = collect([$assignment->employee->name]);
        }

        [$subject, $body] = $emailTemplate->render(
            approverName: $recipient->name,
            offboardeeName: $offboardee->name,
            employeeNumber: $offboardee->employee_code,
            checklistName: $assignment->checklistTemplate?->title ?? 'Untitled Checklist',
            dueDate: $assignment->due_at?->format('M d, Y'),
            department: $assignment->department(),
            position: $offboardee->designation,
            checklistStatus: $assignment->clearanceStatusLabel(),
            dateHired: $offboardee->date_of_joining?->format('M d, Y'),
            separationDate: $offboardingRequest->last_working_day?->format('M d, Y'),
            requestDate: $offboardingRequest->created_at->format('M d, Y'),
            departmentHeadName: $assignment->employee?->name ?? 'Unassigned',
            assignedSignatories: $signatoryNames->isNotEmpty() ? $signatoryNames->implode(', ') : 'Unassigned',
            checklistProgress: "{$completedCount} of {$items->count()} items completed",
            remainingItems: $this->buildRemainingItemsHtml($assignment, $items, $progressByItemId),
            followUpSentAt: $followUp->sent_at->format('M d, Y g:i A'),
        );

        try {
            Mail::to($recipient->email)->send(new ChecklistSignatoryAnnouncementMail($subject, $body));

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send checklist follow-up email.', [
                'checklist_follow_up_id' => $followUp->id,
                'recipient' => $recipient->email,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The "{{remaining_items}}" placeholder — an HTML list of only the
     * checklist items still unchecked, since the follow-up email's whole
     * point is telling HR/admin exactly what's still outstanding (as
     * distinct from `buildChecklistSummaryHtml()`'s per-checklist overview,
     * which lists every checklist, not every item).
     *
     * @param  \Illuminate\Support\Collection<int, ChecklistItem>  $items
     * @param  \Illuminate\Support\Collection<int, ChecklistItemProgress>  $progressByItemId
     */
    private function buildRemainingItemsHtml(OffboardingRequestApprover $assignment, $items, $progressByItemId): string
    {
        $remaining = $items->reject(fn (ChecklistItem $item) => (bool) ($progressByItemId->get($item->id)?->is_checked ?? false));

        if ($remaining->isEmpty()) {
            return '<p>No individual checklist items remain — the checklist is awaiting Department Head approval.</p>';
        }

        $rows = $remaining->map(function (ChecklistItem $item) use ($assignment) {
            $signatory = $assignment->effectiveSignatoryFor($item);

            return '<li>' . e($item->title) . ($signatory ? ' — assigned to ' . e($signatory->name) : '') . '</li>';
        })->implode('');

        return '<ul style="margin:0;padding-left:20px;">' . $rows . '</ul>';
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
     * @param  array<int, OffboardingRequestApprover>  $assignmentByTemplateId  This request's own assignment row for
     *      each template, keyed by template id — consulted so a signatory
     *      snapshotted by `snapshotItemSignatories()` (a per-request
     *      `ChecklistItemAssignment` row, not a change to the shared
     *      template) is notified too, via the same `effectiveSignatoryFor()`
     *      resolution the rest of the app already uses for this.
     * @param  array<int, int>  $newlyCreatedAccountEmployeeIds  Employee ids whose login account was
     *      just created moments ago by `autoAssignGroupSignatories()` — by
     *      the time this method's own `User::firstWhere()` check runs,
     *      that account already exists, so without this list a genuinely
     *      brand-new account would be indistinguishable from one that
     *      already existed before this whole attach operation began, and
     *      its credentials would never make it into the email.
     */
    private function notifyItemApprovers(OffboardingRequest $offboardingRequest, Collection $templates, array $assignmentByTemplateId = [], array $newlyCreatedAccountEmployeeIds = []): void
    {
        $offboardee = $offboardingRequest->employee;

        /** @var array<int, array{employee: Employee, items: array<int, array{checklistTitle: string, itemTitle: string, dueAt: ?string}>}> $byApprover */
        $byApprover = [];

        foreach ($templates as $template) {
            $dueAt = $offboardingRequest->last_working_day
                ? $offboardingRequest->last_working_day->copy()->addDays($template->due_in_days ?? 0)->format('M d, Y')
                : null;
            $assignment = $assignmentByTemplateId[$template->id] ?? null;

            foreach ($template->items as $item) {
                /** @var ChecklistItem $item */

                // `effectiveSignatoryFor()` only ever resolves to someone
                // EXPLICITLY configured as this item's Task Assignee (on the
                // template, or via a per-request override) —
                // `snapshotItemSignatories()` never fills in an unassigned
                // item with a group member, so there is no "fallback
                // participant" case to account for here: every recipient
                // found below was genuinely, explicitly assigned to at
                // least this one item. Being a member of the Clearance
                // Signatory's Employee Master group is never enough on its
                // own to reach this branch.
                $signatory = $assignment ? $assignment->effectiveSignatoryFor($item) : $item->signatory;

                if (! $signatory || $signatory->id === $assignment?->employee_id) {
                    continue;
                }

                $byApprover[$signatory->id]['employee'] ??= $signatory;
                $byApprover[$signatory->id]['items'][] = [
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

            // Only this recipient's own explicitly assigned items — never
            // the whole checklist, and never another employee's items.
            $items = $entry['items'];

            $existingUser = User::firstWhere('username', $employee->employee_code_digits);
            $user = $existingUser ?? User::findOrCreateApprover($employee);
            $isNewAccount = $existingUser === null || in_array($employee->id, $newlyCreatedAccountEmployeeIds, true);

            $credentials = $isNewAccount ? [
                'username' => $employee->employee_code_digits,
                'password' => $employee->employee_code_digits,
            ] : null;

            if (! $employee->email || ! filter_var($employee->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            try {
                Mail::to($employee->email)->send(new ChecklistItemApproverAssignedMail(
                    approverName: $employee->name,
                    offboardeeName: $offboardee->name,
                    offboardeeEmployeeCode: $offboardee->employee_code,
                    assignedItems: $items,
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
     * How long the "Approve" button embedded in the checklist-ready email
     * stays clickable. Generous compared to the password-reset token's 5
     * minutes — unlike a security-sensitive credential, this just needs to
     * outlast however long the checklist realistically sits unactioned in
     * someone's inbox.
     */
    private const APPROVAL_TOKEN_LIFETIME_DAYS = 30;

    /**
     * Emails this employee's Department Head once every checklist item
     * across EVERY checklist they're the assigned approver for on this
     * request has been checked — one "ready for your final review and
     * approval" notice covering the whole combined group, instead of one
     * per checklist. Called only from
     * `ChecklistCompletionService::checkGroupReadyForApproval()`, which
     * already guards this to fire exactly once per group. The single
     * approval token is tied to the group's first member row — clicking
     * "Approve" re-expands to whatever this employee's current full group
     * is at click time (see `ApprovalController::resolveEmailApprovalState()`),
     * not just the members that existed when this email was sent.
     *
     * @param  Collection<int, OffboardingRequestApprover>  $members
     */
    public function notifyDepartmentHeadReady(OffboardingRequest $offboardingRequest, int $employeeId, Collection $members): void
    {
        $members->each->loadMissing('checklistTemplate.items', 'itemProgress.checkedBy');

        $departmentHead = $members->first()->employee ?? Employee::find($employeeId);

        if (! $departmentHead || ! $departmentHead->email || ! filter_var($departmentHead->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $offboardee = $offboardingRequest->employee;
        $checklists = $this->buildChecklistsPayload($members);

        $rawApprovalToken = Str::random(64);

        $approvalToken = ChecklistApprovalToken::create([
            'offboarding_request_approver_id' => $members->first()->id,
            'token' => Hash::make($rawApprovalToken),
            'expires_at' => now()->addDays(self::APPROVAL_TOKEN_LIFETIME_DAYS),
        ]);

        try {
            Mail::to($departmentHead->email)->send(new ChecklistReadyForApprovalMail(
                departmentHeadName: $departmentHead->name,
                offboardeeName: $offboardee->name,
                offboardeeEmployeeCode: $offboardee->employee_code,
                checklists: $checklists,
                approvalUrl: route('approvals.index'),
                approveUrl: route('approval.show', ['id' => $approvalToken->id, 'token' => $rawApprovalToken]),
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send checklist-ready-for-approval email.', [
                'offboarding_request_id' => $offboardingRequest->id,
                'employee_id' => $employeeId,
                'recipient' => $departmentHead->email,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generates a fresh one-click "Approve" link for a Department Head/
     * Clearance Signatory checklist assignment — the same hashed-token-
     * plus-expiry convention `notifyDepartmentHeadReady()` just used above
     * for the original "ready for approval" email, exposed here as its own
     * method so a later manual follow-up (e.g. an admin resending this
     * content as a customizable `EmailTemplate` via
     * `ApprovalController::remind()`) can mint its own fresh link on
     * demand — never reusing or invalidating a prior one, exactly like
     * every other resend in this app (`resendGeneralSignatoryNotification()`
     * above works the same way).
     */
    public function createChecklistApprovalUrl(OffboardingRequestApprover $assignment): string
    {
        $rawToken = Str::random(64);

        $approvalToken = ChecklistApprovalToken::create([
            'offboarding_request_approver_id' => $assignment->id,
            'token' => Hash::make($rawToken),
            'expires_at' => now()->addDays(self::APPROVAL_TOKEN_LIFETIME_DAYS),
        ]);

        return route('approval.show', ['id' => $approvalToken->id, 'token' => $rawToken]);
    }

    /**
     * Emails the HR/admin user who created this offboarding request once an
     * approver has submitted approval for their whole combined group (every
     * checklist they were the assigned approver for on this request) in one
     * action — the "the assigned approver just completed their checklist(s)"
     * notice. Called once from `ApprovalController::finalizeGroupApproval()`,
     * after every member row in the group has already been marked approved.
     * A no-op (logged, not fatal) for a request with no recorded creator —
     * expected for any request created before `created_by` existed.
     *
     * @param  Collection<int, OffboardingRequestApprover>  $members
     */
    public function notifyRequestCreatorOfGroupApproval(OffboardingRequest $offboardingRequest, int $employeeId, Collection $members): void
    {
        $creator = $offboardingRequest->creator;

        if (! $creator || ! $creator->email || ! filter_var($creator->email, FILTER_VALIDATE_EMAIL)) {
            Log::warning('Could not send checklist group approval confirmation email — offboarding request has no recorded creator with a valid email.', [
                'offboarding_request_id' => $offboardingRequest->id,
                'employee_id' => $employeeId,
            ]);

            return;
        }

        $members->each->loadMissing('checklistTemplate.items', 'itemProgress.checkedBy');

        $approver = $members->first()->employee ?? Employee::find($employeeId);
        $offboardee = $offboardingRequest->employee;
        $checklists = $this->buildChecklistsPayload($members);

        try {
            Mail::to($creator->email)->send(new ChecklistGroupApprovedMail(
                creatorName: $creator->name,
                approverName: $approver?->name ?? 'The assigned approver',
                offboardeeName: $offboardee->name,
                offboardeeEmployeeCode: $offboardee->employee_code,
                checklists: $checklists,
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send checklist group approval confirmation email.', [
                'offboarding_request_id' => $offboardingRequest->id,
                'employee_id' => $employeeId,
                'recipient' => $creator->email,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Shared shape for both the "ready for approval" and "group approved"
     * emails: one entry per checklist template in the group, each with its
     * own items and their current checked state — so an approver reviewing
     * either email can see exactly which checklist each item belongs to.
     *
     * @param  Collection<int, OffboardingRequestApprover>  $members
     * @return array<int, array{title: string, items: array<int, array{title: string, checkedByName: ?string, checkedAt: ?string, remark: ?string}>}>
     */
    private function buildChecklistsPayload(Collection $members): array
    {
        return $members->map(function (OffboardingRequestApprover $assignment) {
            $progressByItemId = $assignment->itemProgress->keyBy('checklist_item_id');

            return [
                'title' => $assignment->checklistTemplate->title,
                'items' => $assignment->checklistTemplate->items->map(function (ChecklistItem $item) use ($progressByItemId) {
                    $progress = $progressByItemId->get($item->id);

                    return [
                        'title' => $item->title,
                        'checkedByName' => $progress?->checkedBy?->name,
                        'checkedAt' => $progress?->checked_at?->format('M d, Y g:i A'),
                        'remark' => $progress?->remark,
                    ];
                })->all(),
            ];
        })->values()->all();
    }

    /**
     * Freezes, at request-attach time, exactly who is responsible for every
     * item on this template — the whole point being that none of it can
     * ever drift later if an admin edits the template (changes/adds/removes
     * an item's signatory, or reassigns the Department Head): every item
     * gets its own `ChecklistItemAssignment` snapshot row up front, so
     * `effectiveSignatoryFor()` always finds an explicit per-request record
     * instead of falling back to a live read of `$item->signatory`. Two
     * cases per item:
     *
     *   1. The item already has its own configured `signatory_id` on the
     *      template — snapshot that exact employee.
     *   2. The item has no signatory — snapshot an explicit "no signatory"
     *      row (`assigned_employee_id` null). This still locks the item in:
     *      without it, an admin adding a signatory to this item later would
     *      silently start applying to this already-created request too,
     *      the exact thing this method exists to prevent.
     *
     * Deliberately does NOT fall back to round-robin-distributing an
     * unassigned item across the Department Head's Employee Master group —
     * being a member of that group (or of the Clearance Signatory's group)
     * must never, by itself, make someone a Task Assignee. An item with no
     * explicit Task Assignee simply has none; only the Clearance
     * Signatory/Department Head is responsible for it, exactly as if no
     * group existed at all. This is what `notifyItemApprovers()` downstream
     * relies on to only email someone genuinely, explicitly assigned to at
     * least one item — see its own docblock.
     *
     * Never touches an item that already has an active per-request
     * override (relevant if this template is re-attached, e.g. the Final
     * Pay checklist path reusing `attachAndNotify()`), so a template
     * attached twice never re-snapshots — whatever was captured the first
     * time stands.
     *
     * @return array<int, int> employee ids for whom a brand-new login account was just created
     */
    private function snapshotItemSignatories(OffboardingRequestApprover $assignment, ChecklistTemplate $template): array
    {
        // Historically a no-op guard: before "Use Task Assignee as
        // Clearance Signatory" existed, `attachAndNotify()` never even
        // created an assignment row (so never reached this method) when a
        // template resolved to no owner — this check could never actually
        // fire. It's now a real, valid case: a headless assignment
        // (`employee_id === null`) is exactly the one this whole method
        // exists for — its items' Task Assignees ARE the signatories, so
        // snapshotting must run for it same as any other template.

        $assignment->loadMissing('itemAssignments');
        $alreadyOverriddenItemIds = $assignment->itemAssignments->where('status', 'active')->pluck('checklist_item_id');

        $template->loadMissing('items.signatory');
        $itemsNeedingSnapshot = $template->items->reject(
            fn (ChecklistItem $item) => $alreadyOverriddenItemIds->contains($item->id)
        )->values();

        if ($itemsNeedingSnapshot->isEmpty()) {
            return [];
        }

        $explicitItems = $itemsNeedingSnapshot->filter(fn (ChecklistItem $item) => $item->signatory_id !== null);
        $unassignedItems = $itemsNeedingSnapshot->reject(fn (ChecklistItem $item) => $item->signatory_id !== null)->values();

        $assignedTo = [];
        $newlyCreatedAccountEmployeeIds = [];

        $snapshot = function (ChecklistItem $item, ?Employee $signatoryEmployee) use ($assignment, &$assignedTo, &$newlyCreatedAccountEmployeeIds) {
            $assignedUserId = null;

            if ($signatoryEmployee) {
                if (! isset($assignedTo[$signatoryEmployee->id])) {
                    $existingUser = User::firstWhere('username', $signatoryEmployee->employee_code_digits);

                    if (! $existingUser) {
                        $newlyCreatedAccountEmployeeIds[] = $signatoryEmployee->id;
                    }
                }

                $assignedUserId = User::findOrCreateApprover($signatoryEmployee)->id;
                $assignedTo[$signatoryEmployee->id] ??= $signatoryEmployee;
            }

            $assignment->itemAssignments()->create([
                'checklist_item_id' => $item->id,
                'assigned_by_user_id' => null,
                'assigned_employee_id' => $signatoryEmployee?->id,
                'assigned_user_id' => $assignedUserId,
                'status' => 'active',
                'assigned_at' => now(),
            ]);
        };

        foreach ($explicitItems as $item) {
            $snapshot($item, $item->signatory);
        }

        // No fallback distribution across the Department Head's group — an
        // item with no explicit Task Assignee simply gets an explicit
        // "no signatory" snapshot row (`assigned_employee_id` null), same
        // as `$snapshot()`'s behavior always was for the "no group" case.
        // See this method's own docblock for why.
        foreach ($unassignedItems as $item) {
            $snapshot($item, null);
        }

        // Bust the `itemAssignments` relation cache populated by the
        // `loadMissing()` above — it was cached empty before these rows
        // existed, and `effectiveSignatoryFor()` (called right after this,
        // by `notifyItemApprovers()`) uses `loadMissing()` too, so without
        // this refresh it would silently keep seeing the stale, pre-create
        // snapshot and never notify anyone.
        $assignment->load('itemAssignments.assignedEmployee');

        return $newlyCreatedAccountEmployeeIds;
    }
}
