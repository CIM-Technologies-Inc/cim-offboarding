@props([
    'employees' => [],
])

@php
    $offboardingRequestFields = ['employee_id', 'notice_date', 'last_working_day', 'resignation_type', 'reason', 'notice_period', 'approval_mode'];
    $offboardingRequestHasErrors = $errors->hasAny($offboardingRequestFields);
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
                    <div class="col-span-2">
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Employee <span class="text-error-500">*</span>
                        </label>
                        <div x-data="{ isOptionSelected: false }" class="relative z-20 bg-transparent">
                            <select name="employee_id" required @change="isOptionSelected = true"
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

                    <div class="col-span-2 lg:col-span-1">
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Notice Period <span class="text-error-500">*</span>
                        </label>
                        <div x-data="{ isOptionSelected: {{ old('notice_period') ? 'true' : 'false' }} }" class="relative z-20 bg-transparent">
                            <select name="notice_period" required @change="isOptionSelected = true"
                                :class="isOptionSelected && 'text-gray-800 dark:text-white/90'"
                                class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 pr-11 text-sm text-gray-500 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800">
                                <option value="" class="text-gray-700 dark:bg-gray-900 dark:text-gray-400">Select notice period</option>
                                @foreach (['Immediate', '15 Days', '30 Days', '60 Days', '90 Days'] as $option)
                                    <option value="{{ $option }}" @selected(old('notice_period') === $option) class="text-gray-700 dark:bg-gray-900 dark:text-gray-400">
                                        {{ $option }}
                                    </option>
                                @endforeach
                            </select>
                            <span class="pointer-events-none absolute top-1/2 right-4 z-30 -translate-y-1/2 text-gray-500 dark:text-gray-400">
                                <svg class="stroke-current" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M4.79175 7.396L10.0001 12.6043L15.2084 7.396" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </span>
                        </div>
                        @error('notice_period')
                            <p class="mt-1.5 text-xs text-error-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="col-span-2">
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Approval Mode <span class="text-error-500">*</span>
                        </label>
                        <div class="flex items-center gap-6">
                            <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                                <input type="radio" name="approval_mode" value="sync" required class="h-4 w-4 accent-brand-500"
                                    @checked(old('approval_mode', 'async') === 'sync') />
                                Sync
                            </label>
                            <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                                <input type="radio" name="approval_mode" value="async" required class="h-4 w-4 accent-brand-500"
                                    @checked(old('approval_mode', 'async') === 'async') />
                                Async
                            </label>
                        </div>
                        @error('approval_mode')
                            <p class="mt-1.5 text-xs text-error-500">{{ $message }}</p>
                        @enderror
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
