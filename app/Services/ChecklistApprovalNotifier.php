<?php

namespace App\Services;

use App\Mail\ChecklistItemApproverAssignedMail;
use App\Mail\ChecklistReadyForApprovalMail;
use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\ChecklistApprovalToken;
use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\EmployeeGroup;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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

            if (! $approverEmployeeId) {
                continue;
            }

            $assignment = $offboardingRequest->approvers()->firstOrCreate(
                ['checklist_template_id' => $template->id],
                [
                    'employee_id' => $approverEmployeeId,
                    'status' => 'pending',
                    'assigned_at' => now(),
                    'due_at' => $template->due_in_days ? now()->addDays($template->due_in_days) : null,
                ]
            );

            $assignmentByDepartmentHead[$approverEmployeeId] ??= [
                'template' => $template,
                'assignment' => $assignment,
            ];
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
            $dueAt = $template->due_in_days ? now()->addDays($template->due_in_days)->format('M d, Y') : null;
            $assignment = $assignmentByTemplateId[$template->id] ?? null;

            foreach ($template->items as $item) {
                /** @var ChecklistItem $item */
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

            $existingUser = User::firstWhere('username', $employee->employee_code);
            $user = $existingUser ?? User::findOrCreateApprover($employee);
            $isNewAccount = $existingUser === null || in_array($employee->id, $newlyCreatedAccountEmployeeIds, true);

            $credentials = $isNewAccount ? [
                'username' => $employee->employee_code,
                'password' => $employee->employee_code,
            ] : null;

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
     * How long the "Approve" button embedded in the checklist-ready email
     * stays clickable. Generous compared to the password-reset token's 5
     * minutes — unlike a security-sensitive credential, this just needs to
     * outlast however long the checklist realistically sits unactioned in
     * someone's inbox.
     */
    private const APPROVAL_TOKEN_LIFETIME_DAYS = 30;

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

        $rawApprovalToken = Str::random(64);

        $approvalToken = ChecklistApprovalToken::create([
            'offboarding_request_approver_id' => $assignment->id,
            'token' => Hash::make($rawApprovalToken),
            'expires_at' => now()->addDays(self::APPROVAL_TOKEN_LIFETIME_DAYS),
        ]);

        try {
            Mail::to($departmentHead->email)->send(new ChecklistReadyForApprovalMail(
                departmentHeadName: $departmentHead->name,
                offboardeeName: $offboardee->name,
                offboardeeEmployeeCode: $offboardee->employee_code,
                checklistTitle: $assignment->checklistTemplate->title,
                items: $items,
                approvalUrl: route('approvals.index'),
                approveUrl: route('approval.show', ['id' => $approvalToken->id, 'token' => $rawApprovalToken]),
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send checklist-ready-for-approval email.', [
                'offboarding_request_approver_id' => $assignment->id,
                'recipient' => $departmentHead->email,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Freezes, at request-attach time, exactly who is responsible for every
     * item on this template — the whole point being that none of it can
     * ever drift later if an admin edits the template (changes/adds/removes
     * an item's signatory, or reassigns the Department Head): every item
     * gets its own `ChecklistItemAssignment` snapshot row up front, so
     * `effectiveSignatoryFor()` always finds an explicit per-request record
     * instead of falling back to a live read of `$item->signatory`. Three
     * cases per item:
     *
     *   1. The item already has its own configured `signatory_id` on the
     *      template — snapshot that exact employee.
     *   2. The item has no signatory, but the Department Head is a
     *      registered Employee Master Group Head with active members —
     *      round-robin the item across that group (unchanged from before;
     *      this is the only case that also logs an activity, since it's the
     *      only genuinely automatic *decision* being made here — case 1 is
     *      just recording the admin's own existing choice).
     *   3. Neither applies — snapshot an explicit "no signatory" row
     *      (`assigned_employee_id` null). This still locks the item in:
     *      without it, an admin adding a signatory to this item later would
     *      silently start applying to this already-created request too,
     *      the exact thing this method exists to prevent.
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
        if (! $assignment->employee_id) {
            return [];
        }

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

        $groupMembers = collect();

        // Employee Master groups are a Department Head concept — an
        // Immediate Head checklist (department_head_id null) never has a
        // group of its own, so its unassigned items always snapshot as
        // "no signatory" below, same as a normal checklist with no
        // matching group.
        if ($unassignedItems->isNotEmpty() && $template->department_head_id) {
            $group = EmployeeGroup::where('group_head_employee_id', $template->department_head_id)->first();

            if ($group) {
                $groupMembers = $group->employees()->where('status', 'active')->orderBy('employee_code')->get();
            }
        }

        $assignedTo = [];
        $newlyCreatedAccountEmployeeIds = [];

        $snapshot = function (ChecklistItem $item, ?Employee $signatoryEmployee) use ($assignment, &$assignedTo, &$newlyCreatedAccountEmployeeIds) {
            $assignedUserId = null;

            if ($signatoryEmployee) {
                if (! isset($assignedTo[$signatoryEmployee->id])) {
                    $existingUser = User::firstWhere('username', $signatoryEmployee->employee_code);

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

        $autoAssignedGroupMembers = [];

        foreach ($unassignedItems as $index => $item) {
            if ($groupMembers->isEmpty()) {
                $snapshot($item, null);

                continue;
            }

            $member = $groupMembers[$index % $groupMembers->count()];
            $snapshot($item, $member);
            $autoAssignedGroupMembers[$member->id] ??= $member;
        }

        // Bust the `itemAssignments` relation cache populated by the
        // `loadMissing()` above — it was cached empty before these rows
        // existed, and `effectiveSignatoryFor()` (called right after this,
        // by `notifyItemApprovers()`) uses `loadMissing()` too, so without
        // this refresh it would silently keep seeing the stale, pre-create
        // snapshot and never notify anyone.
        $assignment->load('itemAssignments.assignedEmployee');

        if (! empty($autoAssignedGroupMembers)) {
            $assignment->offboardingRequest->activities()->create([
                'offboarding_request_approver_id' => $assignment->id,
                'action' => 'checklist_item_auto_assigned',
                'status' => $assignment->offboardingRequest->status,
                'comment' => sprintf(
                    '"%s" items automatically assigned to %s\'s group members: %s.',
                    $template->title,
                    $template->departmentHead?->name ?? 'the Department Head',
                    collect($autoAssignedGroupMembers)->map(fn (Employee $e) => "{$e->employee_code} - {$e->name}")->implode(', ')
                ),
            ]);
        }

        return $newlyCreatedAccountEmployeeIds;
    }
}
