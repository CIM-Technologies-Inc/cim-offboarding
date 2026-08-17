<?php

namespace App\Http\Controllers;

use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\EmployeeGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'department_head_id' => ['nullable', 'exists:employees,id'],
            'department' => ['nullable', 'string', 'in:HR,IT,Accounting,Sales'],
            'is_final_pay_checklist' => ['nullable', 'boolean'],
            'due_in_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.signatory_id' => ['nullable', 'exists:employees,id'],
        ]);

        $eligibleSignatoryIds = $this->eligibleSignatoryIds($validated['department_head_id'] ?? null);

        DB::transaction(function () use ($validated, $request, $eligibleSignatoryIds) {
            $template = ChecklistTemplate::create([
                'title' => $validated['title'],
                'department_head_id' => $validated['department_head_id'] ?: null,
                'department' => $validated['department'] ?? null,
                'is_final_pay_checklist' => $request->boolean('is_final_pay_checklist'),
                'due_in_days' => $validated['due_in_days'] ?: null,
                'is_active' => true,
                'created_by' => $request->user()->id,
            ]);

            foreach ($validated['items'] as $index => $item) {
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
        ]);
    }

    public function update(Request $request, ChecklistTemplate $checklistTemplate): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'department_head_id' => ['nullable', 'exists:employees,id'],
            'department' => ['nullable', 'string', 'in:HR,IT,Accounting,Sales'],
            'is_final_pay_checklist' => ['nullable', 'boolean'],
            'due_in_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.signatory_id' => ['nullable', 'exists:employees,id'],
        ]);

        $eligibleSignatoryIds = $this->eligibleSignatoryIds($validated['department_head_id'] ?? null);

        DB::transaction(function () use ($validated, $request, $checklistTemplate, $eligibleSignatoryIds) {
            $checklistTemplate->update([
                'title' => $validated['title'],
                'department_head_id' => $validated['department_head_id'] ?: null,
                'department' => $validated['department'] ?? null,
                'is_final_pay_checklist' => $request->boolean('is_final_pay_checklist'),
                'due_in_days' => $validated['due_in_days'] ?: null,
            ]);

            $checklistTemplate->items()->delete();

            foreach ($validated['items'] as $index => $item) {
                $checklistTemplate->items()->create([
                    'title' => $item['title'],
                    'signatory_id' => $this->sanitizeSignatoryId($item['signatory_id'] ?? null, $eligibleSignatoryIds),
                    'sort_order' => $index,
                ]);
            }
        });

        return redirect()->route('checklist-templates.index')->with('success', 'Checklist template updated.');
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
