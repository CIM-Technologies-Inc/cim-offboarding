<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmployeeGroupController extends Controller
{
    public function index(): View
    {
        return view('pages.employee-groups.index', [
            'title' => 'Employee Master',
            'groups' => EmployeeGroup::with(['groupHead', 'employees' => fn ($q) => $q->orderBy('name')])
                ->withCount('employees')
                ->orderBy('name')
                ->get(),
            'employees' => Employee::with('employeeGroup.groupHead')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'employee_code', 'department', 'designation', 'employee_group_id', 'status']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:employee_groups,name'],
            'group_head_employee_id' => ['nullable', 'exists:employees,id'],
        ]);

        EmployeeGroup::create($validated + [
            'is_active' => $request->boolean('is_active', true),
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Group created.');
    }

    /**
     * Updates the group's own name/status and its current Group Head. This
     * only ever changes which employee is registered as the head — it never
     * touches any member employee's own record, so reassigning the head
     * cannot accidentally alter who belongs to the group.
     */
    public function update(Request $request, EmployeeGroup $employeeGroup): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:employee_groups,name,' . $employeeGroup->id],
            'group_head_employee_id' => ['nullable', 'exists:employees,id'],
        ]);

        $employeeGroup->update($validated + [
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('success', 'Group updated.');
    }

    /**
     * Deletes the group only — member employees are never deleted, just
     * detached (their `employee_group_id` reverts to null via the foreign
     * key's `nullOnDelete()`), preserving every employee record intact.
     */
    public function destroy(EmployeeGroup $employeeGroup): RedirectResponse
    {
        $employeeGroup->delete();

        return back()->with('success', 'Group deleted.');
    }

    /**
     * Assigns an existing employee (selected from the Employee Master
     * records) to this group. An employee belongs to at most one group at a
     * time, so assigning them here simply moves their `employee_group_id`
     * — it never creates a duplicate membership record, and moving them
     * from a different group never touches that employee's other stored
     * information.
     *
     * JSON-aware (same convention as `EmailTemplateController::toggleStatus()`):
     * the Employee Master page's fetch()-based "Add Member" call sends
     * `Accept: application/json` so it can update the Members table in
     * place, while a plain form submit (JS disabled, or any other caller)
     * still gets the original redirect-back-with-flash behavior.
     */
    public function addEmployee(Request $request, EmployeeGroup $employeeGroup): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
        ]);

        $employee = Employee::findOrFail($validated['employee_id']);

        abort_if(
            $employee->employee_group_id === $employeeGroup->id,
            422,
            "{$employee->name} is already in this group."
        );

        $employee->update(['employee_group_id' => $employeeGroup->id]);

        $message = "{$employee->name} added to {$employeeGroup->name}.";

        if ($request->wantsJson()) {
            return response()->json([
                'employee' => $employee->fresh(['employeeGroup.groupHead']),
                'message' => $message,
            ]);
        }

        return back()->with('success', $message);
    }

    /**
     * Removes an employee from this group — clears their group assignment
     * only, never deletes the employee record itself. JSON-aware for the
     * same reactive "Remove" action in the Members table — see
     * `addEmployee()` above.
     */
    public function removeEmployee(Request $request, EmployeeGroup $employeeGroup, Employee $employee): RedirectResponse|JsonResponse
    {
        abort_unless($employee->employee_group_id === $employeeGroup->id, 404);

        $employee->update(['employee_group_id' => null]);

        $message = "{$employee->name} removed from {$employeeGroup->name}.";

        if ($request->wantsJson()) {
            return response()->json([
                'employee' => $employee->fresh(['employeeGroup.groupHead']),
                'message' => $message,
            ]);
        }

        return back()->with('success', $message);
    }
}
