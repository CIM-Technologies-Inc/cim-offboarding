<?php

namespace App\Http\Controllers;

use App\Models\ChecklistTemplate;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\EmployeeGroup;
use App\Models\FinalApprover;
use App\Models\GeneralSignatory;
use App\Services\ClearanceSignatoryEscalationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ChecklistTemplateController extends Controller
{
    public function index(): View
    {
        $templates = ChecklistTemplate::withCount('items')
            ->with('creator')
            ->latest()
            ->get();

        return view('pages.checklist-templates.index', [
            'title' => 'Offboarding Checklist',
            'templates' => $templates,
            // General Signatory is a separate, independent record type (see
            // GeneralSignatoryController's docblock) that happens to live on
            // this same page — its list and the data its create/edit modal
            // needs are fetched here alongside the checklist templates.
            'generalSignatories' => GeneralSignatory::with(['clearanceSignatory', 'tasks.signatory'])->latest()->get(),
            // Final Approver — another independent record type on this same
            // page (see `FinalApprover`'s own docblock); ordered active-first
            // so the current one is always the top row of its table.
            'finalApprovers' => FinalApprover::with(['employee', 'creator'])->orderByDesc('is_active')->latest()->get(),
            'employees' => Employee::orderBy('name')->get(['id', 'name', 'department', 'employee_code']),
            'employeeGroups' => $this->employeeGroupsForPicker(),
        ]);
    }

    public function create(): View
    {
        return view('pages.checklist-templates.create', [
            'title' => 'New Checklist Template',
            'employees' => Employee::orderBy('name')->get(['id', 'name', 'department']),
            'departmentHeadGroups' => $this->departmentHeadGroupOptions(),
            'employeeGroups' => $this->employeeGroupsForPicker(),
            'departments' => $this->departmentOptions(),
            'emailTemplates' => $this->activeEmailTemplateOptions(),
            'escalationEmployees' => app(ClearanceSignatoryEscalationService::class)->eligibleEmployeeOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'employee_group_id' => ['nullable', 'exists:employee_groups,id'],
            'is_immediate_head_checklist' => ['nullable', 'boolean'],
            'department' => ['nullable', 'string', Rule::in($this->departmentOptions())],
            // Primary/Secondary/Final Pay — the Sync workflow's stage
            // ordering (see `ChecklistCompletionService::checkPrimaryChecklistsCompletion()`).
            // Replaces the old standalone `is_final_pay_checklist` checkbox
            // input; that column still exists and is still what every other
            // part of the app checks — this is just a single 3-way control
            // over it plus the new `sequence_type` column together, since a
            // checklist can never be both Final Pay and Primary/Secondary
            // at once.
            'checklist_classification' => ['required', Rule::in(['primary', 'secondary', 'final_pay'])],
            'due_in_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            // Independent of `due_in_days` above — see
            // `ChecklistTemplate.clearance_signing_deadline_days`.
            'clearance_signing_deadline_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.signatory_id' => ['nullable', 'exists:employees,id'],
            'items.*.notify_enabled' => ['nullable', 'boolean'],
            'items.*.email_template_id' => ['nullable', 'required_if:items.*.notify_enabled,1', 'exists:email_templates,id'],
            'items.*.notify_timing' => ['nullable', 'required_if:items.*.notify_enabled,1', Rule::in(['before', 'after'])],
            'items.*.notify_days' => ['nullable', 'required_if:items.*.notify_enabled,1', 'integer', 'min:1'],
            ...self::ESCALATION_RULES,
        ], self::ESCALATION_MESSAGES);

        $isFinalPayChecklist = $validated['checklist_classification'] === 'final_pay';
        $sequenceType = $isFinalPayChecklist ? null : $validated['checklist_classification'];
        // "Multiple Signatory Checklist" is no longer an independently
        // submitted checkbox — a Final Pay checklist is always treated as
        // one, every other priority never is. See `ChecklistTemplate.$use_task_assignee_as_signatory`
        // for what this actually drives downstream.
        $useTaskAssigneeAsSignatory = $isFinalPayChecklist;
        // Mutually exclusive with Immediate Head — a checklist can't both
        // follow the offboardee's own Immediate Head AND have no head at
        // all. The (disabled-in-the-UI, but still defended here) Immediate
        // Head checkbox is ignored whenever this is forced on.
        $isImmediateHeadChecklist = $request->boolean('is_immediate_head_checklist') && ! $useTaskAssigneeAsSignatory;

        // An Immediate Head checklist is assigned exclusively to whichever
        // Immediate Head is picked on each individual offboarding request
        // (see ChecklistApprovalNotifier::attachAndNotify()) — it never has
        // its own Department Head, regardless of what the (disabled-in-the-
        // UI, but still defended here) field submitted. Same for a
        // Task-Assignee-as-Signatory checklist: it has no Clearance
        // Signatory group at all — the individually assigned Task
        // Assignees are themselves the signatories.
        $employeeGroupId = ($isImmediateHeadChecklist || $useTaskAssigneeAsSignatory)
            ? null
            : ($validated['employee_group_id'] ?: null);
        // The submitted value is the specific GROUP the admin picked (never
        // trust a raw employee id from the client for this) — the actual
        // Department Head employee is derived from that group's own
        // registered `group_head_employee_id`, so `department_head_id`
        // keeps its exact existing meaning for every downstream consumer.
        $departmentHeadId = $employeeGroupId ? EmployeeGroup::find($employeeGroupId)?->group_head_employee_id : null;
        $eligibleSignatoryIds = $this->eligibleSignatoryIds($employeeGroupId);
        // A Task-Assignee-as-Signatory checklist also never has a
        // Department Checklist restriction — see the form's own matching
        // disabled-field treatment.
        $department = $useTaskAssigneeAsSignatory ? null : ($validated['department'] ?? null);
        $escalation = $this->escalationSettings($request, $validated, $departmentHeadId, $useTaskAssigneeAsSignatory, null);

        DB::transaction(function () use ($validated, $request, $isImmediateHeadChecklist, $useTaskAssigneeAsSignatory, $employeeGroupId, $departmentHeadId, $department, $eligibleSignatoryIds, $isFinalPayChecklist, $sequenceType, $escalation) {
            $template = ChecklistTemplate::create([
                'title' => $validated['title'],
                'employee_group_id' => $employeeGroupId,
                'department_head_id' => $departmentHeadId,
                'is_immediate_head_checklist' => $isImmediateHeadChecklist,
                'use_task_assignee_as_signatory' => $useTaskAssigneeAsSignatory,
                'department' => $department,
                'is_final_pay_checklist' => $isFinalPayChecklist,
                'sequence_type' => $sequenceType,
                'due_in_days' => $validated['due_in_days'] ?: null,
                'clearance_signing_deadline_days' => $validated['clearance_signing_deadline_days'] ?: null,
                'is_active' => true,
                'created_by' => $request->user()->id,
                ...$escalation,
            ]);

            foreach ($validated['items'] ?? [] as $index => $item) {
                $signatoryId = $this->sanitizeSignatoryId($item['signatory_id'] ?? null, $eligibleSignatoryIds);

                $template->items()->create([
                    'title' => $item['title'],
                    'signatory_id' => $signatoryId,
                    'sort_order' => $index,
                    ...$this->normalizeNotifyConfig($item, $signatoryId),
                ]);
            }
        });

        return redirect()->route('checklist-templates.index')->with('success', 'Checklist template saved.');
    }

    public function show(ChecklistTemplate $checklistTemplate): View
    {
        // departmentHead / employeeGroup / secondarySignatory feed the
        // "Primary Approver (Clearance Signatory)" / "Secondary Approver" panel.
        $checklistTemplate->load('items.signatory', 'creator', 'departmentHead', 'employeeGroup', 'secondarySignatory');

        return view('pages.checklist-templates.show', [
            'title' => $checklistTemplate->title,
            'template' => $checklistTemplate,
        ]);
    }

    public function edit(ChecklistTemplate $checklistTemplate): View
    {
        $checklistTemplate->load('items');

        return view('pages.checklist-templates.edit', [
            'title' => 'Edit Checklist Template',
            'template' => $checklistTemplate,
            'employees' => Employee::orderBy('name')->get(['id', 'name', 'department']),
            'departmentHeadGroups' => $this->departmentHeadGroupOptions(),
            'employeeGroups' => $this->employeeGroupsForPicker(),
            'departments' => $this->departmentOptions($checklistTemplate->department),
            'emailTemplates' => $this->activeEmailTemplateOptions(),
            'escalationEmployees' => app(ClearanceSignatoryEscalationService::class)->eligibleEmployeeOptions($checklistTemplate->secondary_signatory_employee_id),
            // "Assign Secondary Approver" from the details page: open with
            // "Enable Secondary Approver" pre-ticked and the section in view.
            // Only a form default — nothing is saved until Update is clicked.
            'assignSecondary' => request()->boolean('assign_secondary') && ! $checklistTemplate->use_task_assignee_as_signatory,
        ]);
    }

    public function update(Request $request, ChecklistTemplate $checklistTemplate): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'employee_group_id' => ['nullable', 'exists:employee_groups,id'],
            'is_immediate_head_checklist' => ['nullable', 'boolean'],
            'department' => ['nullable', 'string', Rule::in($this->departmentOptions($checklistTemplate->department))],
            'checklist_classification' => ['required', Rule::in(['primary', 'secondary', 'final_pay'])],
            'due_in_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'clearance_signing_deadline_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer', Rule::exists('checklist_items', 'id')->where('checklist_template_id', $checklistTemplate->id)],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.signatory_id' => ['nullable', 'exists:employees,id'],
            'items.*.notify_enabled' => ['nullable', 'boolean'],
            'items.*.email_template_id' => ['nullable', 'required_if:items.*.notify_enabled,1', 'exists:email_templates,id'],
            'items.*.notify_timing' => ['nullable', 'required_if:items.*.notify_enabled,1', Rule::in(['before', 'after'])],
            'items.*.notify_days' => ['nullable', 'required_if:items.*.notify_enabled,1', 'integer', 'min:1'],
            ...self::ESCALATION_RULES,
        ], self::ESCALATION_MESSAGES);

        $isFinalPayChecklist = $validated['checklist_classification'] === 'final_pay';
        $sequenceType = $isFinalPayChecklist ? null : $validated['checklist_classification'];
        // See the matching comment in store() — no longer an independently
        // submitted checkbox.
        $useTaskAssigneeAsSignatory = $isFinalPayChecklist;
        $isImmediateHeadChecklist = $request->boolean('is_immediate_head_checklist') && ! $useTaskAssigneeAsSignatory;

        $employeeGroupId = ($isImmediateHeadChecklist || $useTaskAssigneeAsSignatory)
            ? null
            : ($validated['employee_group_id'] ?: null);
        $departmentHeadId = $employeeGroupId ? EmployeeGroup::find($employeeGroupId)?->group_head_employee_id : null;
        $eligibleSignatoryIds = $this->eligibleSignatoryIds($employeeGroupId);
        $department = $useTaskAssigneeAsSignatory ? null : ($validated['department'] ?? null);
        $escalation = $this->escalationSettings($request, $validated, $departmentHeadId, $useTaskAssigneeAsSignatory, $checklistTemplate);

        DB::transaction(function () use ($validated, $request, $checklistTemplate, $isImmediateHeadChecklist, $useTaskAssigneeAsSignatory, $employeeGroupId, $departmentHeadId, $department, $eligibleSignatoryIds, $isFinalPayChecklist, $sequenceType, $escalation) {
            $checklistTemplate->update([
                'title' => $validated['title'],
                'employee_group_id' => $employeeGroupId,
                'department_head_id' => $departmentHeadId,
                'is_immediate_head_checklist' => $isImmediateHeadChecklist,
                'use_task_assignee_as_signatory' => $useTaskAssigneeAsSignatory,
                'department' => $department,
                'is_final_pay_checklist' => $isFinalPayChecklist,
                'sequence_type' => $sequenceType,
                'due_in_days' => $validated['due_in_days'] ?: null,
                'clearance_signing_deadline_days' => $validated['clearance_signing_deadline_days'] ?: null,
                // Template-level only: existing offboarding requests keep
                // the settings snapshotted when their checklist was assigned.
                ...$escalation,
            ]);

            $this->reconcileItems($checklistTemplate, $validated['items'] ?? [], $eligibleSignatoryIds);
        });

        return redirect()->route('checklist-templates.index')->with('success', 'Checklist template updated.');
    }

    /**
     * "Secondary Clearance Signatory & Escalation Settings" validation rules,
     * shared by store() and update().
     */
    private const ESCALATION_RULES = [
        // "Enable Secondary Approver" — master switch. While it's off the
        // section is hidden and its fields are neither validated nor saved
        // (`exclude_unless`), so previously saved settings are kept as-is.
        'secondary_approver_enabled' => ['nullable', 'boolean'],
        'secondary_signatory_employee_id' => ['exclude_unless:secondary_approver_enabled,1', 'nullable', 'integer', 'exists:employees,id'],
        // Escalation is automatic whenever the switch is on (there is no
        // separate "Enable Automatic Escalation" checkbox), so the period is
        // required then.
        'escalation_days' => ['exclude_unless:secondary_approver_enabled,1', 'required', 'integer', 'min:1', 'max:365'],
        'escalation_day_type' => ['exclude_unless:secondary_approver_enabled,1', 'nullable', 'in:calendar,working'],
        'escalation_notify_hr' => ['exclude_unless:secondary_approver_enabled,1', 'nullable', 'boolean'],
    ];

    private const ESCALATION_MESSAGES = [
        'secondary_signatory_employee_id.exists' => 'The selected secondary Clearance Signatory does not exist in Employee Master.',
        'escalation_days.required' => 'Enter how many days to wait before escalating ("Escalate After (Days)").',
        'escalation_days.min' => '"Escalate After (Days)" must be at least 1 day.',
        'escalation_days.max' => '"Escalate After (Days)" can be at most 365 days.',
    ];

    /**
     * The template's escalation columns, after the cross-field checks the
     * plain rules can't express: escalation needs a valid secondary; the
     * secondary must be an eligible employee and never the template's own
     * primary (its Department/Group Head); and a "Use Task Assignee as
     * Clearance Signatory" checklist has no single signatory to escalate.
     * (An Immediate Head checklist's primary is only known per request, so
     * that comparison happens when each request's checklist is assigned —
     * see `ClearanceSignatoryEscalationService::initializeAssignment()`.)
     *
     * @return array<string, mixed>
     */
    private function escalationSettings(Request $request, array $validated, ?int $departmentHeadId, bool $useTaskAssigneeAsSignatory, ?ChecklistTemplate $existing): array
    {
        // "Enable Secondary Approver" off (or not applicable to a Task
        // Assignee checklist): the feature is disabled for this template.
        // A new template gets the defaults; an existing one keeps whatever
        // secondary/escalation settings it had saved, so re-enabling the
        // switch restores them. `ClearanceSignatoryEscalationService::initializeAssignment()`
        // ignores those settings while the switch is off.
        if (! $request->boolean('secondary_approver_enabled') || $useTaskAssigneeAsSignatory) {
            if ($useTaskAssigneeAsSignatory && $request->boolean('secondary_approver_enabled')) {
                throw ValidationException::withMessages([
                    'secondary_approver_enabled' => 'This checklist uses its Task Assignees as signatories, so it has no single Clearance Signatory to back up. Turn off "Enable Secondary Approver".',
                ]);
            }

            return $existing
                ? ['secondary_approver_enabled' => false]
                : [
                    'secondary_approver_enabled' => false,
                    'secondary_signatory_employee_id' => null,
                    'escalation_enabled' => false,
                    'escalation_days' => null,
                    'escalation_day_type' => 'calendar',
                    'escalation_notify_hr' => true,
                ];
        }

        $secondaryId = ! empty($validated['secondary_signatory_employee_id']) ? (int) $validated['secondary_signatory_employee_id'] : null;
        $errors = [];

        if ($secondaryId) {
            if ($secondaryId === $departmentHeadId) {
                $errors['secondary_signatory_employee_id'] = 'The secondary Clearance Signatory must be a different person from the primary Clearance Signatory (the selected group\'s Group Head).';
            } elseif ($error = app(ClearanceSignatoryEscalationService::class)->eligibilityError(Employee::find($secondaryId))) {
                $errors['secondary_signatory_employee_id'] = $error;
            }
        } else {
            $errors['secondary_signatory_employee_id'] = 'Select a secondary Clearance Signatory, or turn off "Enable Secondary Approver".';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'secondary_approver_enabled' => true,
            'secondary_signatory_employee_id' => $secondaryId,
            // Automatic escalation always accompanies an enabled secondary
            // approver — kept as a column because each request's snapshot
            // and the escalation engine read it.
            'escalation_enabled' => true,
            'escalation_days' => ! empty($validated['escalation_days']) ? (int) $validated['escalation_days'] : null,
            'escalation_day_type' => ($validated['escalation_day_type'] ?? null) === 'working' ? 'working' : 'calendar',
            'escalation_notify_hr' => $request->has('escalation_notify_hr') ? $request->boolean('escalation_notify_hr') : true,
        ];
    }

    /**
     * Updates each submitted item's own existing row IN PLACE (matched by
     * `id`) instead of the old delete-everything-then-recreate-everything
     * approach. That destructive pattern was a serious bug: an item's `id`
     * is what every already-created offboarding request's
     * `ChecklistItemAssignment` (signatory snapshot) and
     * `ChecklistItemProgress` (completion history) rows point to via a
     * cascading foreign key — deleting and recreating the item, even to
     * make an unrelated edit, silently deleted those rows too. Once that
     * snapshot was gone, `effectiveSignatoryFor()` had nothing left to
     * consult and fell back to reading the template's new live value,
     * which is exactly the "editing the template changed an already-created
     * request" bug this method exists to prevent.
     *
     * Only an item genuinely removed from the template (its `id` isn't
     * among the submitted items at all) gets deleted — and only then does
     * the cascade fire, correctly, since the admin deliberately removed it.
     *
     * @param  array<int, array{id?: int|string|null, title: string, signatory_id?: int|string|null}>  $items
     */
    private function reconcileItems(ChecklistTemplate $checklistTemplate, array $items, ?array $eligibleSignatoryIds): void
    {
        $submittedIds = collect($items)->pluck('id')->filter()->all();

        $checklistTemplate->items()->whereNotIn('id', $submittedIds)->delete();

        foreach ($items as $index => $item) {
            $signatoryId = $this->sanitizeSignatoryId($item['signatory_id'] ?? null, $eligibleSignatoryIds);

            $attributes = [
                'title' => $item['title'],
                'signatory_id' => $signatoryId,
                'sort_order' => $index,
                ...$this->normalizeNotifyConfig($item, $signatoryId),
            ];

            if (! empty($item['id'])) {
                $checklistTemplate->items()->whereKey($item['id'])->update($attributes);
            } else {
                $checklistTemplate->items()->create($attributes);
            }
        }
    }

    public function destroy(ChecklistTemplate $checklistTemplate): RedirectResponse
    {
        $checklistTemplate->delete();

        return redirect()->route('checklist-templates.index')->with('success', 'Checklist template deleted.');
    }

    public function toggleStatus(ChecklistTemplate $checklistTemplate): RedirectResponse
    {
        $checklistTemplate->update(['is_active' => ! $checklistTemplate->is_active]);

        return redirect()->route('checklist-templates.index')
            ->with('success', 'Checklist template marked as ' . ($checklistTemplate->is_active ? 'active' : 'inactive') . '.');
    }

    /**
     * Every Employee Master group eligible to be picked as a Clearance
     * Signatory — one option per GROUP, not deduped by its Group Head, so
     * two different groups sharing the same Group Head (e.g. "Admin and
     * Operations Group" and "Human Resources Group" both headed by the same
     * employee) both appear here and remain independently selectable. The
     * option's VALUE is the group's own id (`employee_group_id` on
     * `ChecklistTemplate`) — `store()`/`update()` resolve the actual
     * Department Head employee from that group server-side, never trusting
     * a raw employee id from the client for this field.
     *
     * No `$include`-style fallback is needed here (unlike
     * `departmentOptions()`'s own `$include` param): if a template's
     * previously-selected group is ever deleted, `employee_group_id` is
     * `nullOnDelete()`'d back to null on that row automatically, so there's
     * nothing dangling left to keep selectable.
     */
    private function departmentHeadGroupOptions(): Collection
    {
        return EmployeeGroup::whereNotNull('group_head_employee_id')
            ->with('groupHead:id,name,department,designation')
            ->orderBy('name')
            ->get(['id', 'name', 'group_head_employee_id']);
    }

    /**
     * The real, distinct department values employees actually have —
     * what the "Per Department" picker offers, instead of a hardcoded
     * guess, since that's exactly what this value gets matched against
     * (exact string equality — see `ChecklistTemplate::scopeApplicableToDepartment()`)
     * when deciding which offboarding requests a checklist attaches to.
     * Sourcing the picker from real data means an admin can never select
     * a department no employee actually belongs to.
     *
     * `$include` keeps a template's own already-saved value selectable
     * (and valid) on its own edit page even if it no longer matches any
     * current employee — otherwise re-saving an untouched template could
     * suddenly fail validation on a field the admin never touched.
     */
    private function departmentOptions(?string $include = null): Collection
    {
        $departments = Employee::whereNotNull('department')
            ->where('department', '!=', '')
            ->distinct()
            ->orderBy('department')
            ->pluck('department');

        if ($include && ! $departments->contains($include)) {
            $departments = $departments->push($include)->sort()->values();
        }

        return $departments;
    }

    /**
     * Every Employee Master group that has a registered Group Head, shaped
     * for the create/edit page's item-signatory picker: as the admin picks
     * (or changes) the checklist's Department Head, the picker reactively
     * narrows to that group's own members — the automatic-signatory
     * behavior the checklist template's Department Head selection is
     * supposed to drive. A department head with no matching group here
     * means no restriction applies (see `eligibleSignatoryIds()`), so a
     * checklist can still be configured exactly as it could before this
     * feature existed.
     *
     * Only members flagged `is_task_assignee` on the Employee Master page
     * are included, and the group's own Group Head is always excluded here
     * too — even if the head happens to also be a member of their own group
     * with the flag enabled, they're already the Clearance Signatory and
     * must never additionally appear as a selectable Task Assignee. A group
     * member who isn't marked as a Task Assignee simply never appears in
     * the Task Assignee picker, even though they're still a group member
     * for every other purpose (Clearance Signatory eligibility, group
     * membership counts, etc. are untouched by this flag).
     */
    private function employeeGroupsForPicker()
    {
        return EmployeeGroup::whereNotNull('group_head_employee_id')
            ->with(['employees' => fn ($q) => $q->where('is_task_assignee', true)->select('id', 'employee_group_id')])
            ->get()
            ->map(fn (EmployeeGroup $group) => [
                // The group's own id — the unique reference the Clearance
                // Signatory picker now matches against (`employeeGroupId`),
                // instead of the ambiguous `headId` alone, which two
                // different groups can share.
                'id' => $group->id,
                'name' => $group->name,
                'headId' => $group->group_head_employee_id,
                'employeeIds' => $group->employees->reject(fn (Employee $employee) => $employee->id === $group->group_head_employee_id)->pluck('id'),
            ])
            ->values();
    }

    /**
     * The set of employee IDs allowed as an item signatory on this
     * checklist template — the selected group's members who are flagged
     * `is_task_assignee`, EXCLUDING the group's own Group Head: they're
     * already the Clearance Signatory for the whole checklist, so they must
     * never also appear as a selectable Task Assignee, even if they're a
     * member of their own group with the flag enabled. Returns null
     * (meaning "unrestricted", today's original behavior) when no group is
     * selected. Resolved by the group's own id — never by its Group Head's
     * employee id alone, which two different groups can share.
     */
    private function eligibleSignatoryIds(?int $employeeGroupId): ?array
    {
        if (! $employeeGroupId) {
            return null;
        }

        $group = EmployeeGroup::find($employeeGroupId);

        if (! $group) {
            return null;
        }

        return $group->employees()
            ->where('is_task_assignee', true)
            ->where('id', '!=', $group->group_head_employee_id)
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * Clears (rather than rejects) an item's submitted signatory when it
     * falls outside the eligible pool — e.g. the Department Head's group
     * membership changed since this template was last saved. Silently
     * synchronizing this way, instead of failing the whole save with a
     * validation error, means a routine re-save of an otherwise-untouched
     * template never gets blocked by a group change the admin didn't even
     * touch here. A null `$eligibleSignatoryIds` (no group registered for
     * this Department Head) leaves every submitted value untouched.
     */
    private function sanitizeSignatoryId(mixed $signatoryId, ?array $eligibleSignatoryIds): ?int
    {
        $signatoryId = $signatoryId ?: null;

        if ($signatoryId === null || $eligibleSignatoryIds === null) {
            return $signatoryId ? (int) $signatoryId : null;
        }

        return in_array((int) $signatoryId, $eligibleSignatoryIds, true) ? (int) $signatoryId : null;
    }

    /**
     * Every active Email & Notification template, for the item-level
     * "Enable Scheduled Notification" picker — the admin selects an
     * existing template rather than authoring new content here.
     */
    private function activeEmailTemplateOptions(): Collection
    {
        return EmailTemplate::where('is_active', true)->orderBy('template_name')->get(['id', 'template_name']);
    }

    /**
     * Scheduled notification config is only meaningful once an item has a
     * (sanitized) Task Assignee — same silent-clear convention as
     * `sanitizeSignatoryId()` above, rather than failing the whole save:
     * unchecking "Enable Scheduled Notification" during an update, or the
     * item's assignee being cleared/rejected, both simply drop the config
     * back to disabled instead of blocking the save.
     *
     * @param  array<string, mixed>  $item
     * @return array{notify_enabled: bool, email_template_id: int|null, notify_timing: string|null, notify_days: int|null}
     */
    private function normalizeNotifyConfig(array $item, ?int $signatoryId): array
    {
        if (empty($item['notify_enabled']) || ! $signatoryId) {
            return [
                'notify_enabled' => false,
                'email_template_id' => null,
                'notify_timing' => null,
                'notify_days' => null,
            ];
        }

        return [
            'notify_enabled' => true,
            'email_template_id' => (int) $item['email_template_id'],
            'notify_timing' => $item['notify_timing'],
            'notify_days' => (int) $item['notify_days'],
        ];
    }
}
