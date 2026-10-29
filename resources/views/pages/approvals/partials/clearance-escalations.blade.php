{{-- HR/Admin: Clearance Signatory Escalations panel + "Assign New Clearance
     Signatory" modal. Included on the Approvals page only for users with
     `checklist-signatories.reassign`; the data route and the reassign route
     enforce that same permission server-side. Loads its data via fetch so
     the Approvals page's own (large) query stays unchanged. --}}
@php
    $inputClass = 'dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800';
@endphp
<div x-data="clearanceEscalations()" x-init="load()" class="mb-6" x-show="loaded && rows.length" x-cloak>
    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div>
                <h5 class="text-base font-semibold text-gray-800 dark:text-white/90">Clearance Signatory Escalations</h5>
                <p class="text-xs text-gray-400">Checklists whose Clearance Signatory missed their completion period.</p>
            </div>
            <span x-show="actionRequiredCount()" class="rounded-full bg-error-50 px-2.5 py-1 text-xs font-medium text-error-600 dark:bg-error-500/10 dark:text-error-400"
                x-text="actionRequiredCount() + ' need HR/Admin action'"></span>
        </div>

        <div class="space-y-2">
            <template x-for="row in rows" :key="row.id">
                <div class="rounded-lg border p-3" :class="row.awaitingHr ? 'border-error-500 bg-error-50 dark:border-error-500/30 dark:bg-error-500/10' : 'border-gray-200 dark:border-gray-800'">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-gray-800 dark:text-white/90">
                                <span x-text="row.checklistTitle"></span>
                                <span class="font-normal text-gray-500 dark:text-gray-400" x-text="'— ' + row.offboardeeName + ' (' + row.offboardeeCode + ')'"></span>
                            </p>
                            <p class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                                <span class="rounded-full px-2 py-0.5 font-medium"
                                    :class="row.awaitingHr ? 'bg-error-500 text-white' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400'"
                                    x-text="row.stateLabel"></span>
                                <span class="text-gray-500 dark:text-gray-400" x-show="!row.awaitingHr" x-text="'Active signatory: ' + (row.currentSignatoryName || '—') + (row.currentDeadline ? ' · due ' + row.currentDeadline : '')"></span>
                                <span class="text-gray-500 dark:text-gray-400" x-show="row.awaitingHr" x-text="'Expired signatory: ' + (row.currentSignatoryName || '—')"></span>
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <button type="button" @click="row._history = !row._history"
                                class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
                                x-text="row._history ? 'Hide History' : 'History'"></button>
                            <button type="button" x-show="row.canReassign" @click="openModal(row)"
                                class="rounded-md bg-[#145a3a] px-2.5 py-1 text-xs font-medium text-white hover:bg-[#0f4630]">
                                Assign New Clearance Signatory
                            </button>
                        </div>
                    </div>
                    <div x-show="row._history" class="mt-3 border-t border-gray-100 pt-2 dark:border-gray-800">
                        <ol class="space-y-1.5">
                            <template x-for="(event, idx) in row.history" :key="idx">
                                <li class="text-xs text-gray-600 dark:text-gray-300">
                                    <span class="font-medium" x-text="event.occurredAt + ' — ' + event.label"></span>
                                    <span x-text="(event.from ? ' · from ' + event.from : '') + (event.to ? ' · to ' + event.to : '') + (event.deadline ? ' · deadline ' + event.deadline : '') + (event.by ? ' · by ' + event.by : ' · ' + event.trigger)"></span>
                                    <span class="block text-gray-400" x-show="event.reason" x-text="event.reason"></span>
                                </li>
                            </template>
                        </ol>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <x-ui.modal x-data="{ open: false }" @open-reassign-signatory-modal.window="open = true" @close-reassign-signatory-modal.window="open = false" :isOpen="false" class="max-w-[640px]">
        <div class="no-scrollbar relative max-h-[85vh] w-full overflow-y-auto rounded-3xl bg-white p-5 dark:bg-gray-900 sm:p-6" x-cloak>
            <template x-if="current">
                <div>
                    <h4 class="mb-1 pr-12 text-lg font-semibold text-gray-800 dark:text-white/90">Assign New Clearance Signatory</h4>
                    <p class="mb-4 text-xs text-gray-400">The new signatory takes over clearance signing for this checklist. Completed tasks, approvals, remarks and individual task assignments are kept.</p>

                    <dl class="mb-4 space-y-1.5 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-800">
                        <template x-for="detail in detailRows()" :key="detail.label">
                            <div class="flex flex-wrap gap-2">
                                <dt class="shrink-0 text-gray-400" style="width: 11rem" x-text="detail.label"></dt>
                                <dd class="min-w-0 font-medium text-gray-700 dark:text-gray-300" x-text="detail.value || '—'"></dd>
                            </div>
                        </template>
                    </dl>

                    <div x-show="error" class="mb-4 rounded-lg border border-error-500 bg-error-50 px-3 py-2 text-sm text-error-600 dark:border-error-500/30 dark:bg-error-500/10 dark:text-error-400" x-text="error"></div>

                    {{-- Step 1: choose --}}
                    <div x-show="!reviewing">
                        <div class="relative" @click.window.capture="pickerOpen && ! $el.contains($event.target) && (pickerOpen = false)">
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">New Clearance Signatory <span class="text-error-500">*</span></label>
                            <input type="text" x-model="query" autocomplete="off" @focus="pickerOpen = true" @input="selectedId = null; pickerOpen = true"
                                placeholder="Search name, employee no., department or position..." class="{{ $inputClass }}" />
                            <div x-show="pickerOpen" class="shadow-theme-lg absolute z-50 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                                <template x-for="employee in filteredEmployees()" :key="employee.id">
                                    <div @click="selectEmployee(employee)" class="cursor-pointer border-b border-gray-100 px-4 py-2.5 text-sm last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                                        <span class="text-gray-800 dark:text-white/90" x-text="employee.name + ' (' + (employee.employee_code || '—') + ')'"></span>
                                        <span x-show="employee.id === current.primarySignatoryId" class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] text-gray-600 dark:bg-white/5 dark:text-gray-300">Original primary</span>
                                        <span class="block text-xs text-gray-400" x-text="[employee.department, employee.position].filter(Boolean).join(' · ')"></span>
                                    </div>
                                </template>
                                <div x-show="filteredEmployees().length === 0" class="px-4 py-2.5 text-sm text-gray-400">No eligible employees found</div>
                            </div>
                            <p class="mt-1.5 text-xs text-gray-400">Only active employees who can perform clearance-signatory actions are listed (the offboardee is excluded).</p>
                        </div>
                        <div class="mt-4">
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Reason <span class="text-error-500">*</span></label>
                            <textarea x-model="reason" rows="2" maxlength="1000" placeholder="Why this employee is being assigned"
                                class="dark:bg-dark-900 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30"></textarea>
                        </div>
                    </div>

                    {{-- Step 2: review & confirm --}}
                    <div x-show="reviewing" class="rounded-lg border border-[#145a3a] bg-[#145a3a]/10 p-3 text-sm text-gray-700 dark:text-gray-300">
                        <p class="mb-1 font-semibold text-[#145a3a] dark:text-[#3aa876]">Please confirm</p>
                        <p>New Clearance Signatory: <strong x-text="selectedEmployee()?.name"></strong></p>
                        <p>Completion period: <strong x-text="current.periodLabel || 'None configured (no deadline monitoring)'"></strong></p>
                        <p x-show="current.deadlinePreview">New deadline (if confirmed now): <strong x-text="current.deadlinePreview"></strong></p>
                        <p class="mt-1">Reason: <span x-text="reason"></span></p>
                    </div>

                    <div class="mt-5 flex flex-wrap items-center justify-end gap-3">
                        <button type="button" @click="reviewing ? (reviewing = false) : (open = false)"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400"
                            x-text="reviewing ? 'Back' : 'Cancel'"></button>
                        <button type="button" x-show="!reviewing" @click="review()"
                            class="rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630]">Review Assignment</button>
                        <button type="button" x-show="reviewing" @click="submit()" :disabled="saving"
                            :class="saving ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#0f4630]'"
                            class="flex items-center gap-2 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white">
                            <svg x-show="saving" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25" /><path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" /></svg>
                            <span x-text="saving ? 'Assigning...' : 'Confirm Assignment'"></span>
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </x-ui.modal>
</div>

<script>
    function clearanceEscalations() {
        return {
            rows: [],
            employees: [],
            loaded: false,
            current: null,
            query: '',
            pickerOpen: false,
            selectedId: null,
            reason: '',
            reviewing: false,
            saving: false,
            error: '',

            csrfToken() {
                const meta = document.querySelector('meta[name="csrf-token"]');
                return meta ? meta.content : '';
            },
            notify(icon, title) {
                window.Swal?.fire({ toast: true, position: 'bottom-end', icon, title, showConfirmButton: false, timer: icon === 'success' ? 2500 : 4000, timerProgressBar: icon === 'success', customClass: { container: 'app-toast' } });
            },
            load() {
                window.fetchWithTimeout('{{ route('clearance-signatory-escalations.index') }}', { headers: { Accept: 'application/json' } })
                    .then((response) => (response.ok ? response.json() : Promise.reject(new Error('Could not load escalations.'))))
                    .then((data) => {
                        this.rows = data.escalations.map((row) => ({ ...row, _history: false }));
                        this.employees = data.employees;
                    })
                    .catch(() => {})
                    .finally(() => { this.loaded = true; });
            },
            actionRequiredCount() { return this.rows.filter((row) => row.awaitingHr).length; },
            openModal(row) {
                this.current = row;
                this.query = '';
                this.selectedId = null;
                this.reason = '';
                this.reviewing = false;
                this.error = '';
                this.$dispatch('open-reassign-signatory-modal');
            },
            detailRows() {
                const r = this.current;
                return [
                    ['Offboardee', r.offboardeeName + ' (' + r.offboardeeCode + ')'],
                    ['Checklist', r.checklistTitle],
                    ['Status', r.stateLabel + ' · ' + r.checklistStatus],
                    ['Original primary signatory', r.primarySignatoryName],
                    ['Originally assigned / deadline', (r.originalAssignedAt || '—') + ' / ' + (r.originalDeadline || '—')],
                    ['Secondary signatory', r.secondarySignatoryName],
                    ['Secondary assigned / deadline', (r.secondaryAssignedAt || '—') + ' / ' + (r.secondaryDeadline || '—')],
                    ['Reason for escalation', r.reason],
                ].map(([label, value]) => ({ label, value }));
            },
            filteredEmployees() {
                const needle = this.query.trim().toLowerCase();
                return this.employees
                    .filter((e) => e.id !== this.current.offboardeeEmployeeId)
                    .filter((e) => !needle || [e.name, e.employee_code, e.department, e.position].filter(Boolean).join(' ').toLowerCase().includes(needle))
                    .slice(0, 100);
            },
            selectEmployee(employee) {
                this.selectedId = employee.id;
                this.query = employee.name + ' (' + (employee.employee_code || '—') + ')';
                this.pickerOpen = false;
                this.error = '';
            },
            selectedEmployee() { return this.employees.find((e) => e.id === this.selectedId); },
            review() {
                if (!this.selectedId) { this.error = 'Select the new Clearance Signatory from the list.'; return; }
                if (!this.reason.trim()) { this.error = 'Enter the reason for this reassignment.'; return; }
                this.error = '';
                this.reviewing = true;
            },
            submit() {
                if (this.saving) return;
                this.saving = true;
                this.error = '';

                window.fetchWithTimeout(`{{ url('clearance-signatory-escalations') }}/${this.current.id}/reassign`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrfToken(), 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({
                        employee_id: this.selectedId,
                        reason: this.reason,
                        expected_employee_id: this.current.expectedEmployeeId,
                        expected_escalation_status: this.current.expectedEscalationStatus,
                    }),
                })
                    .then(async (response) => {
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok) throw Object.assign(new Error(data.message || 'Could not assign the new Clearance Signatory.'), { status: response.status });
                        return data;
                    })
                    .then((data) => {
                        const index = this.rows.findIndex((row) => row.id === data.escalation.id);
                        if (index !== -1) this.rows[index] = { ...data.escalation, _history: false };
                        this.notify('success', data.message);
                        this.$dispatch('close-reassign-signatory-modal');
                    })
                    .catch((error) => {
                        // Keep the modal open with the entered values; go back to
                        // the selection step so the admin can correct it.
                        this.error = error?.name === 'AbortError' ? 'This is taking longer than expected. Please refresh and check before trying again.' : error.message;
                        this.reviewing = false;
                        if (error.status === 409) this.load();
                    })
                    .finally(() => { this.saving = false; });
            },
        };
    }
</script>
