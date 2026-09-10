@props([
    'employees' => [],
    'emailTemplates' => [],
    'separationTypes' => [],
])

@php
    $offboardingRequestFields = [
        'employee_id', 'immediate_head_id', 'notice_date', 'last_working_day', 'separation_type_id', 'approval_mode',
        'approver_notification_template_id', 'offboardee_notification_template_id', 'general_signatory_notification_template_id',
        // Not a real form field — `OffboardingRequestController::store()`'s
        // "no active Final Approver configured" check flashes its message
        // under this key so the modal reopens with it shown, the same as
        // every genuine validation failure below.
        'final_approver',
    ];
    $offboardingRequestHasErrors = $errors->hasAny($offboardingRequestFields);

    // The 3 email-sending "functions" that fire once each, automatically,
    // the moment this form is submitted — see
    // `ChecklistApprovalNotifier::notifyDepartmentHeadsOfNewRequest()` /
    // `notifyOffboardee()` / `notifyGeneralSignatories()`. Each function's
    // `defaultId` is resolved from the already-loaded `$emailTemplates`
    // list (no extra query here) purely to pre-select that function's
    // Select field — matching each method's own live "current active
    // template with this exact name" lookup, so what the admin sees here
    // matches what would be used if they left it untouched. Picking a
    // different option submits a per-request override that, once saved, is
    // frozen for this request regardless of later default/template changes.
    $emailTemplateFunctions = collect([
        ['field' => 'approver_notification_template_id', 'label' => 'Approver / Department Head Notification', 'templateName' => 'Offboarding Request Notification'],
        ['field' => 'offboardee_notification_template_id', 'label' => 'Offboardee Notification', 'templateName' => 'Offboarding Details Notification – Employee'],
        ['field' => 'general_signatory_notification_template_id', 'label' => 'General Signatory Notification', 'templateName' => 'General Signatory Offboarding Notification'],
    ])->map(fn (array $function) => $function + [
        'defaultId' => optional($emailTemplates->firstWhere('template_name', $function['templateName']))->id,
    ]);

    // Maps employee id => that employee's own `head_employee_id` — the
    // Immediate Head's real employee id, resolved from the Employee Master
    // Excel import's `headID` column (matched against `employeeNo`/
    // `employee_code`; see `EmployeeGroupController::import()`) — or null
    // when unset. Built once here (not per-request in a controller) so this
    // auto-fill behavior lives in exactly one place regardless of which
    // page renders this modal.
    //
    // Deliberately ID-based, never the offboardee's department, full name,
    // or the free-text `sup_one`/`head` columns — those can change or be
    // ambiguous; `head_employee_id` is a real foreign key that stays
    // correct even if the head's own name is later edited. If it points to
    // someone not currently offered by this modal's own employee list (e.g.
    // no longer active), the `immediate-head-auto-select` handler below
    // finds no match and clears the field instead of applying a bad one —
    // the same "leave it empty, let the admin pick manually" fallback as a
    // genuinely blank `head_employee_id`.
    $immediateHeadByEmployeeId = $employees->pluck('head_employee_id', 'id');
@endphp

<button @click="$dispatch('open-offboarding-request-modal')"
    class="shadow-theme-xs flex items-center justify-center gap-2 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630]">
    <svg class="fill-current" width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M9 3.75V14.25M3.75 9H14.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
    </svg>
    New Offboarding Request
</button>

