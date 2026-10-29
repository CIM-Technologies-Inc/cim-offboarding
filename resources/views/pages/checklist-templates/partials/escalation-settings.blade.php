{{-- "Secondary Clearance Signatory & Escalation Settings" — shared by the
     create and edit checklist template forms. Its own small Alpine scope;
     it reads the parent form's `useTaskAssigneeAsSignatory` (Final Pay /
     "Use Task Assignee as Clearance Signatory" checklists have no single
     signatory, so the section is disabled for them). Everything is
     re-validated server-side — see ChecklistTemplateController::escalationSettings().

     "Enable Secondary Approver" (`secondary_approver_enabled`) is the master
     switch: unchecked by default, and the settings box below expands/collapses
     with it. While unchecked, the server neither validates nor saves the box's
     fields, so a template's previously saved settings are kept.

     Escalation is automatic whenever the switch is on: if the primary hasn't
     completed the checklist within "Escalate After (Days)", it moves to the
     secondary (see ClearanceSignatoryEscalationService).

     @param array $settings  focus (bool, opened via "Assign Secondary Approver"), secondary_approver_enabled, secondary_signatory_employee_id, escalation_days, escalation_day_type, escalation_notify_hr
     @param \Illuminate\Support\Collection $escalationEmployees  ClearanceSignatoryEscalationService::eligibleEmployeeOptions()
--}}
@php
    $inputClass = 'dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800';
