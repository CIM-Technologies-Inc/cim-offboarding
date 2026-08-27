<div x-data="{
        selected: null,
        selectedTemplateIds: [],
        selectedEmployeeIds: [],
        query: '',
        employeeDropdownOpen: false,
        setSelected(detail) {
            this.selected = detail;
            this.selectedTemplateIds = [];
            this.selectedEmployeeIds = [];
            this.query = '';
            this.employeeDropdownOpen = false;
        },
        toggleTemplate(option) {
            if (!option.eligible) {
                return;
            }
            const idx = this.selectedTemplateIds.indexOf(option.templateId);
            if (idx === -1) {
                this.selectedTemplateIds.push(option.templateId);
            } else {
                this.selectedTemplateIds.splice(idx, 1);
                // Drop any employee no longer offered by the remaining
                // selection, so the multi-select never keeps someone chosen
                // only because of a checklist that was just unchecked.
                const stillOffered = this.candidateEmployees().map((e) => e.id);
                this.selectedEmployeeIds = this.selectedEmployeeIds.filter((id) => stillOffered.includes(id));
            }
        },
        // The union (deduped by id) of every currently-checked checklist's
        // own Clearance-Signatory-group-restricted employee pool — checking
        // more checklists only ever widens who can be picked, never narrows
        // an already-made choice away silently.
        candidateEmployees() {
            if (!this.selected) return [];
            const byId = {};
            (this.selected.checklistPoolOptions || [])
                .filter((o) => this.selectedTemplateIds.includes(o.templateId))
                .forEach((o) => (o.assignableEmployees || []).forEach((e) => (byId[e.id] = e)));
            return Object.values(byId);
        },
        filteredEmployees() {
            const needle = this.query.toLowerCase();
            const pool = this.candidateEmployees();
            if (!needle) {
                return pool;
            }
            return pool.filter((e) =>
                e.name.toLowerCase().includes(needle)
                || e.code.toLowerCase().includes(needle)
                || (e.department || '').toLowerCase().includes(needle)
            );
        },
        toggleEmployee(employee) {
            const idx = this.selectedEmployeeIds.indexOf(employee.id);
            if (idx === -1) {
                this.selectedEmployeeIds.push(employee.id);
            } else {
                this.selectedEmployeeIds.splice(idx, 1);
            }
        },
        selectedEmployeeObjects() {
            const byId = {};
            this.candidateEmployees().forEach((e) => (byId[e.id] = e));
            return this.selectedEmployeeIds.map((id) => byId[id]).filter(Boolean);
        },
        canSubmit() {
            return this.selectedTemplateIds.length > 0 && this.selectedEmployeeIds.length > 0;
        },
    }" @open-assign-checklist-pool-modal.window="setSelected($event.detail)">
    <x-ui.modal @open-assign-checklist-pool-modal.window="open = true" :isOpen="false" class="max-w-[560px]">
        <div class="no-scrollbar relative flex max-h-[85vh] w-full max-w-[560px] flex-col overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="selected" x-cloak>
            <template x-if="selected">
                <div>
                    <h4 class="text-xl font-semibold text-gray-800 dark:text-white/90">Assign Checklist</h4>
                    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400" x-text="selected.name"></p>

                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        Checklist(s)
                    </label>
                    <div class="mb-5 space-y-2">
                        <template x-for="option in (selected.checklistPoolOptions || [])" :key="option.templateId">
                            <label class="flex items-start gap-3 rounded-lg border border-gray-200 px-4 py-3 dark:border-gray-800"
                                :class="option.eligible ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'">
                                <input type="checkbox" :disabled="!option.eligible"
                                    :checked="selectedTemplateIds.includes(option.templateId)"
                                    @change="toggleTemplate(option)"
                                    class="mt-0.5 h-4 w-4 rounded border-gray-300 text-[#145a3a] focus:ring-[#145a3a] dark:border-gray-700 dark:bg-gray-900" />
                                <span>
                                    <span class="block text-sm font-medium text-gray-800 dark:text-white/90" x-text="option.title"></span>
                                    <span class="block text-xs text-gray-400" x-show="!option.eligible">Already has assigned signatories</span>
                                </span>
                            </label>
                        </template>
                    </div>

                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        Employee / Approver
                    </label>

                    <div class="mb-2 flex flex-wrap gap-1.5" x-show="selectedEmployeeObjects().length">
                        <template x-for="employee in selectedEmployeeObjects()" :key="employee.id">
                            <span class="inline-flex items-center gap-1 rounded-full bg-[#145a3a]/10 px-2.5 py-1 text-xs font-medium text-[#145a3a] dark:bg-[#3aa876]/10 dark:text-[#3aa876]">
                                <span x-text="`${employee.name} (${employee.code})`"></span>
                                <button type="button" @click="toggleEmployee(employee)" class="text-[#145a3a] hover:opacity-70 dark:text-[#3aa876]">&times;</button>
                            </span>
                        </template>
                    </div>

                    {{-- Alpine's `@click.away` relies on the click bubbling all the
                         way up to `document` — but `x-ui.modal`'s own content
                         wrapper calls `@click.stop` on every click (so clicking
                         inside the modal never closes it via the backdrop),
                         which swallows that bubble before `.away`'s listener
                         ever sees it. Anything else inside this modal (the
                         checklist checkboxes, the "Assign" button, empty
                         whitespace) is genuinely "outside" this select, but
                         `.away` alone can never detect it here. A `.window.capture`
                         listener sidesteps this correctly: capture-phase
                         listeners on `window` run BEFORE the click reaches its
                         target (and before any bubble-phase `stopPropagation()`
                         can run), so it reliably fires for every outside click —
                         including ones elsewhere in this same modal — while a
                         click on the select itself or its own dropdown, both
                         inside `$refs.employeeSelect`, is correctly ignored. --}}
                    <div class="relative" x-ref="employeeSelect"
                        @click.window.capture="employeeDropdownOpen && $refs.employeeSelect && ! $refs.employeeSelect.contains($event.target) && (employeeDropdownOpen = false)">
                        <input type="text" x-model="query" autocomplete="off"
                            @focus="employeeDropdownOpen = true"
                            :disabled="selectedTemplateIds.length === 0"
                            :placeholder="selectedTemplateIds.length === 0 ? 'Select a checklist first...' : 'Search employee...'"
                            :class="selectedTemplateIds.length === 0 ? 'cursor-not-allowed bg-gray-100 dark:bg-gray-800' : ''"
                            class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />

                        <div x-show="employeeDropdownOpen && selectedTemplateIds.length > 0"
                            class="shadow-theme-lg absolute z-50 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                            <template x-for="employee in filteredEmployees()" :key="employee.id">
                                <div @click="toggleEmployee(employee)"
                                    class="flex cursor-pointer items-center justify-between border-b border-gray-100 px-4 py-2.5 text-sm last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                                    <span>
                                        <span class="text-gray-800 dark:text-white/90" x-text="`${employee.name} (${employee.code})`"></span>
                                        <span class="block text-xs text-gray-400" x-text="employee.department"></span>
                                    </span>
                                    <svg x-show="selectedEmployeeIds.includes(employee.id)" width="16" height="16" viewBox="0 0 20 20" fill="none" class="shrink-0 text-[#145a3a] dark:text-[#3aa876]">
                                        <path d="M4.5 10.5L8 14L15.5 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </div>
                            </template>
                            <div x-show="filteredEmployees().length === 0" class="px-4 py-2.5 text-sm text-gray-400">
                                No employees found
                            </div>
                        </div>
                    </div>

                    <p class="mt-5 text-xs text-gray-400">
                        Eligible task lists under the selected checklist(s) will be assigned directly to the selected employee(s) — split evenly when more than one is selected — so they can start clearing items right away, with no need to click "Check This List" first. If any of them doesn't already have a login, one is created automatically using their employee number as both username and initial password, and they'll be emailed with what was assigned to them.
                    </p>

                    <div class="mt-6 flex items-center justify-end gap-3">
                        <button @click="open = false" type="button"
                            class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                            Cancel
                        </button>
                        <form method="POST" :action="selected.assignPoolUrl" x-data="{ processing: false, confirmed: false }"
                            @submit="if (!confirmed) {
                                $event.preventDefault();
                                Swal.fire({
                                    title: 'Assign selected checklist(s) to these employees?',
                                    html: `${selectedTemplateIds.length} checklist(s) will have their eligible task lists assigned across ${selectedEmployeeIds.length} employee(s). Each of them can start clearing their assigned items immediately.`,
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
                            <template x-for="templateId in selectedTemplateIds" :key="templateId">
                                <input type="hidden" name="checklist_template_ids[]" :value="templateId" />
                            </template>
                            <template x-for="employeeId in selectedEmployeeIds" :key="employeeId">
                                <input type="hidden" name="employee_ids[]" :value="employeeId" />
                            </template>
                            <button type="submit" :disabled="!canSubmit() || processing"
                                :class="(!canSubmit() || processing) ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#0f4630]'"
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
