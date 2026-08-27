@props([
    'employees' => [],
    'emailTemplates' => [],
])

@php
    $offboardingRequestFields = [
        'employee_id', 'immediate_head_id', 'notice_date', 'last_working_day', 'resignation_type', 'reason',
        'approver_notification_template_id', 'offboardee_notification_template_id', 'general_signatory_notification_template_id',
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

    // Maps employee id => the id of the employee referenced by that
    // employee's `sup_one` (Immediate Head) column, or null when `sup_one`
    // is blank or doesn't resolve to anyone currently on record. Built once
    // here (not per-request in a controller) so this auto-fill behavior
    // lives in exactly one place regardless of which page renders this
    // modal.
    //
    // `sup_one` is free-text from the Employee Master Excel import, not a
    // foreign key, and checking it against the real data shows no single
    // consistent convention: usually "First Last" while `employees.name` is
    // the full "First Middle Last" (sup_one "Joel Grospe" for the employee
    // actually named "Joel Concepcion Grospe"), occasionally reversed
    // "Last First" (sup_one "Bicol Aljon" for "Aljon Tobes Bicol"), and at
    // least once naming two non-adjacent inner segments of a longer name
    // (sup_one "Jedaver Opingo" for "Mary Grace Jedaver Pancho Opingo"). A
    // plain full-name match, or even a fixed first-word/last-word match,
    // therefore misses most real rows. The rule that actually covers all of
    // these at once: every word in `sup_one` must appear as a whole word
    // somewhere in the candidate's name, in any order — checked against
    // this app's actual ~100-employee roster to confirm it never resolves
    // one `sup_one` to more than one candidate. Still a full-word match
    // (never a partial/substring guess within a word), and an employee is
    // never matched to themselves — an Immediate Head can't be their own. A
    // `sup_one` that still doesn't resolve to any current employee (a typo,
    // a since-renamed/removed employee, or someone who was never in this
    // roster to begin with — e.g. an executive tracked elsewhere) simply
    // resolves to null, which the picker treats as "no suggestion, pick
    // manually", never an error.
    $nameWordSet = fn (?string $name) => $name
        ? array_unique(array_map('mb_strtolower', preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY)))
        : [];
    $employeeWordSets = $employees->map(fn ($employee) => ['id' => $employee->id, 'words' => $nameWordSet($employee->name)]);
    $immediateHeadByEmployeeId = $employees->mapWithKeys(function ($employee) use ($employeeWordSets, $nameWordSet) {
        $supWords = $nameWordSet($employee->sup_one);

        if (empty($supWords)) {
            return [$employee->id => null];
        }

        $match = $employeeWordSets->first(
            fn ($candidate) => $candidate['id'] !== $employee->id && empty(array_diff($supWords, $candidate['words']))
        );

        return [$employee->id => $match['id'] ?? null];
    });
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
                            immediateHeadBySupOne: @js($immediateHeadByEmployeeId),
                            // Looks up the newly-selected Offboardee's `sup_one`-matched
                            // Immediate Head (if any) and applies it directly to the
                            // Immediate Head <select> — found via a plain `document` query
                            // (not $el/$refs: this method is invoked from the Employee
                            // select's OWN nested x-data scope via its @change, and Alpine
                            // resolves $el/$refs to the NEAREST x-data in that call chain —
                            // the Employee select's own small wrapper div — not this outer
                            // one, even though this method is defined here; `document` sidesteps
                            // that ambiguity, and this modal only ever has one instance live
                            // on a page) — dispatching a real 'change' event afterward so
                            // that select's own isOptionSelected/label-color state updates
                            // exactly as if the user had picked it themselves. No match
                            // (blank sup_one, or a name that doesn't match any current
                            // employee) clears the field back to 'Select an immediate head'
                            // so the user can still pick one manually, per spec.
                            setImmediateHeadFromEmployee(employeeId) {
                                const matchedId = this.immediateHeadBySupOne[employeeId];
                                const select = document.querySelector('select[name=immediate_head_id]');
                                select.value = matchedId ? String(matchedId) : '';
                                select.dispatchEvent(new Event('change'));
                            },
                        }">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                Employee <span class="text-error-500">*</span>
                            </label>
                            <div x-data="{ isOptionSelected: false }" class="relative z-20 bg-transparent">
                                <select name="employee_id" required
                                    @change="isOptionSelected = true; setImmediateHeadFromEmployee($event.target.value)"
                                    :class="isOptionSelected && 'text-gray-800 dark:text-white/90'"
                                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 pr-11 text-sm text-gray-500 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800">
                                    <option value="" class="text-gray-700 dark:bg-gray-900 dark:text-gray-400">
                                        Select an employee
                                    </option>
                                    @foreach ($employees as $employee)
                                        <option value="{{ $employee->id }}" @selected(old('employee_id') == $employee->id)
                                            class="text-gray-700 dark:bg-gray-900 dark:text-gray-400">
                                            {{ $employee->name }} ({{ $employee->employee_code }}) &mdash; {{ $employee->department }}
                                        </option>
                                    @endforeach
                                </select>
                                <span class="pointer-events-none absolute top-1/2 right-4 z-30 -translate-y-1/2 text-gray-500 dark:text-gray-400">
                                    <svg class="stroke-current" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M4.79175 7.396L10.0001 12.6043L15.2084 7.396" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </span>
                            </div>
                            @error('employee_id')
                                <p class="mt-1.5 text-xs text-error-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                Immediate Head
                            </label>
                            <div x-data="{ isOptionSelected: {{ old('immediate_head_id') ? 'true' : 'false' }} }" class="relative z-20 bg-transparent">
                                <select name="immediate_head_id" @change="isOptionSelected = true"
                                    :class="isOptionSelected && 'text-gray-800 dark:text-white/90'"
                                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 pr-11 text-sm text-gray-500 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800">
                                    <option value="" class="text-gray-700 dark:bg-gray-900 dark:text-gray-400">
                                        Select an immediate head
                                    </option>
                                    @foreach ($employees as $employee)
                                        <option value="{{ $employee->id }}" @selected(old('immediate_head_id') == $employee->id)
                                            class="text-gray-700 dark:bg-gray-900 dark:text-gray-400">
                                            {{ $employee->name }} ({{ $employee->employee_code }}) &mdash; {{ $employee->department }}
                                        </option>
                                    @endforeach
                                </select>
                                <span class="pointer-events-none absolute top-1/2 right-4 z-30 -translate-y-1/2 text-gray-500 dark:text-gray-400">
                                    <svg class="stroke-current" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M4.79175 7.396L10.0001 12.6043L15.2084 7.396" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </span>
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

                    <div class="col-span-2 lg:col-span-1">
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Resignation Type
                        </label>
                        <input type="text" name="resignation_type" value="{{ old('resignation_type') }}" placeholder="e.g. Voluntary"
                            class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
                        @error('resignation_type')
                            <p class="mt-1.5 text-xs text-error-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="col-span-2 lg:col-span-1">
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Reason <span class="text-error-500">*</span>
                        </label>
                        <div x-data="{ isOptionSelected: {{ old('reason') ? 'true' : 'false' }} }" class="relative z-20 bg-transparent">
                            <select name="reason" required @change="isOptionSelected = true"
                                :class="isOptionSelected && 'text-gray-800 dark:text-white/90'"
                                class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 pr-11 text-sm text-gray-500 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800">
                                <option value="" class="text-gray-700 dark:bg-gray-900 dark:text-gray-400">Select a reason</option>
                                @foreach (['resignation' => 'Resignation', 'termination' => 'Termination', 'retirement' => 'Retirement', 'layoff' => 'Layoff', 'other' => 'Other'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('reason') === $value) class="text-gray-700 dark:bg-gray-900 dark:text-gray-400">
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                            <span class="pointer-events-none absolute top-1/2 right-4 z-30 -translate-y-1/2 text-gray-500 dark:text-gray-400">
                                <svg class="stroke-current" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M4.79175 7.396L10.0001 12.6043L15.2084 7.396" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </span>
                        </div>
                        @error('reason')
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
                <button type="submit" :disabled="submitting"
                    class="flex w-full items-center justify-center gap-2 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630] disabled:cursor-not-allowed disabled:opacity-70 sm:w-auto">
                    <span x-show="submitting" class="h-4 w-4 animate-spin rounded-full border-2 border-solid border-white border-t-transparent"></span>
                    <span x-text="submitting ? 'Submitting...' : 'Submit Request'"></span>
                </button>
            </div>
        </form>
    </div>
</x-ui.modal>
