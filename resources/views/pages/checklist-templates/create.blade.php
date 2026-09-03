@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="New Checklist Template" />

    <div x-data="checklistBuilder(@js(old('title', '')), @js(old('employee_group_id', '')), @js((bool) old('is_immediate_head_checklist', false)), @js((bool) old('use_task_assignee_as_signatory', false)), @js(old('department', '')), @js(old('due_in_days', '')), @js(old('items', [['title' => '', 'signatory_id' => '']])), @js($employees->map(fn ($employee) => ['id' => (string) $employee->id, 'name' => $employee->name, 'department' => $employee->department])), @js($employeeGroups), @js($errors->any() ? $errors->first() : null))"
        class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:p-6">
        <form method="POST" action="{{ route('checklist-templates.store') }}" class="flex flex-col">
            @csrf

            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    List Title
                </label>
                <input type="text" name="title" x-model="title" placeholder="e.g. IT Clearance Checklist"
                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
            </div>

            <div class="mt-5">
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-400" :class="useTaskAssigneeAsSignatory ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'">
                    <input type="checkbox" name="is_immediate_head_checklist" value="1" x-model="isImmediateHead"
                        :disabled="useTaskAssigneeAsSignatory"
                        @change="if (isImmediateHead) { employeeGroupId = ''; useTaskAssigneeAsSignatory = false }"
                        class="h-4 w-4 accent-brand-500" />
                    For Immediate Head
                </label>
                <p class="mt-1.5 text-xs text-gray-400">
                    Assigns this checklist exclusively to the Immediate Head selected on each individual offboarding request, instead of the Department Head below.
                </p>
            </div>

            <div class="mt-5">
                <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                    <input type="checkbox" name="use_task_assignee_as_signatory" value="1" x-model="useTaskAssigneeAsSignatory"
                        @change="if (useTaskAssigneeAsSignatory) { employeeGroupId = ''; department = ''; isImmediateHead = false; onGroupChange() }"
                        class="h-4 w-4 accent-brand-500" />
                    Use Task Assignee as Clearance Signatory
                </label>
                <p class="mt-1.5 text-xs text-gray-400">
                    No Clearance Signatory is needed — whichever employee is assigned as Task Assignee on each item below becomes that item's own clearance signatory.
                </p>
            </div>

            <div class="mt-5">
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Clearance Signatory
                </label>
                <select name="employee_group_id" x-model="employeeGroupId" :disabled="isImmediateHead || useTaskAssigneeAsSignatory"
                    @change="onGroupChange()"
                    :class="(isImmediateHead || useTaskAssigneeAsSignatory) ? 'cursor-not-allowed bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500' : 'bg-transparent text-gray-800 dark:text-white/90'"
                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-none px-4 py-2.5 text-sm shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:focus:border-brand-800">
                    <option value="">None</option>
                    @foreach ($departmentHeadGroups as $group)
                        <option value="{{ $group->id }}">{{ $group->name }} — {{ $group->groupHead?->name }} ({{ $group->groupHead?->designation }}, {{ $group->groupHead?->department }})</option>
                    @endforeach
                </select>
                <p class="mt-1.5 text-xs text-gray-400" x-show="!isImmediateHead && !useTaskAssigneeAsSignatory">
                    Clearance signatory has the authority over the whole checklist and is always the final approver. Each item below can have its own independent assignee.
                </p>
                <template x-if="currentGroupName()">
                    <p class="mt-1.5 text-xs font-medium text-[#145a3a] dark:text-[#3aa876]">
                        Signatories below are automatically restricted to <span x-text="currentGroupName()"></span>'s group members (Employee Master).
                    </p>
                </template>
            </div>

            <div class="mt-5">
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Department Checklist
                </label>
                <select name="department" x-model="department" :disabled="useTaskAssigneeAsSignatory"
                    :class="useTaskAssigneeAsSignatory ? 'cursor-not-allowed bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500' : 'bg-transparent text-gray-800 dark:text-white/90'"
                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-none px-4 py-2.5 text-sm shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:focus:border-brand-800">
                    <option value="">None (applies to every department)</option>
                    @foreach ($departments as $dept)
                        <option value="{{ $dept }}">{{ $dept }}</option>
                    @endforeach
                </select>
                <p class="mt-1.5 text-xs text-gray-400">
                    When set, this checklist is only attached to offboarding requests for employees whose own Department exactly matches this value.
                </p>
            </div>

            <div class="mt-5">
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Due (Days)
                </label>
                <input type="number" name="due_in_days" x-model="dueInDays" min="0" placeholder="e.g. 5"
                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
                <p class="mt-1.5 text-xs text-gray-400">
                    Days after this checklist is assigned before it becomes overdue. Leave blank for no due date.
                </p>
            </div>

            <div class="mt-5">
                <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                    <input type="checkbox" name="is_final_pay_checklist" value="1"
                        @checked(old('is_final_pay_checklist'))
                        class="h-4 w-4 accent-brand-500" />
                    For Final Pay Checklist
                </label>
            </div>

            <div class="mt-7">
                <div class="mb-4 flex items-center justify-between">
                    <h5 class="text-lg font-medium text-gray-800 dark:text-white/90">
                        Tasks
                    </h5>
                    <button type="button" @click="addItem()"
                        class="shadow-theme-xs flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                        <svg class="fill-current" width="16" height="16" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M9 3.75V14.25M3.75 9H14.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        Add Item
                    </button>
                </div>

                <div class="space-y-4">
                    <template x-for="(item, index) in items" :key="index">
                        <div class="flex flex-col gap-3 rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                            <div class="flex-1">
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    List Title
                                </label>
                                <input type="text" x-model="item.title" :name="`items[${index}][title]`" placeholder="e.g. Return company laptop"
                                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
                            </div>
                            <div class="relative sm:w-72" @click.away="item.signatory_open = false">
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Task Assignee
                                </label>
                                <input type="hidden" :name="`items[${index}][signatory_id]`" :value="item.signatory_id" />
                                <div class="relative">
                                    <input type="text" x-model="item.signatory_query" autocomplete="off"
                                        @focus="item.signatory_open = true"
                                        @input="item.signatory_id = ''; item.notify_enabled = false; item.signatory_open = true"
                                        placeholder="Search employee..."
                                        class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 pr-9 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
                                    <button type="button" x-show="item.signatory_id" @click="clearSignatory(item)"
                                        class="absolute top-1/2 right-3 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                                        <svg width="16" height="16" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M13.5 4.5L4.5 13.5M4.5 4.5L13.5 13.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </button>
                                </div>

                                <div x-show="item.signatory_open"
                                    class="shadow-theme-lg absolute z-50 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                                    <template x-for="employee in filteredEmployees(item.signatory_query)" :key="employee.id">
                                        <div @click="selectSignatory(item, employee)"
                                            class="cursor-pointer border-b border-gray-100 px-4 py-2.5 text-sm last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                                            <span class="text-gray-800 dark:text-white/90" x-text="employee.name"></span>
                                            <span class="block text-xs text-gray-400" x-text="employee.department"></span>
                                        </div>
                                    </template>
                                    <div x-show="filteredEmployees(item.signatory_query).length === 0"
                                        class="px-4 py-2.5 text-sm text-gray-400">
                                        <span x-show="noEligibleEmployees()">No Task Assignees available — the selected group has no members.</span>
                                        <span x-show="!noEligibleEmployees()">No employees found</span>
                                    </div>
                                </div>
                            </div>
                            <div class="flex sm:pt-8">
                                <button type="button" @click="removeItem(index)" x-show="items.length > 0"
                                    class="flex h-11 w-11 items-center justify-center rounded-lg border border-gray-300 text-error-500 hover:bg-error-50 dark:border-gray-700 dark:hover:bg-error-500/10">
                                    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M13.5 4.5L4.5 13.5M4.5 4.5L13.5 13.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-400">
                                <input type="checkbox" :name="`items[${index}][notify_enabled]`" value="1"
                                    x-model="item.notify_enabled" :disabled="!item.signatory_id"
                                    class="h-4 w-4 accent-brand-500 disabled:cursor-not-allowed disabled:opacity-50" />
                                Enable Scheduled Notification
                            </label>
                            <p class="mt-1 text-xs text-gray-400" x-show="!item.signatory_id">
                                Select a Task Assignee to enable scheduling.
                            </p>

                            <div x-show="item.notify_enabled && item.signatory_id" x-cloak class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                        Email/Notification Template
                                    </label>
                                    <select :name="`items[${index}][email_template_id]`" x-model="item.email_template_id"
                                        class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800">
                                        <option value="">Select a template</option>
                                        @foreach ($emailTemplates as $et)
                                            <option value="{{ $et->id }}">{{ $et->template_name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                        Send Timing
                                    </label>
                                    <select :name="`items[${index}][notify_timing]`" x-model="item.notify_timing"
                                        class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-3 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800">
                                        <option value="before">Before Last Working Day</option>
                                        <option value="after">After Last Working Day</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                        Number of Days
                                    </label>
                                    <input type="number" min="1" step="1" :name="`items[${index}][notify_days]`"
                                        x-model="item.notify_days" placeholder="e.g. 5"
                                        class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
                                </div>
                            </div>
                        </div>
                        </div>
                    </template>
                </div>
            </div>

            <div class="mt-7 flex items-center gap-3 lg:justify-end">
                <a href="{{ route('checklist-templates.index') }}"
                    class="flex w-full justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03] sm:w-auto">
                    Cancel
                </a>
                <button type="submit" data-turbo-submits-with="Saving..."
                    class="flex w-full justify-center rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630] sm:w-auto">
                    Save as Template
                </button>
            </div>
        </form>
    </div>

    <script>
        function checklistBuilder(initialTitle, initialEmployeeGroupId, initialIsImmediateHead, initialUseTaskAssigneeAsSignatory, initialDepartment, initialDueInDays, initialItems, employees, employeeGroups, flashError = null) {
            return {
                title: initialTitle,
                employeeGroupId: initialEmployeeGroupId,
                isImmediateHead: initialIsImmediateHead,
                useTaskAssigneeAsSignatory: initialUseTaskAssigneeAsSignatory,
                department: initialDepartment,
                dueInDays: initialDueInDays,
                employees: employees,
                employeeGroups: employeeGroups,
                items: [],
                init() {
                    const source = initialItems.length ? initialItems : [{ title: '', signatory_id: '' }];
                    this.items = source.map((item) => ({
                        title: item.title || '',
                        signatory_id: item.signatory_id || '',
                        signatory_query: this.labelFor(item.signatory_id),
                        signatory_open: false,
                        notify_enabled: !!item.notify_enabled,
                        email_template_id: item.email_template_id || '',
                        notify_timing: item.notify_timing || 'before',
                        notify_days: item.notify_days || '',
                    }));

                    if (flashError) {
                        window.Swal?.fire({
                            toast: true,
                            position: 'bottom-end',
                            icon: 'error',
                            title: flashError,
                            showConfirmButton: false,
                            timer: 2500,
                            customClass: { container: 'app-toast' },
                        });
                    }
                },
                labelFor(id) {
                    const employee = this.employees.find((e) => e.id === String(id));
                    return employee ? employee.name : '';
                },
                // Matched by the group's own id — never by its Group Head's
                // employee id alone, which two different groups can share
                // (e.g. "Admin and Operations Group" and "Human Resources
                // Group" both headed by the same employee). This is what
                // makes such groups independently selectable/identifiable
                // instead of collapsing into one ambiguous option.
                currentGroup() {
                    if (!this.employeeGroupId) {
                        return null;
                    }
                    return this.employeeGroups.find((g) => String(g.id) === String(this.employeeGroupId)) || null;
                },
                currentGroupName() {
                    return this.currentGroup()?.name || '';
                },
                // The Clearance Signatory (Group Head) is always excluded
                // from the Task Assignee pool — they're already the
                // checklist's overall signatory, so they must never also be
                // pickable as an individual item's Task Assignee, even if
                // they happen to be a flagged member of their own group
                // (the server already excludes them from `group.employeeIds`
                // too — this filter is a belt-and-suspenders match).
                eligibleEmployees() {
                    const group = this.currentGroup();
                    if (!group) {
                        return this.employees;
                    }
                    const memberIds = group.employeeIds
                        .map((id) => String(id))
                        .filter((id) => id !== String(group.headId));
                    return this.employees.filter((e) => memberIds.includes(e.id));
                },
                filteredEmployees(query) {
                    const pool = this.eligibleEmployees();
                    if (!query) {
                        return pool;
                    }
                    const needle = query.toLowerCase();
                    return pool.filter((e) =>
                        e.name.toLowerCase().includes(needle) || e.department.toLowerCase().includes(needle)
                    );
                },
                noEligibleEmployees() {
                    return this.eligibleEmployees().length === 0;
                },
                // Changing the Clearance Signatory changes which group's
                // members are eligible Task Assignees — any item already
                // pointing at someone outside the newly selected group is
                // cleared immediately so the UI never keeps showing a
                // now-invalid assignee (the server independently enforces
                // this too on save via `sanitizeSignatoryId()`).
                onGroupChange() {
                    const eligibleIds = this.eligibleEmployees().map((e) => e.id);
                    this.items.forEach((item) => {
                        if (item.signatory_id && !eligibleIds.includes(String(item.signatory_id))) {
                            this.clearSignatory(item);
                        }
                    });
                },
                selectSignatory(item, employee) {
                    item.signatory_id = employee.id;
                    item.signatory_query = employee.name;
                    item.signatory_open = false;
                },
                clearSignatory(item) {
                    item.signatory_id = '';
                    item.signatory_query = '';
                    item.signatory_open = false;
                    item.notify_enabled = false;
                },
                addItem() {
                    this.items.push({
                        title: '', signatory_id: '', signatory_query: '', signatory_open: false,
                        notify_enabled: false, email_template_id: '', notify_timing: 'before', notify_days: '',
                    });
                },
                removeItem(index) {
                    this.items.splice(index, 1);
                },
            };
        }
    </script>
@endsection
