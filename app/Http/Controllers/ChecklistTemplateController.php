<?php

namespace App\Http\Controllers;

use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\EmployeeGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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
        ]);
    }

    public function create(): View
    {
        return view('pages.checklist-templates.create', [
            'title' => 'New Checklist Template',
            'employees' => Employee::orderBy('name')->get(['id', 'name', 'department']),
            'departmentHeads' => $this->departmentHeadOptions(),
            'employeeGroups' => $this->employeeGroupsForPicker(),
            'departments' => $this->departmentOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $isGeneralSignatory = $request->boolean('is_general_signatory');
        $isImmediateHeadChecklist = $request->boolean('is_immediate_head_checklist');

        $this->dropBlankItemsWhenGeneralSignatory($request, $isGeneralSignatory);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'department_head_id' => ['nullable', 'exists:employees,id'],
            'is_immediate_head_checklist' => ['nullable', 'boolean'],
            'department' => ['nullable', 'string', Rule::in($this->departmentOptions())],
            'is_final_pay_checklist' => ['nullable', 'boolean'],
            'is_general_signatory' => ['nullable', 'boolean'],
            'due_in_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'items' => $isGeneralSignatory ? ['nullable', 'array'] : ['required', 'array', 'min:1'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.signatory_id' => ['nullable', 'exists:employees,id'],
        ]);

        // An Immediate Head checklist is assigned exclusively to whichever
        // Immediate Head is picked on each individual offboarding request
        // (see ChecklistApprovalNotifier::attachAndNotify()) — it never has
        // its own Department Head, regardless of what the (disabled-in-the-
        // UI, but still defended here) field submitted.
        $departmentHeadId = $isImmediateHeadChecklist ? null : ($validated['department_head_id'] ?: null);
        $eligibleSignatoryIds = $this->eligibleSignatoryIds($departmentHeadId);

        DB::transaction(function () use ($validated, $request, $isGeneralSignatory, $isImmediateHeadChecklist, $departmentHeadId, $eligibleSignatoryIds) {
            $template = ChecklistTemplate::create([
                'title' => $validated['title'],
                'department_head_id' => $departmentHeadId,
                'is_immediate_head_checklist' => $isImmediateHeadChecklist,
                'department' => $validated['department'] ?? null,
                'is_final_pay_checklist' => $request->boolean('is_final_pay_checklist'),
                'is_general_signatory' => $isGeneralSignatory,
                'due_in_days' => $validated['due_in_days'] ?: null,
                'is_active' => true,
                'created_by' => $request->user()->id,
            ]);

            foreach ($validated['items'] ?? [] as $index => $item) {
                $template->items()->create([
                    'title' => $item['title'],
                    'signatory_id' => $this->sanitizeSignatoryId($item['signatory_id'] ?? null, $eligibleSignatoryIds),
                    'sort_order' => $index,
                ]);
            }
        });

        return redirect()->route('checklist-templates.index')->with('success', 'Checklist template saved.');
    }

    public function show(ChecklistTemplate $checklistTemplate): View
    {
        $checklistTemplate->load('items.signatory', 'creator');

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
            'departmentHeads' => $this->departmentHeadOptions(),
            'employeeGroups' => $this->employeeGroupsForPicker(),
            'departments' => $this->departmentOptions($checklistTemplate->department),
        ]);
    }

    public function update(Request $request, ChecklistTemplate $checklistTemplate): RedirectResponse
    {
        $isGeneralSignatory = $request->boolean('is_general_signatory');
        $isImmediateHeadChecklist = $request->boolean('is_immediate_head_checklist');

        $this->dropBlankItemsWhenGeneralSignatory($request, $isGeneralSignatory);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'department_head_id' => ['nullable', 'exists:employees,id'],
            'is_immediate_head_checklist' => ['nullable', 'boolean'],
            'department' => ['nullable', 'string', Rule::in($this->departmentOptions($checklistTemplate->department))],
            'is_final_pay_checklist' => ['nullable', 'boolean'],
            'is_general_signatory' => ['nullable', 'boolean'],
            'due_in_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'items' => $isGeneralSignatory ? ['nullable', 'array'] : ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer', Rule::exists('checklist_items', 'id')->where('checklist_template_id', $checklistTemplate->id)],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.signatory_id' => ['nullable', 'exists:employees,id'],
        ]);

        $departmentHeadId = $isImmediateHeadChecklist ? null : ($validated['department_head_id'] ?: null);
        $eligibleSignatoryIds = $this->eligibleSignatoryIds($departmentHeadId);

        DB::transaction(function () use ($validated, $request, $checklistTemplate, $isGeneralSignatory, $isImmediateHeadChecklist, $departmentHeadId, $eligibleSignatoryIds) {
            $checklistTemplate->update([
                'title' => $validated['title'],
                'department_head_id' => $departmentHeadId,
                'is_immediate_head_checklist' => $isImmediateHeadChecklist,
                'department' => $validated['department'] ?? null,
                'is_final_pay_checklist' => $request->boolean('is_final_pay_checklist'),
                'is_general_signatory' => $isGeneralSignatory,
                'due_in_days' => $validated['due_in_days'] ?: null,
            ]);

            $this->reconcileItems($checklistTemplate, $validated['items'] ?? [], $eligibleSignatoryIds);
        });

        return redirect()->route('checklist-templates.index')->with('success', 'Checklist template updated.');
    }

    /**
     * A General Signatory checklist's item form stays fully visible and
     * usable (per design), so the UI still seeds a blank item row when there
     * are none yet — left untouched, that row would submit as
     * `items[0][title] = ''` and fail `items.*.title`'s `required` rule even
     * though the admin never actually meant to add an item. Only relevant
     * when General Signatory is enabled: any submitted row with no title is
     * dropped here, before validation, so an all-blank `items` array
     * correctly validates as empty. A normal (non-General-Signatory)
     * checklist is untouched — a blank title there must keep failing
     * validation exactly as before, since items are mandatory for it.
     * Reindexed with `values()` so `items.*`/`sort_order` stay contiguous
     * after any rows are dropped.
     */
    private function dropBlankItemsWhenGeneralSignatory(Request $request, bool $isGeneralSignatory): void
    {
        if (! $isGeneralSignatory) {
            return;
        }

        $items = collect($request->input('items', []))
            ->filter(fn ($item) => filled($item['title'] ?? null))
            ->values()
            ->all();

        $request->merge(['items' => $items]);
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
            $attributes = [
                'title' => $item['title'],
                'signatory_id' => $this->sanitizeSignatoryId($item['signatory_id'] ?? null, $eligibleSignatoryIds),
                'sort_order' => $index,
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
     * Employees eligible to be picked as a department head: managers and above.
     */
    private function departmentHeadOptions()
    {
        return Employee::whereIn('designation', Employee::MANAGEMENT_DESIGNATIONS)
            ->orderBy('name')
            ->get(['id', 'name', 'department', 'designation']);
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
     */
    private function employeeGroupsForPicker()
    {
        return EmployeeGroup::whereNotNull('group_head_employee_id')
            ->with('employees:id,employee_group_id')
            ->get()
            ->map(fn (EmployeeGroup $group) => [
                'name' => $group->name,
                'headId' => $group->group_head_employee_id,
                'employeeIds' => $group->employees->pluck('id'),
            ])
            ->values();
    }

    /**
     * The set of employee IDs allowed as an item signatory on this
     * checklist template — the given Department Head's Employee Master
     * group members, plus the Department Head themselves (they may still
     * take an item directly, matching how they can already take over any
     * item during approval). Returns null (meaning "unrestricted", today's
     * original behavior) when the Department Head isn't registered as any
     * group's Group Head — the explicit fallback this feature requires.
     */
    private function eligibleSignatoryIds(?int $departmentHeadId): ?array
    {
        if (! $departmentHeadId) {
            return null;
        }

        $group = EmployeeGroup::where('group_head_employee_id', $departmentHeadId)->first();

        if (! $group) {
            return null;
        }

        return $group->employees()->pluck('id')->push($departmentHeadId)->unique()->values()->all();
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
}
