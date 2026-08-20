@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="New Checklist Template" />

    <div x-data="checklistBuilder(@js(old('title', '')), @js(old('department_head_id', '')), @js((bool) old('is_immediate_head_checklist', false)), @js(old('department', '')), @js(old('due_in_days', '')), @js((bool) old('is_general_signatory', false)), @js(old('items', [['title' => '', 'signatory_id' => '']])), @js($employees->map(fn ($employee) => ['id' => (string) $employee->id, 'name' => $employee->name, 'department' => $employee->department])), @js($employeeGroups), @js($errors->any() ? $errors->first() : null))"
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
                <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                    <input type="checkbox" name="is_immediate_head_checklist" value="1" x-model="isImmediateHead"
                        @change="if (isImmediateHead) departmentHeadId = ''"
                        class="h-4 w-4 accent-brand-500" />
                    Immediate Head
                </label>
                <p class="mt-1.5 text-xs text-gray-400">
                    Assigns this checklist exclusively to the Immediate Head selected on each individual offboarding request, instead of the Department Head below.
                </p>
            </div>

            <div class="mt-5">
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Department Head
                </label>
                <select name="department_head_id" x-model="departmentHeadId" :disabled="isImmediateHead"
                    :class="isImmediateHead ? 'cursor-not-allowed bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500' : 'bg-transparent text-gray-800 dark:text-white/90'"
                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-none px-4 py-2.5 text-sm shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:focus:border-brand-800">
                    <option value="">None</option>
                    @foreach ($departmentHeads as $head)
                        <option value="{{ $head->id }}">{{ $head->name }} ({{ $head->designation }}, {{ $head->department }})</option>
                    @endforeach
                </select>
                <p class="mt-1.5 text-xs text-gray-400" x-show="!isImmediateHead">
                    This department head has the authority over the whole checklist and is always the final approver. Each item below can have its own independent approver — if any item's approver differs from the department head, the checklist auto-approves once every item is checked, with no manual approval step.
                </p>
                <template x-if="currentGroupName()">
                    <p class="mt-1.5 text-xs font-medium text-[#145a3a] dark:text-[#3aa876]">
                        Signatories below are automatically restricted to <span x-text="currentGroupName()"></span>'s group members (Employee Master).
                    </p>
                </template>
            </div>

            <div class="mt-5">
                <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                    <input type="checkbox" name="is_general_signatory" value="1" x-model="isGeneralSignatory"
                        class="h-4 w-4 accent-brand-500" />
                    General Signatory
                </label>
                <!-- <p class="mt-1.5 text-xs text-gray-400">
                    Adds this checklist as an additional signatory on the Clearance Form and removes the requirement to add any checklist items below — you can still add items now or later if this checklist should also use regular item approval. The Department Head above must still be selected for this signatory to appear on the Approval page.
                </p> -->
            </div>

            <div class="mt-5">
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Per Department
                </label>
                <select name="department" x-model="department"
                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800">
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
                    Final Pay Checklist
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
                        <div class="flex flex-col gap-3 rounded-xl border border-gray-200 p-4 dark:border-gray-800 sm:flex-row sm:items-start">
                            <div class="flex-1">
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Items for turn over
                                </label>
                                <input type="text" x-model="item.title" :name="`items[${index}][title]`" placeholder="e.g. Return company laptop"
                                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
                            </div>
                            <div class="relative sm:w-72" @click.away="item.signatory_open = false">
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Signatory
                                </label>
                                <input type="hidden" :name="`items[${index}][signatory_id]`" :value="item.signatory_id" />
                                <div class="relative">
                                    <input type="text" x-model="item.signatory_query" autocomplete="off"
                                        @focus="item.signatory_open = true"
                                        @input="item.signatory_id = ''; item.signatory_open = true"
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
                                        No employees found
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
                    </template>
                </div>
            </div>

            <div class="mt-7 flex items-center gap-3 lg:justify-end">
                <a href="{{ route('checklist-templates.index') }}"
                    class="flex w-full justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03] sm:w-auto">
                    Cancel
                </a>
                <button type="submit"
                    class="flex w-full justify-center rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630] sm:w-auto">
                    Save as Template
                </button>
            </div>
        </form>
    </div>

    <script>
        function checklistBuilder(initialTitle, initialDepartmentHeadId, initialIsImmediateHead, initialDepartment, initialDueInDays, initialIsGeneralSignatory, initialItems, employees, employeeGroups, flashError = null) {
            return {
                title: initialTitle,
                departmentHeadId: initialDepartmentHeadId,
                isImmediateHead: initialIsImmediateHead,
                department: initialDepartment,
                dueInDays: initialDueInDays,
                isGeneralSignatory: initialIsGeneralSignatory,
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
                currentGroup() {
                    if (!this.departmentHeadId) {
                        return null;
                    }
                    return this.employeeGroups.find((g) => String(g.headId) === String(this.departmentHeadId)) || null;
                },
                currentGroupName() {
                    return this.currentGroup()?.name || '';
                },
                eligibleEmployees() {
                    const group = this.currentGroup();
                    if (!group) {
                        return this.employees;
                    }
                    const memberIds = group.employeeIds.map((id) => String(id));
                    memberIds.push(String(this.departmentHeadId));
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
                selectSignatory(item, employee) {
                    item.signatory_id = employee.id;
                    item.signatory_query = employee.name;
                    item.signatory_open = false;
                },
                clearSignatory(item) {
                    item.signatory_id = '';
                    item.signatory_query = '';
                    item.signatory_open = false;
                },
                addItem() {
                    this.items.push({ title: '', signatory_id: '', signatory_query: '', signatory_open: false });
                },
                removeItem(index) {
                    this.items.splice(index, 1);
                },
            };
        }
    </script>
@endsection