<x-ui.modal x-data="{ open: false }" @open-offboarding-request-modal.window="open = true"
    :isOpen="$offboardingRequestHasErrors"
    class="max-w-[830px]">
    <div class="no-scrollbar relative w-full max-w-[830px] overflow-y-auto rounded-3xl bg-white p-4 dark:bg-gray-900 lg:p-11">
        <div class="px-2 pr-14">
            <h4 class="mb-2 text-2xl font-semibold text-gray-800 dark:text-white/90">
                New Offboarding Request
            </h4>
            <p class="mb-6 text-sm text-gray-500 dark:text-gray-400 lg:mb-7">
                Select an employee who hasn't been offboarded yet and fill in the request details.
            </p>
        </div>

        <form class="flex flex-col" method="POST" action="{{ route('offboarding-requests.store') }}"
            x-data="offboardingRequestForm(@js(session('success')), @js($offboardingRequestHasErrors ? $errors->first() : null))"
            @submit="submitting = true">
            @csrf
            <div class="custom-scrollbar h-[458px] overflow-y-auto p-2">
                <div class="grid grid-cols-1 gap-x-6 gap-y-5 lg:grid-cols-2">
                    <div class="col-span-2 space-y-5"
                        x-data="{
                            // Shared by both searchable pickers below instead of each
                            // owning its own independent 'is my dropdown open' flag —
                            // `x-ui.modal`'s own Modal Content wrapper has `@click.stop`
                            // on it (so clicking inside the modal doesn't also close the
                            // whole thing via the backdrop's handler), which stops every
                            // in-modal click from ever bubbling up to `document`; Alpine's
                            // `@click.away` listens on `document`, so it can never fire
                            // for a click elsewhere inside this modal (see
                            // `final-approver-modal.blade.php`'s matching comment, which
                            // hit this exact issue first). A single shared value fixes
                            // BOTH required behaviors at once: the `@click` below (a
                            // plain bubble-phase listener on this shared ancestor, not
                            // `document`, so it isn't blocked by the modal's `.stop`)
                            // closes whichever picker is open the moment anything else in
                            // the modal is clicked, and — since only one picker can ever
                            // be 'active' at a time — opening one via its own
                            // @focus/@input below automatically closes the other with no
                            // extra wiring.
                            activeDropdown: null,
                            immediateHeadByEmployeeId: @js($immediateHeadByEmployeeId),
                            // Looks up the newly-selected Offboardee's own `head_employee_id`
                            // (if any) and applies it directly to the Immediate Head
                            // picker — found via a plain `document` query (not $el/$refs:
                            // this method is invoked from the Employee select's OWN nested
                            // x-data scope via its @change, and Alpine resolves $el/$refs
                            // to the NEAREST x-data in that call chain — the Employee
                            // select's own small wrapper div — not this outer one, even
                            // though this method is defined here; `document` sidesteps that
                            // ambiguity, and this modal only ever has one instance live on
                            // a page) — dispatching a real 'change' event afterward so that
                            // select's own isOptionSelected/label-color state updates
                            // exactly as if the user had picked it themselves. No match
                            // (blank `head_employee_id`, or one that doesn't resolve to a
                            // currently-offered employee) clears the field back to empty so
                            // the user can still pick one manually, per spec.
                            setImmediateHeadFromEmployee(employeeId) {
                                const matchedId = this.immediateHeadByEmployeeId[employeeId];
                                // The Immediate Head field is its own isolated Alpine
                                // island (searchable picker, not a plain <select>), so
                                // it can't be reached via document/$refs from here —
                                // dispatch a window event and let that component apply
                                // it to its own state instead.
                                window.dispatchEvent(new CustomEvent('immediate-head-auto-select', { detail: matchedId ?? null }));
                            },
                        }"
                        @click="activeDropdown = null">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                Employee <span class="text-error-500">*</span>
                            </label>
                            @php
                                $selectedEmployee = $employees->firstWhere('id', (int) old('employee_id'));
                                $selectedEmployeeQuery = $selectedEmployee
                                    ? $selectedEmployee->name . ' (' . $selectedEmployee->employee_code . ')'
                                    : '';
                                $selectedEmployeeIdOld = old('employee_id') ? (string) old('employee_id') : '';
                            @endphp
                            <div x-data="{
                                    query: @js($selectedEmployeeQuery),
                                    selectedEmployeeId: @js($selectedEmployeeIdOld),
                                    employees: @js($employees->map(fn ($employee) => [
                                        'id' => (string) $employee->id,
                                        'name' => $employee->name,
                                        'code' => $employee->employee_code,
                                        'department' => $employee->department,
                                        'position' => $employee->designation,
                                    ])),
                                    filteredEmployees() {
                                        const needle = this.query.toLowerCase();
                                        if (!needle) {
                                            return this.employees;
                                        }
                                        return this.employees.filter((e) =>
                                            e.name.toLowerCase().includes(needle)
                                            || e.code.toLowerCase().includes(needle)
                                        );
                                    },
                                    selectEmployee(employee) {
                                        this.selectedEmployeeId = employee.id;
                                        this.query = `${employee.name} (${employee.code})`;
                                    },
                                }"
                                {{-- `activeDropdown` lives on the OUTER x-data above (shared
                                     with the Immediate Head picker below) — see that x-data's
                                     own comment for why closing on "click elsewhere in the
                                     modal" can't use a plain `@click.away` here. `@click.stop`
                                     keeps a click anywhere inside THIS picker (typing, picking
                                     an option) from bubbling up and immediately re-closing
                                     itself via the outer wrapper's own catch-all `@click`;
                                     `@click.away` is kept too as a harmless extra — it only
                                     ever gets a chance to fire for a genuine click on the
                                     modal's backdrop, which already closes the whole modal
                                     anyway. --}}
                                @click.stop @click.away="activeDropdown = null" class="relative">
                                <input type="hidden" name="employee_id" :value="selectedEmployeeId" />
                                <input type="text" x-model="query" autocomplete="off"
                                    @focus="activeDropdown = 'employee'"
                                    @input="selectedEmployeeId = ''; activeDropdown = 'employee'"
                                    placeholder="Search employee name or employee number..."
                                    :class="selectedEmployeeId ? 'text-gray-800 dark:text-white/90' : 'text-gray-500'"
                                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />

                                {{-- The Employee select's own change previously drove the
                                     Immediate Head auto-fill via `setImmediateHeadFromEmployee()`
                                     (defined on the OUTER x-data above this one). Called here as
                                     part of the SAME inline `@click` expression (not from inside
                                     `selectEmployee()`'s own JS body) so Alpine resolves it by
                                     walking up to that ancestor scope correctly — calling it from
                                     inside a method body would instead resolve `this` to just this
                                     component's own (child) scope, per the same quirk documented
                                     on `setImmediateHeadFromEmployee()` itself. `activeDropdown =
                                     null` is set the same inline way, for the same reason. --}}
                                <div x-show="activeDropdown === 'employee'"
                                    class="shadow-theme-lg absolute z-50 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                                    <template x-for="employee in filteredEmployees()" :key="employee.id">
                                        <div @click="selectEmployee(employee); setImmediateHeadFromEmployee(employee.id); activeDropdown = null"
                                            class="cursor-pointer border-b border-gray-100 px-4 py-2.5 text-sm last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                                            <span class="block font-medium text-gray-800 dark:text-white/90"
                                                x-text="`${employee.name} (${employee.code})`"></span>
                                            <span class="block text-xs text-gray-400"
                                                x-text="[employee.department, employee.position].filter(Boolean).join(' — ')"></span>
                                        </div>
                                    </template>
                                    <div x-show="filteredEmployees().length === 0" class="px-4 py-2.5 text-sm text-gray-400">
                                        No employees found
                                    </div>
                                </div>
                            </div>
                            @error('employee_id')
                                <p class="mt-1.5 text-xs text-error-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                Immediate Head
                            </label>
                            @php
                                $selectedImmediateHead = $employees->firstWhere('id', (int) old('immediate_head_id'));
                                $selectedImmediateHeadQuery = $selectedImmediateHead
                                    ? $selectedImmediateHead->name . ' (' . $selectedImmediateHead->employee_code . ')'
                                    : '';
                                $selectedImmediateHeadIdOld = old('immediate_head_id') ? (string) old('immediate_head_id') : '';
                            @endphp
                            <div x-data="{
                                    query: @js($selectedImmediateHeadQuery),
                                    selectedEmployeeId: @js($selectedImmediateHeadIdOld),
                                    employees: @js($employees->map(fn ($employee) => [
                                        'id' => (string) $employee->id,
                                        'name' => $employee->name,
                                        'code' => $employee->employee_code,
                                        'department' => $employee->department,
                                        'position' => $employee->designation,
                                    ])),
                                    filteredEmployees() {
                                        const needle = this.query.toLowerCase();
                                        if (!needle) {
                                            return this.employees;
                                        }
                                        return this.employees.filter((e) =>
                                            e.name.toLowerCase().includes(needle)
                                            || e.code.toLowerCase().includes(needle)
                                        );
                                    },
                                    selectEmployee(employee) {
                                        this.selectedEmployeeId = employee.id;
                                        this.query = `${employee.name} (${employee.code})`;
                                    },
                                }"
                                @immediate-head-auto-select.window="
                                    const matched = employees.find((e) => e.id === String($event.detail));
                                    if (matched) { selectEmployee(matched); } else { selectedEmployeeId = ''; query = ''; }
                                    activeDropdown = null;
                                "
                                {{-- Same `activeDropdown`/`@click.stop` pattern as the
                                     Employee picker above — see its comment for why a plain
                                     `@click.away` alone can't close this inside the modal. --}}
                                @click.stop @click.away="activeDropdown = null" class="relative">
                                <input type="hidden" name="immediate_head_id" :value="selectedEmployeeId" />
                                <input type="text" x-model="query" autocomplete="off"
                                    @focus="activeDropdown = 'immediate_head'"
                                    @input="selectedEmployeeId = ''; activeDropdown = 'immediate_head'"
                                    placeholder="Search employee name or employee number..."
                                    :class="selectedEmployeeId ? 'text-gray-800 dark:text-white/90' : 'text-gray-500'"
                                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />

                                <div x-show="activeDropdown === 'immediate_head'"
                                    class="shadow-theme-lg absolute z-50 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                                    <template x-for="employee in filteredEmployees()" :key="employee.id">
                                        <div @click="selectEmployee(employee); activeDropdown = null"
                                            class="cursor-pointer border-b border-gray-100 px-4 py-2.5 text-sm last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                                            <span class="block font-medium text-gray-800 dark:text-white/90"
                                                x-text="`${employee.name} (${employee.code})`"></span>
                                            <span class="block text-xs text-gray-400"
                                                x-text="[employee.department, employee.position].filter(Boolean).join(' — ')"></span>
                                        </div>
                                    </template>
                                    <div x-show="filteredEmployees().length === 0" class="px-4 py-2.5 text-sm text-gray-400">
                                        No employees found
                                    </div>
                                </div>
                            </div>
                            @error('immediate_head_id')
                                <p class="mt-1.5 text-xs text-error-500">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="col-span-2 lg:col-span-1">
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Resignation Date <span class="text-error-500">*</span>
                        </label>
                        <x-form.date-picker
                            id="notice_date"
                            name="notice_date"
                            placeholder="Select date"
                            :defaultDate="old('notice_date')"
                            :required="true"
                        />
                        @error('notice_date')
                            <p class="mt-1.5 text-xs text-error-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="col-span-2 lg:col-span-1">
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Last Working Day <span class="text-error-500">*</span>
                        </label>
                        <x-form.date-picker
                            id="last_working_day"
                            name="last_working_day"
                            placeholder="Select date"
                            :defaultDate="old('last_working_day')"
                            :required="true"
                        />
                        @error('last_working_day')
                            <p class="mt-1.5 text-xs text-error-500">{{ $message }}</p>
                        @enderror
                    </div>

                    @php
                        $selectedSeparationTypeId = old('separation_type_id', '');
                        $separationTypesJs = $separationTypes->map(fn ($type) => [
                            'id' => (string) $type->id,
                            'description' => $type->description,
                            'defaultNoticePeriodDays' => $type->default_notice_period_days,
                        ])->keyBy('id');
                    @endphp
                    <div class="contents" x-data="{
                        isOptionSelected: {{ $selectedSeparationTypeId ? 'true' : 'false' }},
                        selectedSeparationTypeId: @js((string) $selectedSeparationTypeId),
                        separationTypesById: @js($separationTypesJs),
                        // Managed here (not just left to `x-model` on the
                        // Notice Period input below) because that field is
                        // `disabled` — the admin can never type into it, it
                        // only ever reflects whichever type is picked.
                        noticePeriodDays: @js(optional($separationTypesJs->get((string) $selectedSeparationTypeId))['defaultNoticePeriodDays'] ?? ''),
                        applySeparationType() {
                            const type = this.separationTypesById[this.selectedSeparationTypeId];
                            this.noticePeriodDays = type ? type.defaultNoticePeriodDays : '';
                        },
                    }">
                        <div class="col-span-2 lg:col-span-1">
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                Separation Type <span class="text-error-500">*</span>
                            </label>
                            <div class="relative z-20 bg-transparent">
                                <select name="separation_type_id" required
                                    @change="isOptionSelected = true; selectedSeparationTypeId = $event.target.value; applySeparationType()"
                                    :class="isOptionSelected && 'text-gray-800 dark:text-white/90'"
                                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-500 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800">
                                    <option value="" class="text-gray-700 dark:bg-gray-900 dark:text-gray-400">Select a separation type</option>
                                    @foreach ($separationTypes as $type)
                                        <option value="{{ $type->id }}" @selected((string) $selectedSeparationTypeId === (string) $type->id) class="text-gray-700 dark:bg-gray-900 dark:text-gray-400">
                                            {{ $type->title }}
                                        </option>
                                    @endforeach
                                </select>
                                <template x-if="selectedSeparationTypeId && separationTypesById[selectedSeparationTypeId]">
                                    <p class="mt-1.5 text-sm text-gray-500 dark:text-gray-400">
                                        <span class="font-medium text-gray-600 dark:text-gray-300">Definition per Policy:</span>
                                        <span x-text="separationTypesById[selectedSeparationTypeId].description"></span>
                                    </p>
                                </template>
                            </div>
                            @error('separation_type_id')
                                <p class="mt-1.5 text-xs text-error-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="col-span-2 lg:col-span-1">
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                Notice Period (Days)
                            </label>
                            {{-- Deliberately no `name` attribute: a `disabled`
                                 field is never submitted with the form anyway
                                 (browsers exclude it), and the server never
                                 trusts a client-supplied notice period —
                                 `OffboardingRequestController::store()` always
                                 re-derives it from the selected Separation
                                 Type's OWN `default_notice_period_days`
                                 server-side, so this input is purely a
                                 read-only preview for the admin, never a real
                                 source of truth. --}}
                            <input type="number" disabled :value="noticePeriodDays"
                                placeholder="Select a separation type first"
                                class="dark:bg-dark-900 h-11 w-full cursor-not-allowed appearance-none rounded-lg border border-gray-300 bg-gray-50 bg-none px-4 py-2.5 text-sm text-gray-500 shadow-theme-xs placeholder:text-gray-400 dark:border-gray-700 dark:bg-white/5 dark:text-gray-400 dark:placeholder:text-white/30" />
                            <!-- <p class="mt-1.5 text-xs text-gray-400">
                                Set automatically from the selected Separation Type's Default Notice Period. Manage these on the
                                <a href="{{ route('separation-types.index') }}" target="_blank" class="text-brand-500 hover:text-brand-600 dark:text-brand-400 font-medium">Separation Types</a>
                                page. The Notification Date will be calculated automatically from today's date.
                            </p> -->
                        </div>
                    </div>

                    <div class="col-span-2">
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Checklist Approval Workflow <span class="text-error-500">*</span>
                        </label>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300"
                                title="Checklists are sent to all approvers immediately and can be completed independently, in any order.">
                                <input type="radio" name="approval_mode" value="async"
                                    @checked(old('approval_mode', 'async') === 'async')
                                    class="mt-0.5 h-4 w-4 accent-brand-500" />
                                <span>
                                    <span class="block font-medium text-gray-800 dark:text-white/90">Parallel Approval</span>
                                    <span class="block text-xs text-gray-400">
                                        All applicable checklists are sent immediately and can be completed independently — no required order.
                                    </span>
                                </span>
                            </label>
                            <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-300"
                                title="Checklists must be completed and approved in the configured order — the next stage only starts once the required checklist(s) in the current stage are approved.">
                                <input type="radio" name="approval_mode" value="sync"
                                    @checked(old('approval_mode', 'async') === 'sync')
                                    class="mt-0.5 h-4 w-4 accent-brand-500" />
                                <span>
                                    <span class="block font-medium text-gray-800 dark:text-white/90">Sequential Approval</span>
                                    <span class="block text-xs text-gray-400">
                                        Checklists must be completed in the configured Primary &rarr; Secondary &rarr; Final Pay order.
                                    </span>
                                </span>
                            </label>
                        </div>
                        @error('approval_mode')
                            <p class="mt-1.5 text-xs text-error-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="col-span-2" x-data="{ showEmailTemplates: {{ $offboardingRequestHasErrors ? 'true' : 'false' }} }">
                        <button type="button" @click="showEmailTemplates = !showEmailTemplates"
                            class="flex items-center gap-2 text-sm text-gray-600 hover:text-[#145a3a] dark:text-gray-400 dark:hover:text-[#3aa876]">
                            <svg class="shrink-0" width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M2.25 5.25C2.25 4.42157 2.92157 3.75 3.75 3.75H14.25C15.0784 3.75 15.75 4.42157 15.75 5.25V12.75C15.75 13.5784 15.0784 14.25 14.25 14.25H3.75C2.92157 14.25 2.25 13.5784 2.25 12.75V5.25Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                                <path d="M2.75 5L9 9.75L15.25 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <span>
                                Email Templates:
                                <span class="font-medium text-gray-800 dark:text-white/90">{{ $emailTemplateFunctions->count() }} configured</span>
                            </span>
                            <svg class="transition-transform" :class="showEmailTemplates && 'rotate-180'" width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M4.79175 7.396L10.0001 12.6043L15.2084 7.396" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </button>

                        <div x-show="showEmailTemplates" x-cloak class="mt-3 space-y-3"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 -translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 -translate-y-1">
                            @foreach ($emailTemplateFunctions as $function)
                                {{-- Each function gets its own fully self-contained card — label,
                                     select, and error message — so it never touches its neighbors,
                                     regardless of screen width. --}}
                                <div class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                                    <label class="mb-1.5 block text-xs font-medium text-gray-500 dark:text-gray-400">
                                        {{ $function['label'] }}
                                    </label>
                                    <select name="{{ $function['field'] }}"
                                        class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800">
                                        @forelse ($emailTemplates as $template)
                                            <option value="{{ $template->id }}"
                                                @selected((int) old($function['field'], $function['defaultId']) === $template->id)>
                                                {{ $template->template_name }}
                                            </option>
                                        @empty
                                            <option value="" disabled selected>No active templates available</option>
                                        @endforelse
                                    </select>
                                    @error($function['field'])
                                        <p class="mt-1.5 text-xs text-error-500">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endforeach
                        </div>
                    </div>

                </div>
            </div>
            <div class="flex items-center gap-3 px-2 mt-6 lg:justify-end">
                <button @click="open = false" type="button" :disabled="submitting"
                    class="flex w-full justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03] sm:w-auto">
                    Close
                </button>
                <button type="submit" :disabled="submitting" data-turbo-submits-with="Submitting..."
                    class="flex w-full items-center justify-center gap-2 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630] disabled:cursor-not-allowed disabled:opacity-70 sm:w-auto">
                    <span x-show="submitting" class="h-4 w-4 animate-spin rounded-full border-2 border-solid border-white border-t-transparent"></span>
                    <span x-text="submitting ? 'Submitting...' : 'Submit Request'"></span>
                </button>
            </div>
        </form>
    </div>
</x-ui.modal>
