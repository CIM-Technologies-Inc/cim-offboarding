@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="Offboardees" />

    <div class="mb-4 flex items-center justify-end md:mb-6">
        <x-offboarding.new-request-modal :employees="$employeesNotOffboarded" :email-templates="$activeEmailTemplates" :separation-types="$separationTypes" />
    </div>

    <div x-data="{
        search: '',
        names: @js($offboardees->pluck('name')->map(fn ($name) => Str::lower($name))),
        hasMatches() {
            const query = this.search.trim().toLowerCase();
            return query === '' || this.names.some((name) => name.includes(query));
        }
    }">
        @php
            $statusLabelsForFilter = [
                'pending' => 'Pending',
                'in_progress' => 'In Progress',
                'overdue' => 'Overdue',
                'completed' => 'Completed',
                'cancelled' => 'Cancelled',
            ];
        @endphp

        @if ($statusFilter || $departmentFilter)
            <div class="mb-6 flex flex-wrap items-center gap-2">
                <span class="text-sm text-gray-500 dark:text-gray-400">Filtered by:</span>
                @if ($statusFilter)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[#145a3a]/10 px-3 py-1 text-xs font-medium text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]">
                        {{ $statusLabelsForFilter[$statusFilter] ?? ucfirst($statusFilter) }}
                        <a href="{{ route('offboardees.index', ['department' => $departmentFilter]) }}" class="hover:text-error-500">
                            <svg width="12" height="12" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M13.5 4.5L4.5 13.5M4.5 4.5L13.5 13.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </a>
                    </span>
                @endif
                @if ($departmentFilter)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[#145a3a]/10 px-3 py-1 text-xs font-medium text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]">
                        {{ $departmentFilter }}
                        <a href="{{ route('offboardees.index', ['status' => $statusFilter]) }}" class="hover:text-error-500">
                            <svg width="12" height="12" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M13.5 4.5L4.5 13.5M4.5 4.5L13.5 13.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </a>
                    </span>
                @endif
                <a href="{{ route('offboardees.index') }}" class="text-xs font-medium text-gray-400 hover:text-error-500 underline">
                    Clear all
                </a>
            </div>
        @endif

        @if ($offboardees->isEmpty())
            <div class="rounded-2xl border border-gray-200 bg-white p-10 text-center dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    @if ($statusFilter)
                        No employees currently match this status.
                    @else
                        No employees are currently in the offboarding process.
                    @endif
                </p>
            </div>
        @else
            <div class="mb-6 flex flex-wrap items-center gap-3">
                <div class="relative max-w-sm flex-1">
                    <input type="text" x-model="search" placeholder="Search by name..."
                        class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none py-2.5 pr-4 pl-11 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
                    <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2">
                        <svg class="fill-gray-500 dark:fill-gray-400" width="18" height="18" viewBox="0 0 20 20" fill="none">
                            <path fill-rule="evenodd" clip-rule="evenodd"
                                d="M3.04175 9.37363C3.04175 5.87693 5.87711 3.04199 9.37508 3.04199C12.8731 3.04199 15.7084 5.87693 15.7084 9.37363C15.7084 12.8703 12.8731 15.7053 9.37508 15.7053C5.87711 15.7053 3.04175 12.8703 3.04175 9.37363ZM9.37508 1.54199C5.04902 1.54199 1.54175 5.04817 1.54175 9.37363C1.54175 13.6991 5.04902 17.2053 9.37508 17.2053C11.2674 17.2053 13.003 16.5344 14.357 15.4176L17.177 18.238C17.4699 18.5309 17.9448 18.5309 18.2377 18.238C18.5306 17.9451 18.5306 17.4703 18.2377 17.1774L15.418 14.3573C16.5365 13.0033 17.2084 11.2669 17.2084 9.37363C17.2084 5.04817 13.7011 1.54199 9.37508 1.54199Z"
                                fill="" />
                        </svg>
                    </span>
                </div>

                <!-- Filter Button + Dropdown -->
                <div x-data="{ open: false }" class="relative" @click.away="open = false">
                    <button type="button" @click="open = !open"
                        class="shadow-theme-xs flex h-11 items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                        <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M2.25 4.5h13.5M4.5 9h9M7.5 13.5h3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        Filter
                        @if ($statusFilter || $departmentFilter)
                            <span class="flex h-4 w-4 items-center justify-center rounded-full bg-[#145a3a] text-[10px] font-semibold text-white dark:bg-[#3aa876]">
                                {{ collect([$statusFilter, $departmentFilter])->filter()->count() }}
                            </span>
                        @endif
                    </button>

                    <div x-show="open" x-cloak x-transition
                        class="shadow-theme-lg absolute right-0 z-40 mt-2 w-72 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                        <form method="GET" action="{{ route('offboardees.index') }}" class="space-y-4">
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Status
                                </label>
                                <div class="relative">
                                    <select name="status"
                                        class="dark:bg-dark-900 h-10 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-3 pr-9 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800">
                                        <option value="">All Statuses</option>
                                        @foreach ($statusLabelsForFilter as $value => $label)
                                            <option value="{{ $value }}" @selected($statusFilter === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-gray-500 dark:text-gray-400">
                                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M4.79175 7.396L10.0001 12.6043L15.2084 7.396" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </span>
                                </div>
                            </div>

                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Department
                                </label>
                                <div class="relative">
                                    <select name="department"
                                        class="dark:bg-dark-900 h-10 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-3 pr-9 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800">
                                        <option value="">All Departments</option>
                                        @foreach ($departments as $department)
                                            <option value="{{ $department }}" @selected($departmentFilter === $department)>{{ $department }}</option>
                                        @endforeach
                                    </select>
                                    <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-gray-500 dark:text-gray-400">
                                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M4.79175 7.396L10.0001 12.6043L15.2084 7.396" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </span>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 pt-1">
                                <a href="{{ route('offboardees.index') }}"
                                    class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-center text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                                    Clear
                                </a>
                                <button type="submit" data-turbo-submits-with="Applying..."
                                    class="flex-1 rounded-lg bg-[#145a3a] px-3 py-2 text-sm font-medium text-white hover:bg-[#0f4630]">
                                    Apply
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div x-show="!hasMatches()" class="rounded-2xl border border-gray-200 bg-white p-10 text-center dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No employees match "<span x-text="search"></span>".
                </p>
            </div>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($offboardees as $offboardee)
                    @php
                        // No 'cancelled' entry here: a cancelled request is
                        // excluded from $offboardees entirely (see
                        // OffboardeeController::index()), so this card loop
                        // can never encounter that status.
                        $statusStyles = [
                            'pending' => 'bg-yellow-50 text-yellow-700 dark:bg-yellow-500/15 dark:text-yellow-400',
                            'in_progress' => 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400',
                            'overdue' => 'bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400',
                            'completed' => 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400',
                        ];
                        $statusLabels = [
                            'pending' => 'Pending',
                            'in_progress' => 'In Progress',
                            'overdue' => 'Overdue',
                            'completed' => 'Completed',
                        ];
                    @endphp
                    <div x-data="{ cancelling: false, extending: false }"
                        data-offboardee-card="{{ $offboardee['id'] }}"
                        @click="if (!cancelling && !extending) $dispatch('open-offboardee-modal', @js($offboardee))"
                        x-show="search.trim() === '' || @js(Str::lower($offboardee['name'])).includes(search.trim().toLowerCase())"
                        class="group cursor-pointer rounded-2xl border border-gray-200 bg-white p-5 transition-all duration-200 hover:-translate-y-1 hover:border-[#145a3a]/40 hover:shadow-lg dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-[#3aa876]/40">
                        <div class="flex items-start justify-between">
                            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-base font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                {{ collect(explode(' ', $offboardee['name']))->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}
                            </div>
                            <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $statusStyles[$offboardee['status']] ?? $statusStyles['pending'] }}">
                                {{ $statusLabels[$offboardee['status']] ?? ucfirst($offboardee['status']) }}
                            </span>
                        </div>

                        <h4 class="mt-4 text-base font-semibold text-gray-800 group-hover:text-[#145a3a] dark:text-white/90 dark:group-hover:text-[#3aa876]">
                            {{ $offboardee['name'] }}
                        </h4>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $offboardee['designation'] }}</p>

                        <div class="mt-4 space-y-1.5 border-t border-gray-100 pt-4 dark:border-gray-800">
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-gray-400">Department</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $offboardee['department'] }}</span>
                            </div>
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-gray-400">Last Working Day</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $offboardee['lastWorkingDay'] ?? '—' }}</span>
                            </div>
                        </div>

                        <!-- @can('offboarding-requests.reset')
                            @if ($offboardee['resetOffboardingUrl'] && $offboardee['hasOffboardingProgress'] && $offboardee['status'] !== 'completed')
                                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-800" :class="{ 'pointer-events-none opacity-50': cancelling }" @click.stop="">
                                    <form method="POST" action="{{ $offboardee['resetOffboardingUrl'] }}" x-data="{ confirmed: false }"
                                        @submit="if (!confirmed) {
                                            $event.preventDefault();
                                            Swal.fire({
                                                title: 'Reset this offboarding request?',
                                                text: 'This will permanently clear all checklist progress, approvals, and signatory clearances for ' + @js($offboardee['name']) + ', and restart the entire offboarding process from the beginning — exactly as if it were newly created. This cannot be undone.',
                                                icon: 'warning',
                                                showCancelButton: true,
                                                confirmButtonText: 'Reset',
                                                cancelButtonText: 'Cancel',
                                                confirmButtonColor: '#dc2626',
                                                cancelButtonColor: '#145a3a',
                                                reverseButtons: true
                                            }).then((result) => { if (result.isConfirmed) { confirmed = true; $el.requestSubmit(); } });
                                        }">
                                        @csrf
                                        <button type="submit" :disabled="cancelling" data-turbo-submits-with="Resetting..."
                                            class="flex w-full items-center justify-center gap-1.5 rounded-lg border border-error-200 px-3 py-2 text-xs font-medium text-error-600 hover:bg-error-50 disabled:cursor-not-allowed dark:border-error-500/30 dark:text-error-400 dark:hover:bg-error-500/10">
                                            <svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <path d="M4.16667 10C4.16667 6.77834 6.77834 4.16667 10 4.16667C11.6928 4.16667 13.2144 4.88883 14.2765 6.04167H12.5C12.1548 6.04167 11.875 6.32149 11.875 6.66667C11.875 7.01185 12.1548 7.29167 12.5 7.29167H15.8333C16.1785 7.29167 16.4583 7.01185 16.4583 6.66667V3.33333C16.4583 2.98816 16.1785 2.70833 15.8333 2.70833C15.4882 2.70833 15.2083 2.98816 15.2083 3.33333V5.01603C13.912 3.66086 12.0555 2.91667 10 2.91667C6.08798 2.91667 2.91667 6.08798 2.91667 10C2.91667 13.912 6.08798 17.0833 10 17.0833C13.129 17.0833 15.7828 15.0645 16.7367 12.2635C16.8477 11.9367 16.6726 11.5818 16.3458 11.4709C16.019 11.36 15.6641 11.535 15.5532 11.8618C14.7663 14.1729 12.5765 15.8333 10 15.8333C6.77834 15.8333 4.16667 13.2217 4.16667 10Z" fill="currentColor" />
                                            </svg>
                                            Reset Offboarding
                                        </button>
                                    </form>
                                </div>
                            @endif
                        @endcan -->

                        @can('offboarding-requests.cancel')
                            @if ($offboardee['cancelOffboardingUrl'] && $offboardee['status'] !== 'cancelled' && $offboardee['status'] !== 'completed')
                                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-800" @click.stop="">
                                    {{-- Deliberately not a native form submit: cancellation must stay on
                                         THIS page and remove just this one card the instant the server
                                         confirms success, with no full-page reload — a plain
                                         `$el.requestSubmit()`/Turbo navigation can't do that. The fetch
                                         call awaits the full response (DB transaction + notification
                                         dispatch all complete server-side before it resolves), so the
                                         loading state below covers the entire cancellation, not just the
                                         network round trip start. --}}
                                    <form method="POST" action="{{ $offboardee['cancelOffboardingUrl'] }}"
                                        @submit.prevent="if (cancelling) return;
                                            Swal.fire({
                                                title: 'Cancel Offboarding Request?',
                                                text: 'Are you sure you want to cancel this offboarding request for ' + @js($offboardee['name']) + '? This action will retract the request and remove all records associated with this offboarding process.',
                                                icon: 'warning',
                                                showCancelButton: true,
                                                confirmButtonText: 'Confirm Cancellation',
                                                cancelButtonText: 'Cancel',
                                                confirmButtonColor: '#dc2626',
                                                cancelButtonColor: '#6b7280',
                                                reverseButtons: true
                                            }).then((result) => {
                                                if (!result.isConfirmed) return;
                                                cancelling = true;
                                                fetch($el.action, {
                                                    method: 'POST',
                                                    headers: {
                                                        'Accept': 'application/json',
                                                        'X-Requested-With': 'XMLHttpRequest',
                                                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                                    },
                                                }).then(async (response) => {
                                                    const data = await response.json().catch(() => ({}));
                                                    if (!response.ok) {
                                                        throw new Error(data.message || 'Cancellation failed. Please try again.');
                                                    }
                                                    $el.closest('[data-offboardee-card]')?.remove();
                                                    Swal.fire({
                                                        icon: 'success',
                                                        title: 'Cancelled',
                                                        text: data.message || 'Offboarding request has been successfully cancelled.',
                                                        confirmButtonColor: '#145a3a',
                                                    });
                                                }).catch((error) => {
                                                    cancelling = false;
                                                    Swal.fire({
                                                        icon: 'error',
                                                        title: 'Cancellation Failed',
                                                        text: error.message,
                                                        confirmButtonColor: '#145a3a',
                                                    });
                                                });
                                            });
                                        ">
                                        @csrf
                                        <button type="submit" :disabled="cancelling"
                                            class="flex w-full items-center justify-center gap-1.5 rounded-lg border border-error-200 px-3 py-2 text-xs font-medium text-error-600 hover:bg-error-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-error-500/30 dark:text-error-400 dark:hover:bg-error-500/10">
                                            <svg x-show="!cancelling" width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <path d="M10 17.5C14.1421 17.5 17.5 14.1421 17.5 10C17.5 5.85786 14.1421 2.5 10 2.5C5.85786 2.5 2.5 5.85786 2.5 10C2.5 14.1421 5.85786 17.5 10 17.5Z" stroke="currentColor" stroke-width="1.5" />
                                                <path d="M7.5 7.5L12.5 12.5M12.5 7.5L7.5 12.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                                            </svg>
                                            <svg x-show="cancelling" x-cloak class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                            </svg>
                                            <span x-text="cancelling ? 'Cancelling...' : 'Cancel Offboarding'"></span>
                                        </button>
                                    </form>
                                </div>
                            @endif
                        @endcan

                        @can('checklists.extend-due')
                            @if ($offboardee['extendAllDueUrl'] && $offboardee['canBulkExtendDue'])
                                <div class="border-t border-gray-100 pt-4 dark:border-gray-800" :class="{ 'pointer-events-none opacity-50': cancelling }" @click.stop="">
                                    {{-- Same fetch-based (not native form submit) convention as Cancel
                                         Offboarding above — the loading state must cover the ENTIRE
                                         bulk extension (every checklist's due date pushed forward, its
                                         own audit row + Timeline entry, and the extension emails), not
                                         just the network round trip start. --}}
                                    <form method="POST" action="{{ $offboardee['extendAllDueUrl'] }}"
                                        @submit.prevent="if (extending) return;
                                            Swal.fire({
                                                title: 'Extend Due Dates',
                                                html: '&lt;p style=\'text-align:left;font-size:13px;line-height:1.6;\'&gt;All applicable checklists have reached their due date. Please specify the number of days you want to extend the due dates.&lt;/p&gt;'
                                                    + '&lt;label style=\'display:block;text-align:left;font-size:12px;color:#6b7280;margin:12px 0 4px;\'&gt;Extension Days&lt;/label&gt;',
                                                input: 'number',
                                                inputAttributes: { min: 1, step: 1 },
                                                inputPlaceholder: 'e.g. 7',
                                                showCancelButton: true,
                                                confirmButtonText: 'Extend Due',
                                                cancelButtonText: 'Cancel',
                                                confirmButtonColor: '#145a3a',
                                                cancelButtonColor: '#6b7280',
                                                reverseButtons: true,
                                                inputValidator: (value) => {
                                                    const days = Number(value);
                                                    if (!value || !Number.isInteger(days) || days &lt; 1) {
                                                        return 'Enter a valid positive whole number of days.';
                                                    }
                                                }
                                            }).then((result) => {
                                                if (!result.isConfirmed) return;
                                                extending = true;
                                                fetch($el.action, {
                                                    method: 'POST',
                                                    headers: {
                                                        'Accept': 'application/json',
                                                        'X-Requested-With': 'XMLHttpRequest',
                                                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                                        'Content-Type': 'application/json',
                                                    },
                                                    body: JSON.stringify({ additional_extension_days: Number(result.value) }),
                                                }).then(async (response) => {
                                                    const data = await response.json().catch(() => ({}));
                                                    if (!response.ok) {
                                                        throw new Error(data.message || 'Extending due dates failed. Please try again.');
                                                    }
                                                    extending = false;
                                                    Swal.fire({
                                                        icon: 'success',
                                                        title: 'Due Dates Extended',
                                                        text: data.message || 'Due dates have been successfully extended.',
                                                        confirmButtonColor: '#145a3a',
                                                    }).then(() => window.location.reload());
                                                }).catch((error) => {
                                                    extending = false;
                                                    Swal.fire({
                                                        icon: 'error',
                                                        title: 'Extension Failed',
                                                        text: error.message,
                                                        confirmButtonColor: '#145a3a',
                                                    });
                                                });
                                            });
                                        ">
                                        @csrf
                                        <button type="submit" :disabled="extending"
                                            class="flex w-full items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                            <svg x-show="!extending" width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <path d="M10 5.83334V10L12.9167 12.9167" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                                <path d="M17.5 10C17.5 14.1421 14.1421 17.5 10 17.5C5.85786 17.5 2.5 14.1421 2.5 10C2.5 5.85786 5.85786 2.5 10 2.5C14.1421 2.5 17.5 5.85786 17.5 10Z" stroke="currentColor" stroke-width="1.5" />
                                            </svg>
                                            <svg x-show="extending" x-cloak class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                            </svg>
                                            <span x-text="extending ? 'Extending...' : 'Extend Due'"></span>
                                        </button>
                                    </form>
                                </div>
                            @endif
                        @endcan

                        @can('final-approval.send')
                            {{-- Only ever shown for a request whose real `status` column is
                                 'completed' — never based on `displayStatus()`, which can read
                                 differently for unrelated reasons (overdue, etc.). --}}
                            @if ($offboardee['status'] === 'completed' && $offboardee['finalApprovalUrl'])
                                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-800" @click.stop="">
                                    @if ($offboardee['finalApprovalStatus'] === 'approved')
                                        <div class="flex w-full items-center justify-center gap-1.5 rounded-lg bg-[#145a3a]/10 px-3 py-2 text-xs font-medium text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]">
                                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <path fill-rule="evenodd" clip-rule="evenodd" d="M13.4767 4.10714C13.7788 4.38292 13.8008 4.85162 13.5257 5.15436L6.83817 12.5211C6.69758 12.6759 6.49882 12.7644 6.29008 12.7644C6.08134 12.7644 5.88258 12.6759 5.74199 12.5211L2.47426 8.9211C2.19916 8.61836 2.22119 8.14966 2.52326 7.87388C2.82533 7.5981 3.29283 7.62018 3.56793 7.92292L6.29008 10.9184L12.4321 4.15582C12.7072 3.85308 13.1746 3.83137 13.4767 4.10714Z" fill="currentColor" />
                                            </svg>
                                            Approved
                                        </div>
                                    @else
                                        @php
                                            $finalApprovalConfirmTitle = $offboardee['finalApprovalStatus'] === 'pending'
                                                ? 'Resend the Final Approval request?'
                                                : 'Send this offboarding request for Final Approval?';
                                        @endphp
                                        <form method="POST" action="{{ $offboardee['finalApprovalUrl'] }}" x-data="{ confirmed: false }"
                                            @submit="if (!confirmed) {
                                                $event.preventDefault();
                                                Swal.fire({
                                                    title: @js($finalApprovalConfirmTitle),
                                                    text: 'This will email the currently active Final Signatory' + (@js($offboardee['finalSignatoryName']) ? (' (' + @js($offboardee['finalSignatoryName']) + ')') : '') + ' the completed Clearance Form for ' + @js($offboardee['name']) + ' and a one-click Approve link.',
                                                    icon: 'question',
                                                    showCancelButton: true,
                                                    confirmButtonText: 'Send',
                                                    cancelButtonText: 'Cancel',
                                                    confirmButtonColor: '#145a3a',
                                                    cancelButtonColor: '#6b7280',
                                                    reverseButtons: true
                                                }).then((result) => { if (result.isConfirmed) { confirmed = true; $el.requestSubmit(); } });
                                            }">
                                            @csrf
                                            <button type="submit" data-turbo-submits-with="Sending..."
                                                class="flex w-full items-center justify-center gap-1.5 rounded-lg border border-[#145a3a]/30 px-3 py-2 text-xs font-medium text-[#145a3a] hover:bg-[#145a3a]/5 dark:border-[#3aa876]/30 dark:text-[#3aa876] dark:hover:bg-[#3aa876]/10">
                                                <svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <path d="M3.5 5.5L10 10.5L16.5 5.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                                    <path d="M3.5 5.5C3.5 4.94772 3.94772 4.5 4.5 4.5H15.5C16.0523 4.5 16.5 4.94772 16.5 5.5V14C16.5 14.5523 16.0523 15 15.5 15H4.5C3.94772 15 3.5 14.5523 3.5 14V5.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                                                </svg>
                                                {{ $offboardee['finalApprovalStatus'] === 'pending' ? 'Resend Final Approval' : 'Final Approval' }}
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            @endif
                        @endcan
                    </div>
                @endforeach
            </div>
        @endif

        <!-- Offboardee Status Modal -->
        <x-offboarding.status-timeline-modal :initial="$deepLinkOffboardee" :email-templates="$activeEmailTemplates" />
    </div>
@endsection
