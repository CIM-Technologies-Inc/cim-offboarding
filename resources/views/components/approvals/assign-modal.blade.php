@props(['employees' => []])

<div x-data="{
        selected: null,
        query: '',
        employeeDropdownOpen: false,
        selectedEmployeeId: '',
        employees: @js($employees->map(fn ($employee) => ['id' => (string) $employee->id, 'name' => $employee->name, 'code' => $employee->employee_code, 'department' => $employee->department])),
        setSelected(detail) {
            this.selected = detail;
            this.query = '';
            this.selectedEmployeeId = '';
            this.employeeDropdownOpen = false;
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
    }" @open-assign-modal.window="setSelected($event.detail)">
    <x-ui.modal @open-assign-modal.window="open = true" :isOpen="false" class="max-w-[480px]">
        <div class="no-scrollbar relative flex min-h-[420px] w-full max-w-[480px] flex-col overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="selected" x-cloak>
            <template x-if="selected">
                <div class="flex h-full flex-col">
                    <h4 class="text-xl font-semibold text-gray-800 dark:text-white/90">Assign Checklist</h4>
                    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400" x-text="selected.name"></p>

                    <template x-if="selected.delegations && selected.delegations.length">
                        <div class="mb-4 space-y-2">
                            <template x-for="delegation in selected.delegations" :key="delegation.templateTitle">
                                <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-xs dark:border-gray-800 dark:bg-white/[0.03]">
                                    <p class="text-gray-400" x-text="selected.checklistTemplates.length > 1 ? `Currently Assigned To (${delegation.templateTitle})` : 'Currently Assigned To'"></p>
                                    <p class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">
                                        <span x-text="delegation.delegatedEmployeeName"></span>
                                        (<span x-text="delegation.delegatedEmployeeCode"></span>)
                                        &mdash; <span class="capitalize" x-text="delegation.delegationStatus"></span>
                                    </p>
                                </div>
                            </template>
                        </div>
                    </template>

                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        Employee / Approver
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

                    <div class="mt-auto flex items-center justify-end gap-3 pt-6">
                        <button @click="open = false" type="button"
                            class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                            Cancel
                        </button>
                        <form method="POST" :action="selected.assignUrl" x-data="{ processing: false, confirmed: false }"
                            @submit="if (!confirmed) {
                                $event.preventDefault();
                                Swal.fire({
                                    title: (selected.delegations && selected.delegations.length) ? 'Reassign this checklist?' : 'Assign this checklist?',
                                    text: (selected.delegations && selected.delegations.length)
                                        ? 'This replaces the current delegated approver on every checklist in this card. Their previous progress stays on record.'
                                        : 'The selected employee will be able to complete checklist items and add remarks on every checklist in this card, but cannot give final approval.',
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
                            <button type="submit" :disabled="!selectedEmployeeId || processing"
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
