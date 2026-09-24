@props(['employees' => [], 'employeeGroups' => []])

<div x-data="generalSignatoryModal(@js($employees), @js($employeeGroups), @js(old('clearance_signatory_id')), @js(old('tasks', [])), @js(old('_general_signatory_id')), @js($errors->any() ? $errors->first() : null), @js(old('checklist_classification', 'primary')), @js(old('due_in_days', '19')))"
    @open-general-signatory-modal.window="openModal($event.detail)">
    <x-ui.modal x-data="{ open: false }" @open-general-signatory-modal.window="open = true" :isOpen="false" class="w-full sm:w-[70vw] sm:max-w-[70vw]">
        <div class="relative max-h-[85vh] w-full overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-cloak>
            <h4 class="mb-1 text-xl font-semibold text-gray-800 dark:text-white/90" x-text="editingId ? 'Edit General Signatory' : 'Add General Signatory'"></h4>
            <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                A Clearance Signatory and their task list — independent of the Offboarding Checklist workflow.
            </p>

            <form :action="formAction()" method="POST" @submit.prevent="submitViaFetch($event).then((ok) => { if (ok) open = false; })">
                @csrf
                <input type="hidden" name="_general_signatory_id" :value="editingId" />
                <template x-if="editingId">
                    <input type="hidden" name="_method" value="PUT" />
                </template>

                <div class="relative" @click.away="clearanceSignatoryOpen = false">
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        Clearance Signatory
                    </label>
                    <input type="hidden" name="clearance_signatory_id" :value="clearanceSignatoryId" />
                    <div class="relative">
                        <input type="text" x-model="clearanceSignatoryQuery" autocomplete="off"
                            @focus="clearanceSignatoryOpen = true"
                            @input="clearanceSignatoryId = ''; clearanceSignatoryOpen = true; onClearanceSignatoryChange()"
                            placeholder="Search by employee ID or name..."
                            class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 pr-9 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
                        <button type="button" x-show="clearanceSignatoryId" @click="clearClearanceSignatory()"
                            class="absolute top-1/2 right-3 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                            <svg width="16" height="16" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M13.5 4.5L4.5 13.5M4.5 4.5L13.5 13.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </button>
                    </div>

                    <div x-show="clearanceSignatoryOpen"
                        class="shadow-theme-lg absolute z-50 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                        <template x-for="employee in filteredClearanceEmployees(clearanceSignatoryQuery)" :key="employee.id">
                            <div @click="selectClearanceSignatory(employee)"
                                class="cursor-pointer border-b border-gray-100 px-4 py-2.5 text-sm last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                                <span class="text-gray-800 dark:text-white/90" x-text="employee.name"></span>
                                <span class="block text-xs text-gray-400" x-text="`${employee.employee_code || ''} · ${employee.department}`"></span>
                            </div>
                        </template>
                        <div x-show="filteredClearanceEmployees(clearanceSignatoryQuery).length === 0"
                            class="px-4 py-2.5 text-sm text-gray-400">
                            No employees found
                        </div>
                    </div>
                    <template x-if="currentGroupName()">
                        <p class="mt-1.5 text-xs font-medium text-[#145a3a] dark:text-[#3aa876]">
                            Task Assignees below are automatically restricted to <span x-text="currentGroupName()"></span>'s group members (Employee Master).
                        </p>
                    </template>
                </div>

                <div class="mt-6">
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        Clearance Signing Deadline
                    </label>
                    <input type="number" name="due_in_days" x-model="dueInDays" min="0" placeholder="e.g. 19"
                        class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
                    <p class="mt-1.5 text-xs text-gray-400">
                        Days after the offboardee's Last Working Day this General Signatory has to complete/approve their assigned checklist. Leave blank for no clearance signing due date.
                    </p>
                </div>

                <div class="mt-6">
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        Checklist Priority
                    </label>
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                        <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                            <input type="radio" name="checklist_classification" value="primary" required
                                x-model="classification"
                                class="h-4 w-4 accent-brand-500" />
                            Core
                        </label>
                        <!-- <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                            <input type="radio" name="checklist_classification" value="secondary"
                                x-model="classification"
                                class="h-4 w-4 accent-brand-500" />
                            Secondary
                        </label> -->
                        <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                            <input type="radio" name="checklist_classification" value="final_pay"
                                x-model="classification"
                                class="h-4 w-4 accent-brand-500" />
                            For Final Pay Checklist
                        </label>
                    </div>
                    <p class="mt-1.5 text-xs text-gray-400">
                        Determines when this General Signatory is notified — Core first, then Secondary, then Final Pay — following the same Sequential/Parallel Approval Workflow configured for the Offboarding Request.
                    </p>
                </div>

                <div class="mt-7">
                    <div class="mb-4 flex items-center justify-between">
                        <h5 class="text-lg font-medium text-gray-800 dark:text-white/90">Task List</h5>
                        <button type="button" @click="addTask()"
                            class="shadow-theme-xs flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                            <svg class="fill-current" width="16" height="16" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 3.75V14.25M3.75 9H14.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            Add Task
                        </button>
                    </div>

                    <div class="space-y-4">
                        <template x-for="(task, index) in tasks" :key="index">
                            <div class="flex flex-col gap-3 rounded-xl border border-gray-200 p-4 dark:border-gray-800 sm:flex-row sm:items-start">
                                <input type="hidden" :name="`tasks[${index}][id]`" :value="task.id" />
                                <div class="flex-1">
                                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                        Task Title
                                    </label>
                                    <input type="text" x-model="task.title" :name="`tasks[${index}][title]`" placeholder="e.g. Return company laptop"
                                        class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
                                </div>
                                <div class="relative sm:w-72" @click.away="task.signatory_open = false">
                                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                        Task Assignee
                                    </label>
                                    <input type="hidden" :name="`tasks[${index}][signatory_id]`" :value="task.signatory_id" />
                                    <div class="relative">
                                        <input type="text" x-model="task.signatory_query" autocomplete="off"
                                            @focus="task.signatory_open = true"
                                            @input="task.signatory_id = ''; task.signatory_open = true"
                                            placeholder="Search employee..."
                                            class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 pr-9 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
                                        <button type="button" x-show="task.signatory_id" @click="clearTaskSignatory(task)"
                                            class="absolute top-1/2 right-3 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                                            <svg width="16" height="16" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <path d="M13.5 4.5L4.5 13.5M4.5 4.5L13.5 13.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        </button>
                                    </div>

                                    <div x-show="task.signatory_open"
                                        class="shadow-theme-lg absolute z-50 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                                        <template x-for="employee in filteredEmployees(task.signatory_query)" :key="employee.id">
                                            <div @click="selectTaskSignatory(task, employee)"
                                                class="cursor-pointer border-b border-gray-100 px-4 py-2.5 text-sm last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                                                <span class="text-gray-800 dark:text-white/90" x-text="employee.name"></span>
                                                <span class="block text-xs text-gray-400" x-text="employee.department"></span>
                                            </div>
                                        </template>
                                        <div x-show="filteredEmployees(task.signatory_query).length === 0"
                                            class="px-4 py-2.5 text-sm text-gray-400">
                                            <span x-show="noEligibleEmployees()">No Task Assignees available — the selected group has no members.</span>
                                            <span x-show="!noEligibleEmployees()">No employees found</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex sm:pt-8">
                                    <button type="button" @click="removeTask(index)" x-show="tasks.length > 0"
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

                <div class="mt-7 flex items-center justify-end gap-3">
                    <button type="button" @click="open = false" :disabled="saving"
                        class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                        Cancel
                    </button>
                    <button type="submit" :disabled="saving" data-turbo-submits-with="Saving..."
                        class="flex items-center justify-center gap-2 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630] disabled:cursor-not-allowed disabled:opacity-70">
                        <span x-show="saving" class="h-4 w-4 animate-spin rounded-full border-2 border-solid border-white border-t-transparent"></span>
                        <span x-text="saving ? (editingId ? 'Updating...' : 'Saving...') : (editingId ? 'Update' : 'Save')"></span>
                    </button>
                </div>
            </form>
        </div>
    </x-ui.modal>
