<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeGroup;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\IOFactory;

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
                ->get(['id', 'name', 'email', 'employee_code', 'department', 'designation', 'employee_group_id', 'is_task_assignee', 'status']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:employee_groups,name'],
            'group_head_employee_id' => ['nullable', 'exists:employees,id'],
        ]);

        $group = EmployeeGroup::create($validated + [
            'is_active' => $request->boolean('is_active', true),
            'created_by' => $request->user()->id,
        ]);

        $groupHeadId = $validated['group_head_employee_id'] ?? null;

        $this->ensureGroupHeadHasAccount($groupHeadId);
        $this->syncDepartmentMembership($group, $groupHeadId, previousGroupHeadEmployeeId: null);

        return back()->with('success', 'Group created.');
    }

    /**
     * Updates the group's own name/status and its current Group Head, and
     * keeps membership in sync with whichever employee is registered as
     * the head — see `syncDepartmentMembership()` for exactly what that
     * means. Reassigning the head to someone in a DIFFERENT department can
     * therefore change who belongs to this group; reassigning to someone
     * in the SAME department, or just editing the name/status, only ever
     * adds employees newly hired into that department since the last
     * save — it never removes anyone in that case.
     */
    public function update(Request $request, EmployeeGroup $employeeGroup): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:employee_groups,name,' . $employeeGroup->id],
            'group_head_employee_id' => ['nullable', 'exists:employees,id'],
        ]);

        $previousGroupHeadId = $employeeGroup->group_head_employee_id;

        $employeeGroup->update($validated + [
            'is_active' => $request->boolean('is_active'),
        ]);

        $groupHeadId = $validated['group_head_employee_id'] ?? null;

        $this->ensureGroupHeadHasAccount($groupHeadId);
        $this->syncDepartmentMembership($employeeGroup, $groupHeadId, $previousGroupHeadId);

        return back()->with('success', 'Group updated.');
    }

    /**
     * Group membership is derived from the Group Head's own `department`
     * (the free-text HR field on `employees`, not this group's own
     * curated roster) — every employee who shares that department joins
     * automatically, so an admin never has to add them one by one, and a
     * later hire into that same department is picked up the next time
     * this group is saved at all (create, edit, or just a name change),
     * not only at the moment the group was first created.
     *
     * Adding is unconditional and safe to repeat: setting `employee_group_id`
     * to this group's own id for an employee already in it is a no-op, and
     * moving someone from a DIFFERENT group here is the intended behavior
     * of "membership follows department" — an employee belongs to at most
     * one group at a time, same invariant `addEmployee()` already enforces
     * for a manual add.
     *
     * Removal only ever happens as a side effect of the Group Head
     * changing to someone in a genuinely different department (never on a
     * plain create, and never just from re-saving the same head) — anyone
     * currently in this group who no longer matches the new department is
     * detached (`employee_group_id` set to null), the exact same
     * non-destructive mechanism `removeEmployee()` already uses; their
     * Employee Master record, user account, role, and every other field
     * are completely untouched.
     */
    private function syncDepartmentMembership(EmployeeGroup $group, ?int $groupHeadEmployeeId, ?int $previousGroupHeadEmployeeId): void
    {
        if ($groupHeadEmployeeId === null) {
            return;
        }

        $groupHead = Employee::find($groupHeadEmployeeId);

        if (! $groupHead || ! $groupHead->department) {
            return;
        }

        Employee::where('department', $groupHead->department)
            ->update(['employee_group_id' => $group->id]);

        $departmentChanged = $previousGroupHeadEmployeeId !== null
            && $previousGroupHeadEmployeeId !== $groupHeadEmployeeId
            && Employee::find($previousGroupHeadEmployeeId)?->department !== $groupHead->department;

        if ($departmentChanged) {
            Employee::where('employee_group_id', $group->id)
                ->where('department', '!=', $groupHead->department)
                ->update(['employee_group_id' => null]);
        }
    }

    /**
     * Mirrors the exact same account-provisioning convention already used
     * everywhere else in this app that hands someone new responsibility —
     * checklist delegation, item reassignment, task-assignee notifications
     * (`User::findOrCreateApprover()`): looked up by the employee's own
     * unique `employee_code` (never by name/email, which can collide or
     * change), a matching account is left completely untouched — same
     * password, same role, no re-creation — and only a genuinely new
     * account gets created, with the app's standard Approver role (the same
     * role/permission set configured on the Users page, via Spatie's
     * `assignRole()` — never a separate or hardcoded role just for Group
     * Heads). Selecting the SAME employee as head of several groups over
     * time, or re-saving a group without changing its head, both resolve to
     * this same existing account every time — never a duplicate.
     */
    private function ensureGroupHeadHasAccount(?int $groupHeadEmployeeId): void
    {
        if ($groupHeadEmployeeId === null) {
            return;
        }

        User::findOrCreateApprover(Employee::findOrFail($groupHeadEmployeeId));
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

    /**
     * Flips whether this group member is eligible to be picked as a Task
     * Assignee on an Offboarding Checklist item (see
     * `ChecklistTemplateController::eligibleSignatoryIds()`, which reads
     * this same `is_task_assignee` flag when narrowing the Task Assignee
     * pool to the selected Clearance Signatory's group). Lives on the
     * `Employee` record itself, not scoped to any one group, since an
     * employee belongs to at most one group at a time — this action is just
     * exposed from within the Members table of whichever group they
     * currently belong to. JSON-aware for the same reactive checkbox
     * pattern as `addEmployee()`/`removeEmployee()` above.
     */
    public function toggleTaskAssignee(Request $request, EmployeeGroup $employeeGroup, Employee $employee): RedirectResponse|JsonResponse
    {
        abort_unless($employee->employee_group_id === $employeeGroup->id, 404);

        $employee->update(['is_task_assignee' => ! $employee->is_task_assignee]);

        $message = "{$employee->name} marked as " . ($employee->is_task_assignee ? 'a Task Assignee.' : 'not a Task Assignee.');

        if ($request->wantsJson()) {
            return response()->json([
                'employee' => $employee->fresh(['employeeGroup.groupHead']),
                'message' => $message,
            ]);
        }

        return back()->with('success', $message);
    }

    /**
     * The exact 11 columns the Employee Master import expects, in header
     * order. Every one of these must be present in the uploaded file's
     * header row (structure check) — but not every one requires a value on
     * every data row (see the required-value subset in `validateRows()`).
     */
    private const IMPORT_COLUMNS = [
        'employeeNo', 'lastName', 'firstName', 'middleName', 'position',
        'department', 'email', 'personalEmail', 'supOne', 'supTwo', 'head',
    ];

    /**
     * Replaces the entire Employee Master roster from an uploaded Excel
     * file, using `employeeNo` as the sync key: a matched employee is
     * updated in place (preserving its `id` and every relationship —
     * offboarding history, checklist assignments, group membership — since
     * `updateOrCreate`-style matching never deletes-then-recreates), a new
     * `employeeNo` is inserted, and any existing employee whose
     * `employeeNo` is no longer in the file is deleted (cascading per the
     * same foreign keys that already govern deleting an employee anywhere
     * else in this app). The whole file is validated — structure AND every
     * row's data — before a single database write happens; any failure
     * leaves the existing roster completely untouched.
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'excel_file' => ['required', 'file', 'mimes:xlsx,xls'],
        ]);

        try {
            $sheet = IOFactory::load($request->file('excel_file')->getRealPath())->getActiveSheet();
        } catch (\Throwable $e) {
            return back()->withErrors(['excel_file' => 'Could not read that file — please upload a valid Excel (.xlsx or .xls) file.']);
        }

        $rows = $sheet->toArray(null, true, true, false);

        if (empty($rows)) {
            return back()->withErrors(['excel_file' => 'The uploaded file is empty.']);
        }

        $headerRow = array_shift($rows);
        $headerMap = $this->matchHeaders($headerRow);

        if ($headerMap === null) {
            $present = array_keys($this->normalizedHeaders($headerRow));
            $missing = array_filter(self::IMPORT_COLUMNS, fn ($column) => ! in_array(strtolower($column), $present, true));

            return back()->withErrors(['excel_file' => 'Missing required column(s): ' . implode(', ', $missing) . '.']);
        }

        [$validRows, $errors] = $this->validateRows($rows, $headerMap);

        if (! empty($errors)) {
            $shown = array_slice($errors, 0, 5);
            $suffix = count($errors) > 5 ? ' (+' . (count($errors) - 5) . ' more)' : '';

            return back()->withErrors(['excel_file' => implode('; ', $shown) . $suffix]);
        }

        $created = 0;
        $updated = 0;
        $deleted = 0;
        $importedCodes = [];

        DB::transaction(function () use ($validRows, &$created, &$updated, &$deleted, &$importedCodes) {
            foreach ($validRows as $row) {
                $employee = Employee::firstOrNew(['employee_code' => $row['employeeNo']]);
                $isNew = ! $employee->exists;

                $employee->fill([
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'personal_email' => $row['personalEmail'] ?: null,
                    'department' => $row['department'],
                    'designation' => $row['position'] ?: '',
                    'sup_one' => $row['supOne'] ?: null,
                    'sup_two' => $row['supTwo'] ?: null,
                    'head' => $row['head'] ?: null,
                ]);

                if ($isNew) {
                    $employee->status = 'active';
                    $employee->date_of_joining = now();
                }

                $employee->save();

                $importedCodes[] = $row['employeeNo'];
                $isNew ? $created++ : $updated++;
            }

            $deleted = Employee::whereNotIn('employee_code', $importedCodes)->count();
            Employee::whereNotIn('employee_code', $importedCodes)->delete();
        });

        $total = $created + $updated;

        return back()->with('success', "Imported {$total} employee(s) ({$created} added, {$updated} updated, {$deleted} removed).");
    }

    /**
     * @param  array<int, string>  $headerRow
     * @return array<string, string>
     */
    private function normalizedHeaders(array $headerRow): array
    {
        $normalized = [];

        foreach ($headerRow as $cell) {
            $key = strtolower(trim((string) $cell));

            if ($key !== '') {
                $normalized[$key] = (string) $cell;
            }
        }

        return $normalized;
    }

    /**
     * Case-insensitively matches the uploaded header row against
     * `IMPORT_COLUMNS`, returning `[normalizedColumnName => cellIndex]`, or
     * null if any required column is missing entirely.
     *
     * @param  array<int, string>  $headerRow
     * @return array<string, int>|null
     */
    private function matchHeaders(array $headerRow): ?array
    {
        $byLower = [];

        foreach ($headerRow as $index => $cell) {
            $byLower[strtolower(trim((string) $cell))] = $index;
        }

        $map = [];

        foreach (self::IMPORT_COLUMNS as $column) {
            $index = $byLower[strtolower($column)] ?? null;

            if ($index === null) {
                return null;
            }

            $map[$column] = $index;
        }

        return $map;
    }

    /**
     * Validates every data row against the required/optional fields in
     * `import()`'s docblock, collecting every failure (never stopping at
     * the first one) so a single re-upload can fix everything at once.
     * Returns `[validatedRows, errorMessages]` — `validatedRows` is only
     * meaningful when `errorMessages` is empty, since a single bad row must
     * block the entire file (see `import()`).
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<string, int>  $headerMap
     * @return array{0: array<int, array<string, string>>, 1: array<int, string>}
     */
    private function validateRows(array $rows, array $headerMap): array
    {
        $required = ['employeeNo', 'lastName', 'firstName', 'department', 'email'];
        $validRows = [];
        $errors = [];
        $seenCodes = [];

        foreach ($rows as $offset => $row) {
            $rowNumber = $offset + 2; // +1 for 0-index, +1 for the header row already shifted off

            $values = [];
            foreach ($headerMap as $column => $index) {
                $values[$column] = trim((string) ($row[$index] ?? ''));
            }

            // A completely blank row (common trailing spreadsheet artifact)
            // is silently skipped rather than reported as an error.
            if (implode('', $values) === '') {
                continue;
            }

            foreach ($required as $field) {
                if ($values[$field] === '') {
                    $errors[] = "Row {$rowNumber}: {$field} is required";
                }
            }

            if ($values['email'] !== '' && ! filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Row {$rowNumber}: email is not a valid email address";
            }

            if ($values['personalEmail'] !== '' && ! filter_var($values['personalEmail'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Row {$rowNumber}: personalEmail is not a valid email address";
            }

            if ($values['employeeNo'] !== '') {
                if (isset($seenCodes[$values['employeeNo']])) {
                    $errors[] = "Row {$rowNumber}: duplicate employeeNo \"{$values['employeeNo']}\" (already used on row {$seenCodes[$values['employeeNo']]})";
                } else {
                    $seenCodes[$values['employeeNo']] = $rowNumber;
                }
            }

            $nameParts = array_filter([
                $values['firstName'] !== '' ? Str::title(strtolower($values['firstName'])) : null,
                $values['middleName'] !== '' ? Str::title(strtolower($values['middleName'])) : null,
                $values['lastName'] !== '' ? Str::title(strtolower($values['lastName'])) : null,
            ]);

            $values['name'] = implode(' ', $nameParts);
            $validRows[] = $values;
        }

        return [$validRows, $errors];
    }
}
