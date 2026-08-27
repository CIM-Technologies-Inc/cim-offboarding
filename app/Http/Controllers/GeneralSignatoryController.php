<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeGroup;
use App\Models\GeneralSignatory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A General Signatory record is deliberately independent of the Offboarding
 * Checklist workflow — it's just a Clearance Signatory plus a Task List,
 * stored for reference, never attached to an offboarding request, never
 * creating an `OffboardingRequestApprover`, and never triggering the
 * approval/notification pipeline `ChecklistApprovalNotifier` drives. This
 * controller deliberately keeps its own small copies of the Task Assignee
 * eligibility rules (`eligibleSignatoryIds()`/`sanitizeSignatoryId()`/
 * `employeeGroupsForPicker()`) rather than reusing `ChecklistTemplateController`'s
 * private methods, so this feature can never regress the existing, more
 * sensitive checklist template code — see the same rules there for the
 * canonical version this mirrors.
 */
class GeneralSignatoryController extends Controller
{
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'clearance_signatory_id' => ['required', 'exists:employees,id'],
            'tasks' => ['nullable', 'array'],
            'tasks.*.title' => ['nullable', 'string', 'max:255'],
            'tasks.*.signatory_id' => ['nullable', 'exists:employees,id'],
        ]);

        $eligibleSignatoryIds = $this->eligibleSignatoryIds((int) $validated['clearance_signatory_id']);
        $tasks = $this->nonBlankTasks($validated['tasks'] ?? []);

        DB::transaction(function () use ($validated, $request, $eligibleSignatoryIds, $tasks) {
            $generalSignatory = GeneralSignatory::create([
                'clearance_signatory_id' => $validated['clearance_signatory_id'],
                'is_active' => true,
                'created_by' => $request->user()->id,
            ]);

            foreach ($tasks as $index => $task) {
                $generalSignatory->tasks()->create([
                    'title' => $task['title'] ?? '',
                    'signatory_id' => $this->sanitizeSignatoryId($task['signatory_id'] ?? null, $eligibleSignatoryIds),
                    'sort_order' => $index,
                ]);
            }
        });

        $this->ensureGeneralSignatoryHasAccount((int) $validated['clearance_signatory_id']);

        return $this->respond($request, 'General Signatory saved.');
    }

    public function update(Request $request, GeneralSignatory $generalSignatory): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'clearance_signatory_id' => ['required', 'exists:employees,id'],
            'tasks' => ['nullable', 'array'],
            'tasks.*.title' => ['nullable', 'string', 'max:255'],
            'tasks.*.signatory_id' => ['nullable', 'exists:employees,id'],
        ]);

        $eligibleSignatoryIds = $this->eligibleSignatoryIds((int) $validated['clearance_signatory_id']);
        $tasks = $this->nonBlankTasks($validated['tasks'] ?? []);

        DB::transaction(function () use ($validated, $generalSignatory, $eligibleSignatoryIds, $tasks) {
            $generalSignatory->update([
                'clearance_signatory_id' => $validated['clearance_signatory_id'],
            ]);

            // Delete-and-recreate is safe here (unlike
            // ChecklistTemplateController::reconcileItems()'s careful
            // in-place matching): nothing else in the schema references
            // `general_signatory_tasks.id` — there's no per-request
            // snapshot/assignment concept for General Signatory, since it
            // never attaches to an offboarding request.
            $generalSignatory->tasks()->delete();

            foreach ($tasks as $index => $task) {
                $generalSignatory->tasks()->create([
                    'title' => $task['title'] ?? '',
                    'signatory_id' => $this->sanitizeSignatoryId($task['signatory_id'] ?? null, $eligibleSignatoryIds),
                    'sort_order' => $index,
                ]);
            }
        });

        $this->ensureGeneralSignatoryHasAccount((int) $validated['clearance_signatory_id']);

        return $this->respond($request, 'General Signatory updated.');
    }

    /**
     * General Signatories are additional Clearance Signatories, so they
     * need the same login access any other Approver does. Mirrors the
     * exact same account-provisioning convention used everywhere else in
     * this app that hands someone new responsibility
     * (`User::findOrCreateApprover()`, e.g.
     * `EmployeeGroupController::ensureGroupHeadHasAccount()` for a Group
     * Head): looked up by the employee's own unique `employee_code` (never
     * name/email), a matching account is left completely untouched — same
     * password, same role, no re-creation — and only a genuinely new
     * account gets created, with the standard `approver` role (via
     * Spatie's `assignRole()`, the same role/permission set configured on
     * the Users page — never a separate or hardcoded role just for General
     * Signatories). Selecting the SAME employee as the General Signatory
     * on several checklists, or re-saving one without changing its
     * signatory, both resolve to this same existing account every time —
     * never a duplicate.
     */
    private function ensureGeneralSignatoryHasAccount(int $clearanceSignatoryId): void
    {
        User::findOrCreateApprover(Employee::findOrFail($clearanceSignatoryId));
    }

    /**
     * The General Signatory modal saves via `fetch()` now (see
     * `general-signatory-modal.blade.php`) so it can close itself and
     * refresh just its own table row set without a full page
     * navigation — an AJAX request gets a bare JSON acknowledgement
     * instead of the old redirect-with-flash-message. The redirect path
     * stays as a fallback for any non-JS/non-fetch submission of this
     * same form.
     */
    private function respond(Request $request, string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message]);
        }

        return redirect()->route('checklist-templates.index')->with('success', $message);
    }

    /**
     * Task Title and Task Assignee are both optional for a General
     * Signatory (unlike a checklist template's items, which still require a
     * title) — a task row is only actually saved if it has SOME content
     * (a title and/or a Task Assignee). A row left completely empty (e.g.
     * the form's default blank row, never touched by the admin) is silently
     * dropped rather than persisted as a junk empty task, so "no task
     * items" and "one still-blank default row" both correctly result in
     * zero saved tasks.
     *
     * @param  array<int, array{title?: string|null, signatory_id?: string|int|null}>  $tasks
     * @return array<int, array{title?: string|null, signatory_id?: string|int|null}>
     */
    private function nonBlankTasks(array $tasks): array
    {
        return array_values(array_filter(
            $tasks,
            fn (array $task) => filled($task['title'] ?? null) || filled($task['signatory_id'] ?? null)
        ));
    }

    public function destroy(GeneralSignatory $generalSignatory): RedirectResponse
    {
        $generalSignatory->delete();

        return redirect()->route('checklist-templates.index')->with('success', 'General Signatory deleted.');
    }

    public function toggleStatus(GeneralSignatory $generalSignatory): RedirectResponse
    {
        $generalSignatory->update(['is_active' => ! $generalSignatory->is_active]);

        return redirect()->route('checklist-templates.index')
            ->with('success', 'General Signatory marked as ' . ($generalSignatory->is_active ? 'active' : 'inactive') . '.');
    }

    /**
     * The set of employee IDs allowed as a task's Task Assignee — the given
     * Clearance Signatory's Employee Master group members who are flagged
     * `is_task_assignee`, EXCLUDING the Clearance Signatory themselves.
     * Returns null ("unrestricted") when the Clearance Signatory isn't
     * registered as any group's Group Head — since the Clearance Signatory
     * picker here is intentionally open to any employee (unlike the
     * checklist template's Group-Head-only picker), this unrestricted case
     * is expected to happen routinely, not just as an edge case.
     */
    private function eligibleSignatoryIds(?int $clearanceSignatoryId): ?array
    {
        if (! $clearanceSignatoryId) {
            return null;
        }

        $group = EmployeeGroup::where('group_head_employee_id', $clearanceSignatoryId)->first();

        if (! $group) {
            return null;
        }

        return $group->employees()
            ->where('is_task_assignee', true)
            ->where('id', '!=', $clearanceSignatoryId)
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * Clears (rather than rejects) a task's submitted Task Assignee when it
     * falls outside the eligible pool — same silent-sync convention as
     * `ChecklistTemplateController::sanitizeSignatoryId()`.
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