@endphp
<div id="secondary-approver-settings" class="mt-5" x-data="escalationSettings(@js($settings), @js($escalationEmployees))"
    x-effect="setPanelOpen(secondaryEnabled && ! useTaskAssigneeAsSignatory)">
    <label class="flex items-start gap-2" :class="useTaskAssigneeAsSignatory ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'">
        <input type="hidden" name="secondary_approver_enabled" value="0" />
        <input type="checkbox" name="secondary_approver_enabled" value="1" x-model="secondaryEnabled" :disabled="useTaskAssigneeAsSignatory"
            class="mt-0.5 h-4 w-4 rounded border-gray-300 accent-[#145a3a] dark:border-gray-700" />
        <span>
            <span class="block text-sm font-medium text-gray-700 dark:text-gray-400">Enable Secondary Approver</span>
            <span class="block text-xs text-gray-400" x-show="!useTaskAssigneeAsSignatory">Assign a backup Clearance Signatory and automatic escalation for this checklist.</span>
            <span class="block text-xs text-gray-400" x-show="useTaskAssigneeAsSignatory">Not available — this checklist's Task Assignees are its signatories, so there is no single Clearance Signatory to back up.</span>
        </span>
    </label>

    {{-- Expand/collapse: height + opacity animated in plain inline style (no
         Alpine collapse plugin is bundled); overflow is only released once
         fully open so the employee dropdown isn't clipped, and the panel is
         inert while collapsed so its fields can't be tabbed into. --}}
    <div x-ref="panel" style="height: 0px; opacity: 0; overflow: hidden; transition: height 300ms ease, opacity 300ms ease;">
    <div class="mt-3 rounded-xl border border-gray-200 p-4 dark:border-gray-800 sm:p-6">
    <h5 class="text-sm font-semibold text-gray-800 dark:text-white/90">Secondary Clearance Signatory &amp; Escalation Settings</h5>
    <p class="mt-1 text-xs text-gray-400">
        A backup Clearance Signatory who automatically takes over if the primary doesn't complete this checklist within the period below.
        If the secondary also misses their period, HR/Admin is alerted to assign a new signatory. Changes apply to new offboarding requests only.
    </p>

    <div class="mt-4 space-y-4">
        {{-- A. Secondary Clearance Signatory --}}
        <div class="relative" @click.window.capture="open && ! $el.contains($event.target) && (open = false)">
            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                Secondary Clearance Signatory <span class="text-error-500">*</span>
            </label>
            <input type="hidden" name="secondary_signatory_employee_id" :value="secondaryId" />
            <div class="relative">
                <input type="text" x-ref="secondarySearch" x-model="query" autocomplete="off" @focus="open = true" @input="secondaryId = ''; open = true" @keydown.escape="open = false"
                    placeholder="Search name, employee no., department or position..." class="{{ $inputClass }} pr-9" />
                <button type="button" x-show="secondaryId" @click="secondaryId = ''; query = ''" aria-label="Clear secondary signatory"
                    class="absolute top-1/2 right-3 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <svg width="16" height="16" viewBox="0 0 18 18" fill="none"><path d="M13.5 4.5L4.5 13.5M4.5 4.5L13.5 13.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" /></svg>
                </button>
            </div>
            <div x-show="open" class="shadow-theme-lg absolute z-50 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                <template x-for="employee in filtered()" :key="employee.id">
                    <div @click="select(employee)" class="cursor-pointer border-b border-gray-100 px-4 py-2.5 text-sm last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                        <span class="text-gray-800 dark:text-white/90" x-text="employee.name + ' (' + (employee.employee_code || '—') + ')'"></span>
                        <span class="block text-xs text-gray-400" x-text="[employee.department, employee.position].filter(Boolean).join(' · ')"></span>
                    </div>
                </template>
                <div x-show="filtered().length === 0" class="px-4 py-2.5 text-sm text-gray-400">No eligible employees found</div>
            </div>
            <p class="mt-1.5 text-xs text-gray-400">Only active employees who can perform clearance-signatory actions are listed. Must differ from the primary Clearance Signatory.</p>
            @error('secondary_signatory_employee_id')
                <p class="mt-1.5 text-xs text-error-500">{{ $message }}</p>
            @enderror
        </div>

        {{-- Escalation period — escalation is automatic whenever Enable
             Secondary Approver is on (no separate checkbox). --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Escalate After (Days) <span class="text-error-500">*</span>
                </label>
                <input type="number" name="escalation_days" x-model="days" min="1" max="365" placeholder="e.g. 10" class="{{ $inputClass }}" />
                @error('escalation_days')
                    <p class="mt-1.5 text-xs text-error-500">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Count Days As</label>
                <select name="escalation_day_type" x-model="dayType"
                    class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 pr-8 text-sm text-gray-700 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    <option value="calendar">Calendar days</option>
                    <option value="working">Working days (excludes weekends &amp; holidays)</option>
                </select>
            </div>
        </div>
        <p class="text-xs text-gray-400">
            Counted from the moment the checklist is actually assigned (e.g. assigned Oct 1, 9:00 AM with 10 calendar days → escalates Oct 11, 9:00 AM).
            The secondary signatory then gets a new period of the same length. The checklist's own due date is not changed.
        </p>

        <label class="flex cursor-pointer items-start gap-2">
            <input type="hidden" name="escalation_notify_hr" value="0" />
            <input type="checkbox" name="escalation_notify_hr" value="1" x-model="notifyHr"
                class="mt-0.5 h-4 w-4 rounded border-gray-300 accent-[#145a3a] dark:border-gray-700" />
            <span>
                <span class="block text-sm font-medium text-gray-700 dark:text-gray-400">Notify HR/Admin when a checklist is escalated to the secondary signatory</span>
                <span class="block text-xs text-gray-400">HR/Admin is always notified if the secondary also misses their period.</span>
            </span>
        </label>
    </div>
</div>
    </div>
</div>

@once
    <script>
        function escalationSettings(settings, employees) {
            const selected = employees.find((e) => String(e.id) === String(settings.secondary_signatory_employee_id || ''));

            return {
                employees,
                secondaryEnabled: !!Number(settings.secondary_approver_enabled) || settings.secondary_approver_enabled === true,
                panelOpen: null,
                secondaryId: selected ? String(selected.id) : '',
                query: selected ? `${selected.name} (${selected.employee_code || '—'})` : '',
                open: false,
                days: settings.escalation_days || '',
                dayType: settings.escalation_day_type || 'calendar',
                notifyHr: settings.escalation_notify_hr === undefined || settings.escalation_notify_hr === null ? true : (!!Number(settings.escalation_notify_hr) || settings.escalation_notify_hr === true),
                // Opened via "Assign Secondary Approver" on the details page
                // (`settings.focus`): bring the section into view and put the
                // cursor in the employee search. The switch itself is already
                // pre-ticked by the server-rendered default.
                init() {
                    if (!settings.focus) return;
                    this.$nextTick(() => {
                        this.$el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        this.$refs.secondarySearch?.focus({ preventScroll: true });
                    });
                },
                // Called by x-effect whenever the switch (or the parent form's
                // Task Assignee mode) changes. The first call just sets the
                // state, so an edit page opens already expanded/collapsed.
                setPanelOpen(open) {
                    const panel = this.$refs.panel;
                    const first = this.panelOpen === null;

                    if (open === this.panelOpen) return;
                    this.panelOpen = open;
                    panel.inert = !open;

                    if (first) {
                        Object.assign(panel.style, open
                            ? { height: 'auto', opacity: '1', overflow: 'visible' }
                            : { height: '0px', opacity: '0', overflow: 'hidden' });
                        return;
                    }

                    if (open) {
                        panel.style.overflow = 'hidden';
                        panel.style.height = '0px';
                        requestAnimationFrame(() => {
                            panel.style.height = panel.scrollHeight + 'px';
                            panel.style.opacity = '1';
                        });
                        panel.addEventListener('transitionend', () => {
                            if (this.panelOpen) Object.assign(panel.style, { height: 'auto', overflow: 'visible' });
                        }, { once: true });
                    } else {
                        Object.assign(panel.style, { height: panel.scrollHeight + 'px', overflow: 'hidden' });
                        requestAnimationFrame(() => requestAnimationFrame(() => {
                            panel.style.height = '0px';
                            panel.style.opacity = '0';
                        }));
                        this.open = false;
                    }
                },
                filtered() {
                    const needle = this.query.trim().toLowerCase();
                    const list = !needle ? this.employees : this.employees.filter((e) =>
                        [e.name, e.employee_code, e.department, e.position].filter(Boolean).join(' ').toLowerCase().includes(needle));
                    return list.slice(0, 100);
                },
                select(employee) {
                    this.secondaryId = String(employee.id);
                    this.query = `${employee.name} (${employee.employee_code || '—'})`;
                    this.open = false;
                },
            };
        }
    </script>
@endonce
