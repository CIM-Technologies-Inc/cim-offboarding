@props(['employees' => []])

<div x-data="{
        selected: null,
        query: '',
        employeeDropdownOpen: false,
        selectedEmployeeId: '',
        // Replaced on every open with this specific item's own checklist's
        // `assignableEmployees` — employees under that checklist's own
        // Clearance Signatory only, never the full active-employee
        // roster. The static fallback below only ever applies if the
        // dispatched detail somehow omits that field.
        employees: @js($employees->map(fn ($employee) => ['id' => (string) $employee->id, 'name' => $employee->name, 'code' => $employee->employee_code, 'department' => $employee->department])),
        setSelected(detail) {
            this.selected = detail;
            this.query = '';
            this.selectedEmployeeId = '';
            this.employeeDropdownOpen = false;
            if (detail?.assignableEmployees !== undefined) {
                this.employees = detail.assignableEmployees;
            }
        },
        filteredEmployees() {
            const needle = this.query.toLowerCase();
            if (!needle) {
                return this.employees;
            }
            return this.employees.filter((e) =>
                e.name.toLowerCase().includes(needle)
                || e.code.toLowerCase().includes(needle)
                || (e.department || '').toLowerCase().includes(needle)
            );
        },
        selectEmployee(employee) {
            this.selectedEmployeeId = employee.id;
            this.query = `${employee.name} (${employee.code})`;
            this.employeeDropdownOpen = false;
        },
    }" @open-item-assign-modal.window="setSelected($event.detail)">
    <x-ui.modal @open-item-assign-modal.window="open = true" :isOpen="false" class="max-w-[480px]">
        <div class="no-scrollbar relative flex min-h-[460px] w-full max-w-[480px] flex-col overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="selected" x-cloak>
            <template x-if="selected">
                <div class="flex h-full flex-col">
                    <h4 class="text-xl font-semibold text-gray-800 dark:text-white/90">Assign Checklist Item</h4>

                    <div class="mt-4 space-y-1.5 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-xs dark:border-gray-800 dark:bg-white/[0.03]">
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Employee</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.offboardeeName"></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Checklist</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.checklistTitle"></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Checklist Item</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.itemTitle"></span>
                        </div>
                    </div>

                    <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-xs dark:border-gray-800 dark:bg-white/[0.03]">
                        <p class="text-gray-400">Currently Assigned To</p>
                        <p class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">
                            <template x-if="selected.currentApproverName">
                                <span>
                                    <span x-text="selected.currentApproverCode"></span> &mdash; <span x-text="selected.currentApproverName"></span>
                                </span>
                            </template>
                            <template x-if="!selected.currentApproverName">
                                <span>Unassigned</span>
                            </template>
                        </p>
                    </div>

                    <label class="mb-1.5 mt-5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        Assign To
                    </label>
                    <div class="relative" @click.away="employeeDropdownOpen = false">
                        <input type="text" x-model="query" autocomplete="off"
                            @focus="employeeDropdownOpen = true"
                            @input="selectedEmployeeId = ''; employeeDropdownOpen = true"
                            placeholder="Search employee..."
                            class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />

                        <div x-show="employeeDropdownOpen"
                            class="shadow-theme-lg absolute z-50 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                            <template x-for="employee in filteredEmployees()" :key="employee.id">
                                <div @click="selectEmployee(employee)"
                                    class="cursor-pointer border-b border-gray-100 px-4 py-2.5 text-sm last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                                    <span class="text-gray-800 dark:text-white/90" x-text="`${employee.name} (${employee.code})`"></span>
                                    <span class="block text-xs text-gray-400" x-text="employee.department"></span>
                                </div>
                            </template>
                            <div x-show="filteredEmployees().length === 0" class="px-4 py-2.5 text-sm text-gray-400">
                                No employees found
                            </div>
                        </div>
                    </div>

                    <p class="mt-5 text-xs text-gray-400">
                        The original assignment stays on record. If the selected employee doesn't already have a login, one is created automatically using their employee number as both username and initial password, and they'll be emailed to review this item.
                    </p>

                    <div class="mt-auto flex items-center justify-end gap-3 pt-6">
                        <button @click="open = false" type="button"
                            class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                            Cancel
                        </button>
                        <form method="POST" :action="selected.assignItemUrl" x-data="{ processing: false, confirmed: false }"
                            @submit="if (!confirmed) {
                                $event.preventDefault();
                                Swal.fire({
                                    title: 'Reassign this checklist item?',
                                    text: 'The new approver will be responsible for checking this item. The current assignment stays on record.',
                                    icon: 'question',
                                    showCancelButton: true,
                                    confirmButtonText: 'Assign',
                                    cancelButtonText: 'Cancel',
                                    confirmButtonColor: '#145a3a',
                                    cancelButtonColor: '#6b7280',
                                    reverseButtons: true
                                }).then((result) => {
                                    if (result.isConfirmed) {
                                        confirmed = true;
                                        processing = true;
                                        $el.requestSubmit();
                                    }
                                });
                            }">
                            @csrf
                            <input type="hidden" name="employee_id" :value="selectedEmployeeId" />
                            <button type="submit" :disabled="!selectedEmployeeId || processing" data-turbo-submits-with="Assigning..."
                                :class="(!selectedEmployeeId || processing) ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#0f4630]'"
                                class="flex items-center justify-center gap-1.5 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white">
                                Assign
                            </button>
                        </form>
                    </div>
                </div>
            </template>
        </div>
    </x-ui.modal>
</div>
