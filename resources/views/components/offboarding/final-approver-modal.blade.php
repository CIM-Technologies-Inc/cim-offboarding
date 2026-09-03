@props(['employees' => []])

<div x-data="finalApproverModal(@js($employees), @js($errors->has('employee_id') ? $errors->first('employee_id') : null))"
    @open-final-approver-modal.window="openModal()">
    <x-ui.modal x-data="{ open: false }" @open-final-approver-modal.window="open = true" :isOpen="false" class="w-full sm:w-[50vw] sm:max-w-[50vw]">
        {{--
            Deliberately `overflow-visible` here, NOT `overflow-y-auto` like
            `general-signatory-modal.blade.php`'s equivalent wrapper — this
            form is short enough to never need its own scrollbar, and an
            `overflow-y-auto`/`hidden` ancestor clips any `absolute`-positioned
            descendant (the employee search dropdown below) to its own
            bounds even when nothing is actually scrolling, cutting the
            dropdown off. Leave the General Signatory modal's own wrapper
            untouched — its Task List can genuinely grow long enough to need
            real scrolling, so it keeps `overflow-y-auto` and `max-h-[85vh]`.
        --}}
        {{--
            `@click="employeeOpen = false"` here, paired with `@click.stop`
            on the search field's own wrapper below, is what actually makes
            "click outside the field/dropdown closes it" work for anywhere
            ELSE inside this modal (the title, description, Cancel/Submit
            buttons, blank padding) — `x-ui.modal`'s own Modal Content div
            has `@click.stop` on it (so clicking inside the modal doesn't
            also close the whole thing via the backdrop's handler), which
            stops every in-modal click from ever bubbling up to `document`.
            Alpine's `@click.away` listens on `document`, so with nothing
            fixed it would only ever fire for a click on the backdrop
            itself — which closes the ENTIRE modal anyway, not just the
            dropdown. This local click-catch is scoped to this file only;
            `x-ui.modal` and `general-signatory-modal.blade.php` are both
            untouched.
        --}}
        <div class="relative w-full overflow-visible rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-cloak
            @click="employeeOpen = false">
            <h4 class="mb-6 text-xl font-semibold text-gray-800 dark:text-white/90">Set Final Approver</h4>
            <!-- <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                The employee selected below becomes the active "Approved for Payment by:" signatory on every new
                Clearance Form — the previously active Final Approver (if any) is marked Inactive automatically.
            </p> -->

            <form :action="'/final-approvers'" method="POST" @submit.prevent="submitViaFetch($event).then((ok) => { if (ok) open = false; })">
                @csrf

                <div class="relative z-10" @click.stop @click.away="employeeOpen = false">
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        Final Signatory
                    </label>
                    <input type="hidden" name="employee_id" :value="employeeId" />
                    <div class="relative">
                        <input type="text" x-model="employeeQuery" autocomplete="off"
                            @focus="employeeOpen = true"
                            @input="employeeId = ''; employeeOpen = true"
                            placeholder="Search by employee ID or name..."
                            class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 pr-9 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
                        <button type="button" x-show="employeeId" @click="clearEmployee()"
                            class="absolute top-1/2 right-3 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                            <svg width="16" height="16" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M13.5 4.5L4.5 13.5M4.5 4.5L13.5 13.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </button>
                    </div>

                    <div x-show="employeeOpen"
                        class="shadow-theme-lg absolute z-50 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                        <template x-for="employee in filteredEmployees(employeeQuery)" :key="employee.id">
                            <div @click="selectEmployee(employee)"
                                class="cursor-pointer border-b border-gray-100 px-4 py-2.5 text-sm last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                                <span class="text-gray-800 dark:text-white/90" x-text="employee.name"></span>
                                <span class="block text-xs text-gray-400" x-text="`${employee.employee_code || ''} · ${employee.department}`"></span>
                            </div>
                        </template>
                        <div x-show="filteredEmployees(employeeQuery).length === 0"
                            class="px-4 py-2.5 text-sm text-gray-400">
                            No employees found
                        </div>
                    </div>
                </div>

                <div class="mt-7 flex items-center justify-end gap-3">
                    <button type="button" @click="open = false" :disabled="saving"
                        class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                        Cancel
                    </button>
                    <button type="submit" :disabled="saving || !employeeId" data-turbo-submits-with="Saving..."
                        class="flex items-center justify-center gap-2 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630] disabled:cursor-not-allowed disabled:opacity-70">
                        <span x-show="saving" class="h-4 w-4 animate-spin rounded-full border-2 border-solid border-white border-t-transparent"></span>
                        <span x-text="saving ? 'Saving...' : 'Set Final Approver'"></span>
                    </button>
                </div>
            </form>
        </div>
    </x-ui.modal>
</div>

<script>
    function finalApproverModal(employees, flashError) {
        return {
            employeeId: '',
            employeeQuery: '',
            employeeOpen: false,
            saving: false,
            employees: employees,
            init() {
                if (!flashError) {
                    return;
                }

                this.$nextTick(() => this.$dispatch('open-final-approver-modal'));

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
            openModal() {
                this.employeeId = '';
                this.employeeQuery = '';
                this.employeeOpen = false;
            },
            // Same "own copy, dedupe by id" search convention as the General
            // Signatory Clearance Signatory picker — see
            // `general-signatory-modal.blade.php`'s `filteredClearanceEmployees()`.
            filteredEmployees(query) {
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
            selectEmployee(employee) {
                this.employeeId = employee.id;
                this.employeeQuery = `${employee.name} (${employee.employee_code || ''})`;
                this.employeeOpen = false;
            },
            clearEmployee() {
                this.employeeId = '';
                this.employeeQuery = '';
                this.employeeOpen = false;
            },
            // Same fetch()-then-refresh-just-this-table convention as
            // `general-signatory-modal.blade.php`'s `submitViaFetch()`/`refreshTable()`.
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
                        const message = data.errors ? Object.values(data.errors).flat()[0] : (data.message || 'Failed to set Final Approver.');
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
            async refreshTable() {
                const response = await fetch(window.location.href, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                const html = await response.text();
                const freshTbody = new DOMParser().parseFromString(html, 'text/html').getElementById('final-approvers-tbody');
                const currentTbody = document.getElementById('final-approvers-tbody');

                if (freshTbody && currentTbody) {
                    currentTbody.innerHTML = freshTbody.innerHTML;
                }
            },
        };
    }
</script>
