<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\TaskExemption;
use App\Models\TaskExemptionTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Task Exemption (System Administration) — admin CRUD for the rules
 * `App\Services\TaskExemptionResolver` applies when an offboarding request
 * is created. Saving here only ever affects FUTURE requests: every request
 * freezes what applied to it at submission (`OffboardingRequestExemptedTask`),
 * so editing, deactivating, or deleting a rule never changes one that
 * already exists.
 *
 * JSON-aware create/update/delete, same convention as Employee Master's
 * fetch()-based modals: field-level 422 errors keep the modal open, and the
 * page updates in place without a reload.
 */
class TaskExemptionController extends Controller
{
    public function index(): View
    {
        return view('pages.task-exemptions.index', [
            'title' => 'Task Exemption',
            'exemptions' => TaskExemption::with(['targets.employee', 'items', 'creator', 'updater'])
                ->orderBy('name')
                ->get()
                ->map(fn (TaskExemption $exemption) => $this->present($exemption))
                ->values(),
            'checklists' => $this->checklistOptions(),
            'departmentOptions' => $this->departmentOptions(),
            'positionOptions' => $this->positionOptions(),
            'employeeOptions' => Employee::orderBy('name')
                ->get(['id', 'name', 'employee_code', 'department', 'designation', 'position', 'status'])
                ->map(fn (Employee $employee) => [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'employee_code' => $employee->employee_code,
                    'department' => $employee->department,
                    'position' => $employee->position ?: $employee->designation,
                ])
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $exemption = DB::transaction(function () use ($data, $request) {
            // No Exemption Name/Status/Reason on the form: a new rule is
            // always Active, and its `name` is generated from its targets.
            $exemption = TaskExemption::create([
                'name' => $this->generatedName($data),
                'is_active' => true,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            $this->syncTargetsAndItems($exemption, $data);

            ActivityLog::record('task_exemption_created', 'Task Exemption', "Task exemption \"{$exemption->name}\" created.", [
                'subject_type' => 'TaskExemption',
                'subject_id' => $exemption->id,
                'new_values' => $this->auditValues($exemption),
            ]);

            return $exemption;
        });

        return response()->json([
            'exemption' => $this->present($exemption->fresh(['targets.employee', 'items', 'creator', 'updater'])),
            'message' => 'Task exemption created.',
        ]);
    }

    public function update(Request $request, TaskExemption $taskExemption): JsonResponse
    {
        $data = $this->validated($request, $taskExemption);

        DB::transaction(function () use ($data, $request, $taskExemption) {
            $taskExemption->loadMissing(['targets', 'items']);
            $before = $this->auditValues($taskExemption);

            // Status/reason are no longer edited on the form, so whatever
            // the rule already has is kept; only the generated name follows
            // its (possibly changed) targets.
            $taskExemption->update([
                'name' => $this->generatedName($data),
                'updated_by' => $request->user()->id,
            ]);

            $this->syncTargetsAndItems($taskExemption, $data);

            ActivityLog::record('task_exemption_updated', 'Task Exemption', "Task exemption \"{$taskExemption->name}\" updated.", [
                'subject_type' => 'TaskExemption',
                'subject_id' => $taskExemption->id,
                'old_values' => $before,
                'new_values' => $this->auditValues($taskExemption->fresh(['targets', 'items'])),
            ]);
        });

        return response()->json([
            'exemption' => $this->present($taskExemption->fresh(['targets.employee', 'items', 'creator', 'updater'])),
            'message' => 'Task exemption updated. Changes apply to new offboarding requests only.',
        ]);
    }

    /**
     * The list's Active/Inactive switch. Takes the intended state rather
     * than blindly flipping it, so a double-click or a stale page can never
     * leave the rule in the opposite state from what the switch shows. Like
     * every other change here, it only affects FUTURE offboarding requests.
     */
    public function toggleStatus(Request $request, TaskExemption $taskExemption): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $isActive = (bool) $validated['is_active'];
        $wasActive = $taskExemption->is_active;

        if ($isActive !== $wasActive) {
            $taskExemption->update([
                'is_active' => $isActive,
                'updated_by' => $request->user()->id,
            ]);

            ActivityLog::record('task_exemption_updated', 'Task Exemption', "Task exemption \"{$taskExemption->name}\" " . ($isActive ? 'activated' : 'deactivated') . '.', [
                'subject_type' => 'TaskExemption',
                'subject_id' => $taskExemption->id,
                'old_values' => ['is_active' => $wasActive],
                'new_values' => ['is_active' => $isActive],
            ]);
        }

        return response()->json([
            'exemption' => $this->present($taskExemption->fresh(['targets.employee', 'items', 'creator', 'updater'])),
            'message' => $isActive
                ? 'Task exemption activated. It will apply to new offboarding requests.'
                : 'Task exemption deactivated. New offboarding requests will no longer use it.',
        ]);
    }

    public function destroy(TaskExemption $taskExemption): JsonResponse
    {
        DB::transaction(function () use ($taskExemption) {
            $taskExemption->loadMissing(['targets', 'items']);

            ActivityLog::record('task_exemption_deleted', 'Task Exemption', "Task exemption \"{$taskExemption->name}\" deleted.", [
                'subject_type' => 'TaskExemption',
                'subject_id' => $taskExemption->id,
                'old_values' => $this->auditValues($taskExemption),
            ]);

            // Targets/items cascade with it; every request that already used
            // this rule keeps its own frozen snapshot (name/reason copied in).
            $taskExemption->delete();
        });

        return response()->json([
            'id' => $taskExemption->id,
            'message' => 'Task exemption deleted.',
        ]);
    }

    /**
     * @return array{departments: array<int, string>, positions: array<int, string>, employee_ids: array<int, int>, item_ids: array<int, int>}
     */
    private function validated(Request $request, ?TaskExemption $existing = null): array
    {
        $departmentOptions = $this->departmentOptions();
        $positionOptions = $this->positionOptions();

        $validated = $request->validate([
            'departments' => ['nullable', 'array'],
            'departments.*' => ['string', 'distinct', Rule::in($departmentOptions)],
            'positions' => ['nullable', 'array'],
            'positions.*' => ['string', 'distinct', Rule::in($positionOptions)],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer', 'distinct', 'exists:employees,id'],
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer', 'distinct', 'exists:checklist_items,id'],
        ], [
            'item_ids.required' => 'Select at least one checklist task to exempt.',
            'item_ids.min' => 'Select at least one checklist task to exempt.',
            'departments.*.in' => 'One of the selected departments no longer exists in Employee Master.',
            'positions.*.in' => 'One of the selected positions no longer exists in Employee Master.',
            'employee_ids.*.exists' => 'One of the selected employees no longer exists in Employee Master.',
            'item_ids.*.exists' => 'One of the selected tasks no longer exists. Refresh the page and try again.',
        ]);

        $departments = array_values(array_unique($validated['departments'] ?? []));
        $positions = array_values(array_unique($validated['positions'] ?? []));
        $employeeIds = array_values(array_unique(array_map('intval', $validated['employee_ids'] ?? [])));
        $itemIds = array_values(array_unique(array_map('intval', $validated['item_ids'])));

        if (! $departments && ! $positions && ! $employeeIds) {
            throw ValidationException::withMessages([
                'targets' => 'Select at least one Department, Position, or Employee for this exemption to apply to.',
            ]);
        }

        // Only tasks on ACTIVE checklists can be newly selected. A task that
        // was already on this rule but whose checklist has since been
        // deactivated may stay (it's simply ignored until reactivated —
        // see `TaskExemptionResolver`), so editing an unrelated field never
        // forces the admin to drop it.
        $alreadySelected = $existing ? $existing->items()->pluck('checklist_items.id')->all() : [];
        $inactive = ChecklistItem::whereIn('id', array_diff($itemIds, $alreadySelected))
            ->whereHas('template', fn ($query) => $query->where('is_active', false))
            ->pluck('title');

        if ($inactive->isNotEmpty()) {
            throw ValidationException::withMessages([
                'item_ids' => 'These tasks belong to an inactive checklist and cannot be selected: ' . $inactive->implode(', ') . '.',
            ]);
        }

        return [
            'departments' => $departments,
            'positions' => $positions,
            'employee_ids' => $employeeIds,
            'item_ids' => $itemIds,
        ];
    }

    /**
     * The rule's label — shown on the list, the New Request preview, and
     * every request snapshot ("matched rule") — built from its own targets,
     * since the form no longer asks for an Exemption Name. e.g.
     * "Department: Field Operations · Position: Driver · Employee: Juan Dela Cruz".
     */
    private function generatedName(array $data): string
    {
        $employeeNames = Employee::whereIn('id', $data['employee_ids'])->orderBy('name')->pluck('name');

        $parts = array_filter([
            $data['departments'] ? 'Department: ' . implode(', ', $data['departments']) : null,
            $data['positions'] ? 'Position: ' . implode(', ', $data['positions']) : null,
            $employeeNames->isNotEmpty() ? 'Employee: ' . $employeeNames->implode(', ') : null,
        ]);

        return Str::limit(implode(' · ', $parts), 250);
    }

    private function syncTargetsAndItems(TaskExemption $exemption, array $data): void
    {
        $exemption->targets()->delete();

        $rows = collect($data['departments'])->map(fn ($value) => ['target_type' => TaskExemptionTarget::TYPE_DEPARTMENT, 'target_value' => $value, 'employee_id' => null])
            ->concat(collect($data['positions'])->map(fn ($value) => ['target_type' => TaskExemptionTarget::TYPE_POSITION, 'target_value' => $value, 'employee_id' => null]))
            ->concat(collect($data['employee_ids'])->map(fn ($id) => ['target_type' => TaskExemptionTarget::TYPE_EMPLOYEE, 'target_value' => null, 'employee_id' => $id]));

        foreach ($rows as $row) {
            $exemption->targets()->create($row);
        }

        $exemption->items()->sync($data['item_ids']);
    }

    private function present(TaskExemption $exemption): array
    {
        $targets = $exemption->targets;
        $itemsByTemplate = $exemption->items->groupBy('checklist_template_id');
        $templates = ChecklistTemplate::whereIn('id', $itemsByTemplate->keys())->get()->keyBy('id');

        return [
            'id' => $exemption->id,
            'name' => $exemption->name,
            'reason' => $exemption->reason,
            'is_active' => $exemption->is_active,
            'departments' => $targets->where('target_type', TaskExemptionTarget::TYPE_DEPARTMENT)->pluck('target_value')->values()->all(),
            'positions' => $targets->where('target_type', TaskExemptionTarget::TYPE_POSITION)->pluck('target_value')->values()->all(),
            'employees' => $targets->where('target_type', TaskExemptionTarget::TYPE_EMPLOYEE)
                ->filter(fn ($target) => $target->employee)
                ->map(fn ($target) => [
                    'id' => $target->employee->id,
                    'name' => $target->employee->name,
                    'employee_code' => $target->employee->employee_code,
                ])->values()->all(),
            'item_ids' => $exemption->items->pluck('id')->values()->all(),
            'checklists' => $itemsByTemplate->map(fn (Collection $items, $templateId) => [
                'id' => (int) $templateId,
                'title' => $templates->get($templateId)?->title ?? 'Deleted checklist',
                'is_active' => (bool) $templates->get($templateId)?->is_active,
                'tasks' => $items->sortBy('sort_order')->pluck('title')->values()->all(),
            ])->sortBy('title')->values()->all(),
            'updated_by' => $exemption->updater?->name ?? $exemption->creator?->name,
            'updated_at' => $exemption->updated_at?->format('M d, Y g:i A'),
        ];
    }

    private function auditValues(TaskExemption $exemption): array
    {
        return [
            'name' => $exemption->name,
            'reason' => $exemption->reason,
            'is_active' => $exemption->is_active,
            'targets' => $exemption->targets->map(fn ($target) => $target->target_type . ':' . ($target->target_value ?? $target->employee_id))->values()->all(),
            'item_ids' => $exemption->items->pluck('id')->values()->all(),
        ];
    }

    /**
     * Every ACTIVE checklist with its tasks, for the Create/Edit modal's
     * accordion.
     */
    private function checklistOptions(): Collection
    {
        return ChecklistTemplate::where('is_active', true)
            ->with('items')
            ->orderBy('title')
            ->get()
            ->map(fn (ChecklistTemplate $template) => [
                'id' => $template->id,
                'title' => $template->title,
                'department' => $template->department,
                'is_final_pay_checklist' => $template->is_final_pay_checklist,
                'tasks' => $template->items->map(fn (ChecklistItem $item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                ])->values()->all(),
            ])
            ->values();
    }

    /**
     * Same source as Employee Master's own Department dropdown — the
     * distinct `employees.department` values (there's no department master
     * table in this app).
     */
    private function departmentOptions(): Collection
    {
        return Employee::whereNotNull('department')
            ->where('department', '!=', '')
            ->distinct()
            ->orderBy('department')
            ->pluck('department');
    }

    /**
     * Distinct positions across both `position` and its mirrored
     * `designation` column (Employee Master writes both), deduplicated
     * case-insensitively.
     */
    private function positionOptions(): Collection
    {
        return Employee::whereNotNull('designation')->where('designation', '!=', '')->distinct()->pluck('designation')
            ->concat(Employee::whereNotNull('position')->where('position', '!=', '')->distinct()->pluck('position'))
            ->map(fn ($value) => trim($value))
            ->unique(fn ($value) => Str::lower($value))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }
}
