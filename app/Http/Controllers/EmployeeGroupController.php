<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmployeeGroup;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            'employees' => Employee::with(['employeeGroup.groupHead', 'headEmployee:id,name,employee_code'])
                ->orderBy('name')
                ->get(['id', 'name', 'firstName', 'lastName', 'middleName', 'email', 'employee_code', 'department', 'designation', 'position', 'employee_group_id', 'head_employee_id', 'is_task_assignee', 'status']),
            // Feeds the Add New Employee modal's Department dropdown/Position
            // datalist — see `storeEmployee()`'s own docblock for why
            // Department is a closed dropdown (existing values only) while
            // Position stays free text with suggestions.
            'departmentOptions' => $this->departmentOptions(),
            'positionOptions' => $this->positionOptions(),
            // The "Last Employee Master File Upload" card — see
            // `latestRosterUpload()`'s own docblock for why this reuses
            // `ActivityLog` instead of a new table.
            'latestRosterUpload' => $this->latestRosterUpload(),
        ]);
    }

    /**
     * The Employee Master page's "Last Upload" card reuses `ActivityLog`
     * (`import()`'s own `employee_roster_imported`/`employee_roster_import_failed`
     * entries — see `logFailedImport()`'s docblock for why those are two
     * distinct action keys) rather than a new dedicated upload-history
     * table: every piece of information the card needs — who, when, what
     * file, what outcome, how many records in each bucket — is already
     * captured there on every `import()` call, success or failure, and nothing
     * else in this app writes either of those two action keys (in
     * particular, `storeEmployee()`'s manual single-add logs its own
     * `employee_created` key instead, so it can never be mistaken for a
     * file upload here).
     *
     * Returns null when no Employee Master file has EVER been uploaded on
     * this installation — the view shows its own "not uploaded yet" message
     * for that case instead of a card with placeholder/zeroed-out values.
     */
    private function latestRosterUpload(): ?array
    {
        $log = ActivityLog::where('module', 'Employees')
            ->whereIn('action', ['employee_roster_imported', 'employee_roster_import_failed'])
            ->latest('id')
            ->first();

        if (! $log) {
            return null;
        }

        $values = $log->new_values ?? [];

        return [
            'fileName' => $values['file_name'] ?? null,
            // `created_at` IS the upload's own completion timestamp, not an
            // approximation of it — `import()` runs entirely synchronously
            // within the request (no queue in between), so this is the
            // exact moment the import finished, logged in whatever timezone
            // `config('app.timezone')` is set to app-wide (same as every
            // other timestamp this app already displays).
            'uploadedAt' => $log->created_at,
            'uploadedByName' => $log->employee_name ?: $log->username,
            'status' => $log->status,
            'totalRecords' => (int) ($values['total_records'] ?? 0),
            'successfullyImported' => (int) ($values['successfully_imported'] ?? 0),
            'skippedDuplicateRecords' => (int) ($values['skipped_duplicate_records'] ?? 0),
            'failedRecords' => (int) ($values['failed_records'] ?? 0),
            'failureReason' => $log->failure_reason,
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:employee_groups,name'],
            // The admin selects the Group Head directly (see the Group Head
            // picker in `employee-groups/index.blade.php`) — the picker lets
            // them search by name for convenience, but what it actually
            // submits, and all this validates, is the employee's own unique
            // id (resolved from their `employeeNo`/`employee_code` the
            // moment they're picked), never their name.
            'group_head_employee_id' => ['nullable', 'exists:employees,id'],
        ]);

        $groupHeadId = $validated['group_head_employee_id'] ?? null;
        $autoAddMembers = $request->boolean('auto_add_members');
        $isActive = $request->boolean('is_active', true);
        $createdBy = $request->user()->id;

        DB::transaction(function () use ($validated, $groupHeadId, $autoAddMembers, $isActive, $createdBy) {
            $group = EmployeeGroup::create($validated + [
                // Mirrors the selected Group Head's own department — purely
                // informational/for display (e.g. the Employee Directory's
                // department filter); membership itself is always computed
                // fresh from the head below, never from this stored value.
                'department' => $groupHeadId ? Employee::find($groupHeadId)?->department : null,
                'is_active' => $isActive,
                'created_by' => $createdBy,
            ]);

            $this->ensureGroupHeadHasAccount($groupHeadId);

            // "Automatically Add Group Members" — checked by default in the
            // form, but the admin can uncheck it to create the group with an
            // empty member list instead, adding people manually afterward. Only
            // gates whether `syncDepartmentMembership()` runs for THIS save; it
            // is never persisted on the group itself.
            if ($autoAddMembers) {
                $this->syncDepartmentMembership($group, $groupHeadId, previousGroupHeadEmployeeId: null);
            }

            ActivityLog::record('employee_group_updated', 'Employees', "Employee group \"{$group->name}\" created.", [
                'subject_type' => 'EmployeeGroup',
                'subject_id' => $group->id,
            ]);
        });

        return back()->with('success', 'Group created.');
    }

    /**
     * Updates the group's own name/status and its current Group Head, and
     * — unless "Automatically Add Group Members" was unchecked for this
     * save — keeps membership in sync with whichever employee is registered
     * as the head — see `syncDepartmentMembership()` for exactly what that
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
        $groupHeadId = $validated['group_head_employee_id'] ?? null;
        $autoAddMembers = $request->boolean('auto_add_members');
        $isActive = $request->boolean('is_active');

        DB::transaction(function () use ($employeeGroup, $validated, $isActive, $groupHeadId, $autoAddMembers, $previousGroupHeadId) {
            $employeeGroup->update($validated + [
                'department' => $groupHeadId ? Employee::find($groupHeadId)?->department : null,
                'is_active' => $isActive,
            ]);

            $this->ensureGroupHeadHasAccount($groupHeadId);

            // Same "Automatically Add Group Members" gate as `store()` — see
            // its own comment there. Unchecking it on an edit never removes
            // anyone already in the group; it only skips adding anyone new
            // (and skips the head-change removal side effect below) for this
            // one save.
            if ($autoAddMembers) {
                $this->syncDepartmentMembership($employeeGroup, $groupHeadId, $previousGroupHeadId);
            }

            ActivityLog::record('employee_group_updated', 'Employees', "Employee group \"{$employeeGroup->name}\" updated.", [
                'subject_type' => 'EmployeeGroup',
                'subject_id' => $employeeGroup->id,
                'old_values' => ['group_head_employee_id' => $previousGroupHeadId],
                'new_values' => ['group_head_employee_id' => $groupHeadId],
            ]);
        });

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
     * key's `nullOnDelete()`, same as `checklist_templates.employee_group_id`
     * for any checklist that had this group configured as its Clearance
     * Signatory), preserving every employee record and checklist template
     * intact. Wrapped defensively — every FK pointing at `employee_groups`
     * is already `nullOnDelete()`, so this should never actually fail, but
     * an unexpected DB-level error still surfaces as a normal flashed error
     * message instead of a raw 500 page.
     */
    public function destroy(EmployeeGroup $employeeGroup): RedirectResponse
    {
        $groupName = $employeeGroup->name;

        try {
            $employeeGroup->delete();
        } catch (\Throwable $e) {
            Log::error('Failed to delete employee group.', [
                'employee_group_id' => $employeeGroup->id,
                'exception' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to delete "'.$groupName.'". Please try again.');
        }

        return back()->with('success', 'Group "'.$groupName.'" deleted.');
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

        ActivityLog::record('employee_group_updated', 'Employees', "{$employee->name} added to group \"{$employeeGroup->name}\".", [
            'subject_type' => 'Employee',
            'subject_id' => $employee->id,
        ]);

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
     * Manually creates a single `employees` row from scratch — the
     * one-at-a-time counterpart to `import()`'s whole-roster Excel upload,
     * for an Admin/HR user adding one new hire without building a
     * spreadsheet. Deliberately mirrors `import()`'s own conventions for a
     * brand-new row (see its Pass 1 above) so a manually added employee is
     * indistinguishable from one added via the spreadsheet afterward: same
     * title-cased name parts, same combined `name`, same
     * `designation`/`position` dual-write (kept identical — there's no
     * separate "Position" source anywhere in this app, see
     * `positionOptions()` below), same `status = 'active'` and
     * `date_of_joining = now()` for a genuinely new hire.
     *
     * `employee_group_id` is deliberately left unset, same as a fresh
     * `import()` row — so a manually added employee starts out in the
     * "Unassigned" bucket of the Employee Directory, exactly like the
     * import's own new rows do until a group's Head's department later
     * picks them up (see `syncDepartmentMembership()`).
     *
     * JSON-aware (same convention as `addEmployee()`/`removeEmployee()`):
     * the Add New Employee modal's fetch()-based submit uses this to update
     * the Employee Directory in place without a page reload.
     */
    public function storeEmployee(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'employee_code' => ['required', 'string', 'max:255', 'unique:employees,employee_code'],
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'middleName' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'personal_email' => ['nullable', 'email', 'max:255'],
            // A closed dropdown on the form (only departments that already
            // have at least one employee in them), so it's re-validated
            // server-side against that same list rather than against any
            // free-text value a direct request could otherwise smuggle in.
            'department' => ['required', 'string', Rule::in($this->departmentOptions())],
            // Unlike Department above, Position/`designation` has no
            // existing lookup table or enum ANYWHERE in this app (see
            // `positionOptions()`) — the form offers existing values as
            // suggestions, but a genuinely new position title (the first
            // hire into a brand-new role) must still be allowed through.
            'position' => ['required', 'string', 'max:255'],
            // Optional, like the Excel import's own blank `headID` — but when
            // given, it must be an existing employee who hasn't already been
            // offboarded (someone who's left can't approve anyone's work).
            'head_employee_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->where(fn ($query) => $query->where(
                    fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'offboarded')
                )),
            ],
        ], [
            'employee_code.unique' => 'This Employee No. is already registered in the system.',
            'head_employee_id.exists' => 'The selected Immediate Head is not a valid, active employee record.',
            'head_employee_id.integer' => 'Please select the Immediate Head from the list.',
        ]);

        $firstName = Str::title(strtolower(trim($validated['firstName'])));
        $middleName = Str::title(strtolower(trim($validated['middleName'])));
        $lastName = Str::title(strtolower(trim($validated['lastName'])));

        $headEmployee = ! empty($validated['head_employee_id']) ? Employee::find($validated['head_employee_id']) : null;

        $employee = DB::transaction(function () use ($validated, $firstName, $middleName, $lastName, $headEmployee) {
            $employee = $this->createEmployeeGuardingAgainstNameDuplicate($validated, $firstName, $middleName, $lastName, $headEmployee);

            if ($headEmployee) {
                $this->assignToHeadsGroup($employee, $headEmployee);
            }

            return $employee;
        });

        ActivityLog::record('employee_created', 'Employees', "{$employee->name} added to the Employee Master roster.", [
            'subject_type' => 'Employee',
            'subject_id' => $employee->id,
            'new_values' => $headEmployee ? [
                'head_employee_id' => $headEmployee->id,
                'employee_group_id' => $employee->employee_group_id,
            ] : null,
        ]);

        $message = "{$employee->name} added.";

        if ($request->wantsJson()) {
            return response()->json([
                'employee' => $employee->fresh(['employeeGroup.groupHead', 'headEmployee:id,name,employee_code']),
                'message' => $message,
            ]);
        }

        return back()->with('success', $message);
    }

    /**
     * Re-checks for a same-name duplicate immediately before the actual
     * insert, serialized by a MySQL named lock keyed on the normalized full
     * name — closes the race window an unguarded "check, then separately
     * insert" would otherwise have between two concurrent submissions for
     * the SAME name (neither sees the other's row yet when each runs its
     * own `isDuplicateEmployeeName()` check). `employee_code` already gets
     * this same guarantee for free from its own DB-level unique index; this
     * is the equivalent for the name-based check below, which has no
     * underlying unique index to rely on (see that method's own docblock
     * for why one isn't practical here). Named locks are MySQL-specific and
     * session-scoped (never left stuck "on" — the connection's own session
     * ending releases it automatically even on a crashed/killed request),
     * so this only runs on a `mysql` connection; a non-MySQL connection
     * (never used by this app in practice) just falls back to the
     * unguarded check.
     */
    private function createEmployeeGuardingAgainstNameDuplicate(array $validated, string $firstName, string $middleName, string $lastName, ?Employee $headEmployee = null): Employee
    {
        $usingMysql = DB::connection()->getDriverName() === 'mysql';
        $lockName = 'employee-name:' . sha1(Str::lower($firstName . '|' . $middleName . '|' . $lastName));

        if ($usingMysql) {
            DB::select('SELECT GET_LOCK(?, 5)', [$lockName]);
        }

        try {
            if ($this->isDuplicateEmployeeName($firstName, $middleName, $lastName)) {
                // A single dedicated key (not attached to firstName/middleName/
                // lastName individually) — one clear banner at the top of the
                // modal rather than the same sentence repeated under three
                // separate fields.
                throw ValidationException::withMessages([
                    'duplicate_name' => self::DUPLICATE_NAME_MESSAGE,
                ]);
            }

            return Employee::create([
                'employee_code' => $validated['employee_code'],
                'name' => implode(' ', array_filter([$firstName, $middleName, $lastName])),
                'firstName' => $firstName,
                'lastName' => $lastName,
                'middleName' => $middleName,
                'email' => $validated['email'],
                'personal_email' => ($validated['personal_email'] ?? null) ?: null,
                'department' => $validated['department'],
                'designation' => $validated['position'],
                'position' => $validated['position'],
                // Same pair the Excel import writes for a row's headID: the
                // FK every approval/clearance reader uses (`headEmployee()`),
                // plus the legacy free-text `head` name alongside it.
                'head_employee_id' => $headEmployee?->id,
                'head' => $headEmployee?->name,
                'status' => 'active',
                'date_of_joining' => now(),
            ]);
        } finally {
            if ($usingMysql) {
                DB::select('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
        }
    }

    /**
     * Makes the Add New Employee form's selected Immediate Head the new
     * employee's Group Head too. A Group Head lives on the GROUP
     * (`employee_groups.group_head_employee_id`), not the employee, so this
     * places the employee in a group that head already runs — preferring
     * one whose department matches the new employee's own, else the
     * oldest — and only when the head runs no group yet, creates one for
     * them (named after their department, same convention as the existing
     * groups). Creating it never runs `syncDepartmentMembership()`: nobody
     * else is moved, and the admin can still edit/auto-add members later.
     *
     * The head also gets an approver account, same as picking them on the
     * Create/Edit Group form (`ensureGroupHeadHasAccount()`).
     */
    private function assignToHeadsGroup(Employee $employee, Employee $headEmployee): void
    {
        $headedGroups = EmployeeGroup::where('group_head_employee_id', $headEmployee->id)->orderBy('id')->get();

        $group = $headedGroups->firstWhere('department', $employee->department) ?? $headedGroups->first();

        if (! $group) {
            $group = EmployeeGroup::create([
                'name' => $this->uniqueGroupNameFor($headEmployee),
                'department' => $headEmployee->department,
                'group_head_employee_id' => $headEmployee->id,
                'is_active' => true,
                'created_by' => auth()->id(),
            ]);

            ActivityLog::record('employee_group_updated', 'Employees', "Employee group \"{$group->name}\" created for Group Head {$headEmployee->name}.", [
                'subject_type' => 'EmployeeGroup',
                'subject_id' => $group->id,
            ]);
        }

        $employee->update(['employee_group_id' => $group->id]);

        $this->ensureGroupHeadHasAccount($headEmployee->id);
    }

    /**
     * `employee_groups.name` is unique — tries the head's department first,
     * then "Department – Head Name", then the head's name alone, and finally
     * appends a counter so creation never fails on a name collision.
     */
    private function uniqueGroupNameFor(Employee $headEmployee): string
    {
        $candidates = array_values(array_filter([
            $headEmployee->department,
            $headEmployee->department ? "{$headEmployee->department} – {$headEmployee->name}" : null,
            $headEmployee->name,
        ]));

        foreach ($candidates as $candidate) {
            if (! EmployeeGroup::where('name', $candidate)->exists()) {
                return $candidate;
            }
        }

        $base = end($candidates);
        $suffix = 2;

        while (EmployeeGroup::where('name', "{$base} ({$suffix})")->exists()) {
            $suffix++;
        }

        return "{$base} ({$suffix})";
    }

    private const DUPLICATE_NAME_MESSAGE = 'An employee with the same name already exists in the system. Please verify the employee details before proceeding.';

    /**
     * "Already registered anywhere in the application" per this feature's
     * own spec, deliberately NOT keyed on `employee_code` — the whole point
     * is to catch someone re-entered under a DIFFERENT code, which an
     * `employee_code`-only check (already covered separately by the
     * column's own unique index) can never catch. Matches on First + Last
     * Name always; Middle Name only when BOTH this submission and the
     * existing record actually have one — an existing record with no
     * middle name on file (common for older/imported rows) can't be
     * compared on a field it doesn't have, so First + Last alone is treated
     * as enough in that specific case, per this feature's own spec ("Handle
     * optional or missing middle names correctly"). Every comparison is
     * case- and surrounding-whitespace-insensitive (`LOWER(TRIM(...))` on
     * both sides) so "JUAN DELA CRUZ " and "Juan Dela Cruz" are correctly
     * treated as the same name. Deliberately does NOT fuzzy-match beyond
     * that (no partial/sounds-like matching) — this app's own spec
     * explicitly warns against "overly broad matching that incorrectly
     * prevents different employees with similar names from being
     * registered".
     */
    private function isDuplicateEmployeeName(string $firstName, string $middleName, string $lastName): bool
    {
        return Employee::query()
            ->whereRaw('LOWER(TRIM(firstName)) = ?', [Str::lower(trim($firstName))])
            ->whereRaw('LOWER(TRIM(lastName)) = ?', [Str::lower(trim($lastName))])
            ->where(function ($query) use ($middleName) {
                $query->whereRaw("LOWER(TRIM(IFNULL(middleName, ''))) = ?", [Str::lower(trim($middleName))])
                    ->orWhereNull('middleName')
                    ->orWhere('middleName', '');
            })
            ->exists();
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

        ActivityLog::record('employee_group_updated', 'Employees', "{$employee->name} removed from group \"{$employeeGroup->name}\".", [
            'subject_type' => 'Employee',
            'subject_id' => $employee->id,
        ]);

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

        $wasTaskAssignee = $employee->is_task_assignee;
        $employee->update(['is_task_assignee' => ! $employee->is_task_assignee]);

        ActivityLog::record('employee_group_updated', 'Employees', "{$employee->name}'s Task Assignee eligibility toggled.", [
            'subject_type' => 'Employee',
            'subject_id' => $employee->id,
            'old_values' => ['is_task_assignee' => $wasTaskAssignee],
            'new_values' => ['is_task_assignee' => $employee->is_task_assignee],
        ]);

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
     * Same "no made-up department the roster doesn't actually have"
     * convention already used by `ChecklistTemplateController::departmentOptions()`
     * — distinct, non-blank `employees.department` values, so the Add New
     * Employee modal's dropdown can never offer (and the backend never
     * accepts) a department no current employee actually belongs to.
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
     * Unlike `departmentOptions()` above, Position (`designation`) has no
     * equivalent lookup/enum anywhere in this app — these are only ever
     * suggestions for the Add New Employee modal's datalist, never a
     * closed set the backend validates against (see `storeEmployee()`).
     */
    private function positionOptions(): Collection
    {
        return Employee::whereNotNull('designation')
            ->where('designation', '!=', '')
            ->distinct()
            ->orderBy('designation')
            ->pluck('designation');
    }

    /**
     * The exact 12 columns the Employee Master import expects, in header
     * order. Every one of these must be present in the uploaded file's
     * header row (structure check) — but not every one requires a value on
     * every data row (see the required-value subset in `validateRows()`).
     * `headID` is the head/supervisor's own `employeeNo` — distinct from the
     * free-text `head` column (the head's name) — resolved to the new
     * `head_employee_id` FK at import time (see `import()`).
     */
    private const IMPORT_COLUMNS = [
        'employeeNo', 'lastName', 'firstName', 'middleName', 'position',
        'department', 'email', 'personalEmail', 'supOne', 'supTwo', 'head', 'headID',
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

        // Captured up front, before anything about the file's CONTENTS is
        // even looked at — the Last Upload card (see `latestRosterUpload()`)
        // needs the original filename on every outcome, failures included,
        // not only on a successful import.
        $fileName = $request->file('excel_file')->getClientOriginalName();

        try {
            $sheet = IOFactory::load($request->file('excel_file')->getRealPath())->getActiveSheet();
        } catch (\Throwable $e) {
            $this->logFailedImport($fileName, 'Could not read that file — please upload a valid Excel (.xlsx or .xls) file.');

            return back()->withErrors(['excel_file' => 'Could not read that file — please upload a valid Excel (.xlsx or .xls) file.']);
        }

        $rows = $sheet->toArray(null, true, true, false);

        if (empty($rows)) {
            $this->logFailedImport($fileName, 'The uploaded file is empty.');

            return back()->withErrors(['excel_file' => 'The uploaded file is empty.']);
        }

        $headerRow = array_shift($rows);
        $headerMap = $this->matchHeaders($headerRow);

        if ($headerMap === null) {
            $present = array_keys($this->normalizedHeaders($headerRow));
            $missing = array_filter(self::IMPORT_COLUMNS, fn ($column) => ! in_array(strtolower($column), $present, true));
            $reason = 'Missing required column(s): ' . implode(', ', $missing) . '.';

            $this->logFailedImport($fileName, $reason);

            return back()->withErrors(['excel_file' => $reason]);
        }

        [$validRows, $errors] = $this->validateRows($rows, $headerMap);

        if (! empty($errors)) {
            $shown = array_slice($errors, 0, 5);
            $suffix = count($errors) > 5 ? ' (+' . (count($errors) - 5) . ' more)' : '';
            $reason = implode('; ', $shown) . $suffix;

            // `validateRows()` pushes EVERY non-blank row into `$validRows`
            // unconditionally — "valid" only actually describes its
            // contents once the caller (here) has confirmed `$errors` is
            // empty; with errors present, as here, it's really just "every
            // row this file had," which is exactly the file's own "Total
            // Records". Failed rows are further split into "duplicate
            // employeeNo within this file" vs every other validation reason
            // (see `classifyImportRowFailures()`), matching the Last Upload
            // card's own Skipped/Duplicate vs Failed distinction — NOT added
            // on top of `$validRows`'s own count, which already includes
            // them.
            $totalRows = count($validRows);
            $duplicateRowCount = $this->countDuplicateEmployeeNoRows($errors);

            $this->logFailedImport($fileName, $reason, [
                'total_records' => $totalRows,
                'successfully_imported' => 0,
                'skipped_duplicate_records' => $duplicateRowCount,
                'failed_records' => $totalRows - $duplicateRowCount,
            ]);

            return back()->withErrors(['excel_file' => $reason]);
        }

        $created = 0;
        $updated = 0;
        $deleted = 0;
        $importedCodes = [];

        DB::transaction(function () use ($validRows, &$created, &$updated, &$deleted, &$importedCodes) {
            // Pass 1: create/update every employee first, WITHOUT touching
            // head_employee_id yet — a row's headID can reference an
            // employeeNo that only appears later in the file (or wasn't in
            // the database before this upload at all), so the head must
            // exist in the database before it can be linked to. Keeps each
            // saved model keyed by its own employeeNo for pass 2 below.
            $employeesByCode = [];

            foreach ($validRows as $row) {
                $employee = Employee::firstOrNew(['employee_code' => $row['employeeNo']]);
                $isNew = ! $employee->exists;

                $employee->fill([
                    'name' => $row['name'],
                    // Discrete name parts alongside the combined `name`
                    // above — same title-cased values already computed for
                    // it in `validateRows()`, just also kept as their own
                    // columns. `firstName`/`lastName` are required by
                    // `validateRows()`, so these are never both blank for a
                    // valid row; `middleName` is optional and correctly
                    // stored as null when the file leaves it blank.
                    'firstName' => $row['firstName'] ?: null,
                    'lastName' => $row['lastName'] ?: null,
                    'middleName' => $row['middleName'] ?: null,
                    'email' => $row['email'],
                    'personal_email' => $row['personalEmail'] ?: null,
                    'department' => $row['department'],
                    'designation' => $row['position'] ?: '',
                    // A separate column from `designation` above (which
                    // every existing reader — the Employee Directory's own
                    // "Position" column, Department Head eligibility, etc.
                    // — keeps reading unchanged) so this new column and its
                    // established sibling can never drift apart on import.
                    'position' => $row['position'] ?: null,
                    'sup_one' => $row['supOne'] ?: null,
                    'sup_two' => $row['supTwo'] ?: null,
                    'head' => $row['head'] ?: null,
                ]);

                if ($isNew) {
                    $employee->status = 'active';
                    $employee->date_of_joining = now();
                }

                $employee->save();

                $employeesByCode[$row['employeeNo']] = $employee;
                $importedCodes[] = $row['employeeNo'];
                $isNew ? $created++ : $updated++;
            }

            // Pass 2: every employeeNo referenced by any row's headID is now
            // guaranteed to exist (validated in `validateRows()` against
            // this same file) — resolve and set each employee's
            // head_employee_id. A blank headID clears any previously set
            // head, rather than leaving a stale one from an earlier upload.
            foreach ($validRows as $row) {
                $headEmployee = $row['headID'] !== '' ? ($employeesByCode[$row['headID']] ?? null) : null;

                $employeesByCode[$row['employeeNo']]->update([
                    'head_employee_id' => $headEmployee?->id,
                ]);
            }

            $deleted = Employee::whereNotIn('employee_code', $importedCodes)->count();
            Employee::whereNotIn('employee_code', $importedCodes)->delete();
        });

        $total = $created + $updated;

        // Every key an earlier version of this call already recorded
        // (`created`/`updated`/`deleted` — the whole-ROSTER delta, deletions
        // included) is kept exactly as-is; the `file_name`/`total_records`/
        // `successfully_imported`/`skipped_duplicate_records`/
        // `failed_records` keys alongside them are what `latestRosterUpload()`
        // actually reads for the Last Upload card — a SUCCESSFUL import
        // never skips or fails a row that was validated (an all-or-nothing
        // file either imports completely or not at all), so `total_records`
        // here is just every valid row in THIS file, matching the card's own
        // "Total Records" wording (the file's content, not the net roster
        // change that `deleted` also factors in).
        ActivityLog::record('employee_roster_imported', 'Employees', "Employee Master roster imported: {$created} added, {$updated} updated, {$deleted} removed.", [
            'status' => 'success',
            'new_values' => [
                'created' => $created,
                'updated' => $updated,
                'deleted' => $deleted,
                'file_name' => $fileName,
                'total_records' => $total,
                'successfully_imported' => $total,
                'skipped_duplicate_records' => 0,
                'failed_records' => 0,
            ],
        ]);

        return back()->with('success', "Imported {$total} employee(s) ({$created} added, {$updated} updated, {$deleted} removed).");
    }

    /**
     * The single write path every failure branch of `import()` above uses —
     * `employee_roster_imported` (success) and this (`employee_roster_import_failed`)
     * are deliberately two DIFFERENT action keys, never the same key with
     * just a different `status`, so `latestRosterUpload()` below can tell
     * them apart with a plain `whereIn()` without having to also inspect
     * `status`. A failed attempt writes NOTHING to the `employees` table
     * (see `import()`'s own docblock — validation happens before any DB
     * write), so every count here defaults to 0 unless the caller already
     * worked out more specific per-row numbers (the row-validation failure
     * branch does; the three earlier, more structural failures — unreadable
     * file, empty file, missing columns — never get far enough to count
     * rows meaningfully at all, so they stay at 0 rather than guess).
     */
    private function logFailedImport(string $fileName, string $reason, array $counts = []): void
    {
        ActivityLog::record('employee_roster_import_failed', 'Employees', "Employee Master import failed: {$reason}", [
            'status' => 'failed',
            'failure_reason' => $reason,
            'new_values' => array_merge([
                'file_name' => $fileName,
                'total_records' => 0,
                'successfully_imported' => 0,
                'skipped_duplicate_records' => 0,
                'failed_records' => 0,
            ], $counts),
        ]);
    }

    /**
     * Counts the DISTINCT rows (never double-counting a row that produced
     * more than one error message) whose failure was specifically a
     * duplicate `employeeNo` WITHIN this file — the Last Upload card's own
     * "Skipped/Duplicate Records" number. Everything else that didn't make
     * it in (`total_records - successfully_imported - this count`, computed
     * by the caller) is "Failed Records" instead — per that field's own
     * definition ("records that failed validation OR could not be
     * imported"), which deliberately also covers a row with no problem of
     * its own that still didn't import because this app's `import()` is
     * all-or-nothing (see its own docblock): one bad row blocks every row
     * in the same file, not just itself. Keeping the three displayed counts
     * (Imported/Skipped/Failed) always summing back to Total Records is
     * worth more to an admin reading this card than narrowing "Failed" to
     * only a row's own direct validation error.
     *
     * Deliberately parses the SAME error strings `import()` already shows
     * the admin directly, rather than having `validateRows()` return a
     * second, separately-shaped structure alongside them — one source of
     * truth for what each row's problem actually was.
     *
     * @param  array<int, string>  $errors
     */
    private function countDuplicateEmployeeNoRows(array $errors): int
    {
        $duplicateRows = [];

        foreach ($errors as $error) {
            if (preg_match('/^Row (\d+):/', $error, $matches) && str_contains($error, 'duplicate employeeNo')) {
                $duplicateRows[(int) $matches[1]] = true;
            }
        }

        return count($duplicateRows);
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
        $errors = [];

        // First pass: trim every row's values and drop genuinely blank rows
        // (a common trailing spreadsheet artifact). Also collects every
        // non-blank `employeeNo` in the file up front — since this import
        // replaces the ENTIRE roster in one shot (see `import()`'s
        // docblock), a row's `headID` is validated against employee numbers
        // THIS FILE will leave in place, not just whatever already exists in
        // the database — otherwise a head and their report uploaded together
        // for the first time (or a head listed later in the file than their
        // reports) would wrongly fail.
        $rowsWithValues = [];
        $fileEmployeeNos = [];

        foreach ($rows as $offset => $row) {
            $rowNumber = $offset + 2; // +1 for 0-index, +1 for the header row already shifted off

            $values = [];
            foreach ($headerMap as $column => $index) {
                $values[$column] = trim((string) ($row[$index] ?? ''));
            }

            if (implode('', $values) === '') {
                continue;
            }

            if ($values['employeeNo'] !== '') {
                $fileEmployeeNos[$values['employeeNo']] = true;
            }

            $rowsWithValues[] = [$rowNumber, $values];
        }

        $validRows = [];
        $seenCodes = [];

        foreach ($rowsWithValues as [$rowNumber, $values]) {
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

            // Blank headID is valid and intentionally left unassigned (e.g.
            // the topmost head in the org chart has no supervisor of their
            // own) — only a NON-blank value that fails to match any
            // employeeNo in this file is an error.
            if ($values['headID'] !== '' && ! isset($fileEmployeeNos[$values['headID']])) {
                $errors[] = "Row {$rowNumber}: headID \"{$values['headID']}\" does not match any employeeNo in this file";
            }

            // Title-cased in place (not just for `name` below) so the
            // discrete `firstName`/`middleName`/`lastName` columns `import()`
            // saves are consistently formatted the same way `name` always
            // has been, regardless of how the source file capitalized them.
            $values['firstName'] = $values['firstName'] !== '' ? Str::title(strtolower($values['firstName'])) : '';
            $values['middleName'] = $values['middleName'] !== '' ? Str::title(strtolower($values['middleName'])) : '';
            $values['lastName'] = $values['lastName'] !== '' ? Str::title(strtolower($values['lastName'])) : '';

            $values['name'] = implode(' ', array_filter([
                $values['firstName'] ?: null,
                $values['middleName'] ?: null,
                $values['lastName'] ?: null,
            ]));
            $validRows[] = $values;
        }

        return [$validRows, $errors];
    }
}