</div>

<script>
    function generalSignatoryModal(employees, employeeGroups, oldClearanceSignatoryId, oldTasks, oldEditingId, flashError, oldClassification, oldDueInDays) {
        return {
            editingId: null,
            clearanceSignatoryId: '',
            clearanceSignatoryQuery: '',
            clearanceSignatoryOpen: false,
            classification: 'primary',
            dueInDays: '19',
            tasks: [],
            saving: false,
            employees: employees,
            employeeGroups: employeeGroups,
            init() {
                // A failed store()/update() redirects back to this same
                // index page with `old()` input + a validation error — since
                // that's a fresh page load (not a fetch/SPA update), `saving`
                // naturally starts false again here, which already satisfies
                // "remove the loader / re-enable the buttons" on failure. All
                // that's left is reopening the modal (closed by default) with
                // the submitted values restored, so the user sees the form
                // open with their input intact and the error shown, instead
                // of a silently-closed modal on an otherwise-plain page.
                //
                // Gated on `flashError` alone — NOT also on
                // `oldClearanceSignatoryId === null`, since the single most
                // likely validation failure (Clearance Signatory left blank)
                // is exactly the case where that value IS null. Checking it
                // here would silently swallow that exact failure: the modal
                // would stay closed and the error would never be shown, the
                // opposite of what a failed save must do.
                if (!flashError) {
                    return;
                }

                // Dispatching the same window event the Add/Edit buttons use
                // both opens the modal (the x-ui.modal instance listens for
                // it too) and — for edit mode — fully restores state via
                // openModal() below, since its `record` shape matches
                // exactly. Create mode has no `record` for openModal() to
                // read, so its old() values are restored manually right
                // after (dispatch is synchronous, so openModal() has already
                // run and reset to blank defaults by the time we get here).
                //
                // Deferred to $nextTick(): this init() runs while Alpine is
                // still walking the DOM to set up every component on the
                // page, in document order — since this wrapper is the
                // parent of the modal element it's targeting, that child's
                // own window-event listener may not be registered yet at
                // this exact moment. Dispatching immediately would fire into
                // a listener that doesn't exist yet, so the modal would
                // silently never open on page load — $nextTick() waits until
                // Alpine has finished this pass, guaranteeing the listener
                // is live first.
                this.$nextTick(() => {
                    const detail = oldEditingId
                        ? { mode: 'edit', record: { id: oldEditingId, clearanceSignatoryId: oldClearanceSignatoryId, tasks: oldTasks || [], classification: oldClassification } }
                        : { mode: 'create' };

                    this.$dispatch('open-general-signatory-modal', detail);

                    if (!oldEditingId) {
                        this.clearanceSignatoryId = oldClearanceSignatoryId ? String(oldClearanceSignatoryId) : '';
                        this.clearanceSignatoryQuery = this.clearanceLabelFor(this.clearanceSignatoryId);
                        this.classification = oldClassification || 'primary';
                        this.dueInDays = oldDueInDays ?? '19';
                        this.tasks = (oldTasks && oldTasks.length ? oldTasks : [{ title: '', signatory_id: '' }]).map((t) => ({
                            id: t.id || null,
                            title: t.title || '',
                            signatory_id: t.signatory_id ? String(t.signatory_id) : '',
                            signatory_query: this.labelFor(t.signatory_id),
                            signatory_open: false,
                        }));
                    }
                });

                window.Swal?.fire({
                    toast: true,
                    position: 'bottom-end',
                    icon: 'error',
                    title: flashError,
                    showConfirmButton: false,
                    timer: 3000,
                    customClass: { container: 'app-toast' },
                });
            },
            openModal(detail) {
                if (detail.mode === 'edit' && detail.record) {
                    this.editingId = detail.record.id;
                    this.clearanceSignatoryId = detail.record.clearanceSignatoryId ? String(detail.record.clearanceSignatoryId) : '';
                    this.clearanceSignatoryQuery = this.clearanceLabelFor(this.clearanceSignatoryId);
                    this.classification = detail.record.classification || 'primary';
                    this.dueInDays = detail.record.dueInDays ?? '';
                    const source = detail.record.tasks && detail.record.tasks.length ? detail.record.tasks : [{ title: '', signatory_id: '' }];
                    this.tasks = source.map((t) => ({
                        id: t.id || null,
                        title: t.title || '',
                        signatory_id: t.signatory_id ? String(t.signatory_id) : '',
                        signatory_query: this.labelFor(t.signatory_id),
                        signatory_open: false,
                    }));
                } else {
                    this.editingId = null;
                    this.clearanceSignatoryId = '';
                    this.clearanceSignatoryQuery = '';
                    this.classification = 'primary';
                    this.dueInDays = '19';
                    this.tasks = [{ id: null, title: '', signatory_id: '', signatory_query: '', signatory_open: false }];
                }
                this.clearanceSignatoryOpen = false;
            },
            formAction() {
                return this.editingId ? `/general-signatories/${this.editingId}` : '/general-signatories';
            },
            // Submits asynchronously so the modal can close and just the
            // General Signatory table can refresh, without a full page
            // navigation — see GeneralSignatoryController::respond(), which
            // detects this fetch's `Accept: application/json` header and
            // returns a bare JSON acknowledgement instead of its old
            // redirect-with-flash-message. Resolves `true` on success (the
            // form's @submit handler closes the modal) or `false` on
            // failure (modal stays open, entered data and error both stay
            // visible so the user can correct it).
            async submitViaFetch(event) {
                if (this.saving) {
                    return false;
                }

                this.saving = true;
                const form = event.target;

                try {
                    const response = await window.fetchWithTimeout(form.action, {
                        method: 'POST',
                        headers: { Accept: 'application/json' },
                        body: new FormData(form),
                    });
                    const data = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        this.saving = false;
                        const message = data.errors ? Object.values(data.errors).flat()[0] : (data.message || 'Failed to save General Signatory.');
                        window.Swal?.fire({
                            toast: true,
                            position: 'bottom-end',
                            icon: 'error',
                            title: message,
                            showConfirmButton: false,
                            timer: 3000,
                            customClass: { container: 'app-toast' },
                        });

                        return false;
                    }

                    await this.refreshTable();
                    this.saving = false;
                    window.Swal?.fire({
                        toast: true,
                        position: 'bottom-end',
                        icon: 'success',
                        title: data.message || 'Saved.',
                        showConfirmButton: false,
                        timer: 2000,
                        customClass: { container: 'app-toast' },
                    });

                    return true;
                } catch (e) {
                    this.saving = false;
                    window.Swal?.fire({
                        toast: true,
                        position: 'bottom-end',
                        icon: 'error',
                        title: e?.name === 'AbortError'
                            ? 'This is taking longer than expected. Please check before trying again.'
                            : 'Network error — please try again.',
                        showConfirmButton: false,
                        timer: 3000,
                        customClass: { container: 'app-toast' },
                    });

                    return false;
                }
            },
            // Re-fetches this same page and swaps in just the fresh
            // #general-signatories-tbody content — reuses the exact
            // server-rendered row markup (status toggle, Edit/View/Delete
            // actions, task/assignee summaries) without duplicating any of
            // that logic in JS, and leaves everything else on the page —
            // including the checklist templates table — completely
            // untouched. Newly inserted rows are picked up automatically by
            // Alpine's own DOM-mutation observer, so their x-data/@click
            // bindings work immediately with no manual re-init.
            async refreshTable() {
                const response = await fetch(window.location.href, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                const html = await response.text();
                const freshTbody = new DOMParser().parseFromString(html, 'text/html').getElementById('general-signatories-tbody');
                const currentTbody = document.getElementById('general-signatories-tbody');

                if (freshTbody && currentTbody) {
                    currentTbody.innerHTML = freshTbody.innerHTML;
                }
            },
            labelFor(id) {
                const employee = this.employees.find((e) => e.id === String(id));
                return employee ? employee.name : '';
            },
            clearanceLabelFor(id) {
                if (!id) {
                    return '';
                }
                const employee = this.employees.find((e) => String(e.id) === String(id));
                return employee ? `${employee.name} (${employee.employee_code || ''})` : '';
            },
            // Deduplicates by employee id — the same employee must never
            // appear twice in the dropdown even if the source list somehow
            // contains a repeated row.
            filteredClearanceEmployees(query) {
                const seen = new Set();
                const pool = this.employees.filter((e) => {
                    if (seen.has(e.id)) {
                        return false;
                    }
                    seen.add(e.id);
                    return true;
                });

                if (!query) {
                    return pool;
                }

                const needle = query.toLowerCase();
                return pool.filter((e) =>
                    e.name.toLowerCase().includes(needle) || (e.employee_code || '').toLowerCase().includes(needle)
                );
            },
            selectClearanceSignatory(employee) {
                this.clearanceSignatoryId = employee.id;
                this.clearanceSignatoryQuery = this.clearanceLabelFor(employee.id);
                this.clearanceSignatoryOpen = false;
                this.onClearanceSignatoryChange();
            },
            clearClearanceSignatory() {
                this.clearanceSignatoryId = '';
                this.clearanceSignatoryQuery = '';
                this.clearanceSignatoryOpen = false;
                this.onClearanceSignatoryChange();
            },
            currentGroup() {
                if (!this.clearanceSignatoryId) {
                    return null;
                }
                return this.employeeGroups.find((g) => String(g.headId) === String(this.clearanceSignatoryId)) || null;
            },
            currentGroupName() {
                return this.currentGroup()?.name || '';
            },
            eligibleEmployees() {
                const group = this.currentGroup();
                if (!group) {
                    return this.employees;
                }
                const memberIds = group.employeeIds
                    .map((id) => String(id))
                    .filter((id) => id !== String(this.clearanceSignatoryId));
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
            onClearanceSignatoryChange() {
                const eligibleIds = this.eligibleEmployees().map((e) => e.id);
                this.tasks.forEach((task) => {
                    if (task.signatory_id && !eligibleIds.includes(String(task.signatory_id))) {
                        this.clearTaskSignatory(task);
                    }
                });
            },
            selectTaskSignatory(task, employee) {
                task.signatory_id = employee.id;
                task.signatory_query = employee.name;
                task.signatory_open = false;
            },
            clearTaskSignatory(task) {
                task.signatory_id = '';
                task.signatory_query = '';
                task.signatory_open = false;
            },
            addTask() {
                this.tasks.push({ id: null, title: '', signatory_id: '', signatory_query: '', signatory_open: false });
            },
            removeTask(index) {
                this.tasks.splice(index, 1);
            },
        };
    }
</script>
