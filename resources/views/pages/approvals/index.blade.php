@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="Your Approval" />

    {{-- Fires the SweetAlert toast for flashed success/status messages — most
         relevantly, the redirect from the "Approve" email link. --}}
    <div x-data="flashToast(@js(session('success')), null)"></div>

    @if (session('success'))
        <div class="mb-6 rounded-lg border border-success-500 bg-success-50 px-4 py-3 text-sm text-success-600 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-400">
            {{ session('success') }}
        </div>
    @endif

    <div>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Review pending offboarding requests and approve or decline them.
            </p>

            {{-- Purely a display preference for this approver's own queue —
                 whether several checklists assigned to them for the same
                 offboardee show as one combined card or as separate cards.
                 Never changes the underlying checklist assignments or
                 approval workflow, only how they're grouped here. --}}
            <form method="POST" action="{{ route('approvals.update-display-preference') }}" class="flex items-center gap-2">
                @csrf
                @method('PATCH')
                <span class="text-xs font-medium {{ !$combineChecklists ? 'text-[#145a3a] dark:text-[#3aa876]' : 'text-gray-400' }}">
                    Separate Checklist
                </span>
                <label class="relative inline-flex cursor-pointer items-center">
                    <input type="checkbox" name="separate_checklists" value="1" class="peer sr-only disabled:cursor-not-allowed disabled:opacity-50"
                        onchange="this.form.requestSubmit(); this.disabled = true;" @checked(! $combineChecklists) />
                    <div class="peer h-6 w-11 rounded-full bg-gray-200 transition-colors duration-200 peer-checked:bg-[#145a3a] peer-focus:outline-hidden after:absolute after:top-0.5 after:left-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:transition-all after:duration-200 after:content-[''] peer-checked:after:translate-x-5 dark:bg-gray-700">
                    </div>
                </label>
                <!-- <span class="text-xs font-medium {{ ! $combineChecklists ? 'text-[#145a3a] dark:text-[#3aa876]' : 'text-gray-400' }}">
                    Separate Checklist
                </span> -->
            </form>
        </div>

        @php
            $statusBadges = [
                'pending' => ['label' => 'Pending', 'class' => 'bg-yellow-50 text-yellow-700 dark:bg-yellow-500/15 dark:text-yellow-400'],
                'assigned' => ['label' => 'Assigned', 'class' => 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400'],
                'in_progress' => ['label' => 'In Progress', 'class' => 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400'],
                'done' => ['label' => 'Done', 'class' => 'bg-teal-50 text-teal-700 dark:bg-teal-500/15 dark:text-teal-400'],
                'approved' => ['label' => 'Approved', 'class' => 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400'],
                'declined' => ['label' => 'Declined', 'class' => 'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400'],
                // A declined checklist is now placed On Hold (see
                // `ApprovalController::decline()`) rather than staying
                // 'declined' — kept as its own amber state, distinct from
                // both 'declined' (legacy rows only) and 'overdue'.
                'on_hold' => ['label' => 'On Hold', 'class' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400'],
                'overdue' => ['label' => 'Overdue', 'class' => 'bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400'],
                'ready_for_approval' => ['label' => 'Ready for Approval', 'class' => 'bg-[#145a3a]/10 text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]'],
            ];

            // Filter dropdown/chip labels for the Status filter — deliberately
            // its own map, distinct from `$statusBadges` above: the filter
            // groups several underlying `displayStatus` values under one
            // option (e.g. "In Progress" covers both 'in_progress' and
            // 'assigned' — see `ApprovalController::index()`'s own matching
            // comment for why), so it can't just reuse that per-card map.
            $statusFilterLabels = [
                'pending' => 'Pending',
                'in_progress' => 'In Progress',
                'completed' => 'Completed',
                'due' => 'Due',
            ];

            // One lowercase "haystack" per card — every searchable employee
            // field joined into a single string — so the Search field below
            // can match First/Last/Middle Name and Employee Number with one
            // simple `.includes()` per card, the same technique
            // `pages/offboardees/index.blade.php` already uses for its own
            // (name-only) search field.
            $searchHaystacks = $approvals->map(fn ($approval) => Str::lower(collect([
                $approval['name'],
                $approval['firstName'] ?? null,
                $approval['lastName'] ?? null,
                $approval['middleName'] ?? null,
                $approval['employeeCode'],
            ])->filter()->implode(' ')))->values();
        @endphp

        {{-- Filtered-by chips + Clear all — outside the empty/non-empty
             branch below so a filter combination that currently matches
             nothing still leaves a visible way back, same convention as
             `pages/offboardees/index.blade.php`. --}}
        @if ($statusFilter || $departmentFilter || $checklistFilter)
            <div class="mb-6 flex flex-wrap items-center gap-2">
                <span class="text-sm text-gray-500 dark:text-gray-400">Filtered by:</span>
                @if ($statusFilter)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[#145a3a]/10 px-3 py-1 text-xs font-medium text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]">
                        {{ $statusFilterLabels[$statusFilter] ?? ucfirst($statusFilter) }}
                        <a href="{{ route('approvals.index', ['department' => $departmentFilter, 'checklist' => $checklistFilter]) }}" class="hover:text-error-500">
                            <svg width="12" height="12" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M13.5 4.5L4.5 13.5M4.5 4.5L13.5 13.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </a>
                    </span>
                @endif
                @if ($departmentFilter)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[#145a3a]/10 px-3 py-1 text-xs font-medium text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]">
                        {{ $departmentFilter }}
                        <a href="{{ route('approvals.index', ['status' => $statusFilter, 'checklist' => $checklistFilter]) }}" class="hover:text-error-500">
                            <svg width="12" height="12" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M13.5 4.5L4.5 13.5M4.5 4.5L13.5 13.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </a>
                    </span>
                @endif
                @if ($checklistFilter)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[#145a3a]/10 px-3 py-1 text-xs font-medium text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]">
                        {{ $checklistFilter }}
                        <a href="{{ route('approvals.index', ['status' => $statusFilter, 'department' => $departmentFilter]) }}" class="hover:text-error-500">
                            <svg width="12" height="12" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M13.5 4.5L4.5 13.5M4.5 4.5L13.5 13.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </a>
                    </span>
                @endif
                <a href="{{ route('approvals.index') }}" class="text-xs font-medium text-gray-400 hover:text-error-500 underline">
                    Clear all
                </a>
            </div>
        @endif

        @if ($approvals->isEmpty())
            <div class="rounded-2xl border border-gray-200 bg-white p-10 text-center dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    @if ($statusFilter || $departmentFilter || $checklistFilter)
                        No offboarding requests match the selected filters.
                    @else
                        No offboarding requests are waiting for approval.
                    @endif
                </p>
            </div>
        @else
            <div x-data="{
                search: '',
                haystacks: @js($searchHaystacks),
                hasMatches() {
                    const query = this.search.trim().toLowerCase();
                    return query === '' || this.haystacks.some((haystack) => haystack.includes(query));
                },
                // Live 5-days-before / on-or-after Clearance Signing Due
                // Date urgency for every card's Clearance Signing Due row
                // below — same thresholds as the checklist/General Signatory
                // modals' own clearanceSigningUrgency(), shared here across
                // every card via one tick instead of one setInterval per
                // card.
                nowTick: Date.now(),
                init() {
                    setInterval(() => {
                        this.nowTick = Date.now();
                    }, 60000);
                },
                clearanceSigningUrgency(iso) {
                    if (!iso) {
                        return 'none';
                    }
                    const due = new Date(iso).getTime();
                    if (Number.isNaN(due)) {
                        return 'none';
                    }
                    if (this.nowTick >= due) {
                        return 'danger';
                    }
                    if (due - this.nowTick <= 5 * 24 * 60 * 60 * 1000) {
                        return 'warning';
                    }
                    return 'none';
                },
            }">
                {{-- Search + Filter toolbar. Search matches instantly,
                     client-side (Alpine, below); Status/Department/Checklist
                     Title are applied server-side on Apply — same split as
                     `pages/offboardees/index.blade.php`'s own search+filter
                     bar, and the reason `data-turbo-submits-with` below is
                     what gives that server round trip a loading indicator. --}}
                <div class="mb-6 flex flex-wrap items-center gap-3">
                    <div class="relative max-w-sm flex-1">
                        <input type="text" x-model="search" placeholder="Search by name or employee number..."
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
                            @if ($statusFilter || $departmentFilter || $checklistFilter)
                                <span class="flex h-4 w-4 items-center justify-center rounded-full bg-[#145a3a] text-[10px] font-semibold text-white dark:bg-[#3aa876]">
                                    {{ collect([$statusFilter, $departmentFilter, $checklistFilter])->filter()->count() }}
                                </span>
                            @endif
                        </button>

                        <div x-show="open" x-cloak x-transition
                            class="shadow-theme-lg absolute right-0 z-40 mt-2 w-72 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                            <form method="GET" action="{{ route('approvals.index') }}" class="space-y-4">
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                        Status
                                    </label>
                                    <div class="relative">
                                        <select name="status"
                                            class="dark:bg-dark-900 h-10 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-3 pr-9 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800">
                                            <option value="">All Statuses</option>
                                            @foreach ($statusFilterLabels as $value => $label)
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

                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                        Checklist Title
                                    </label>
                                    <div class="relative">
                                        <select name="checklist"
                                            class="dark:bg-dark-900 h-10 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-3 pr-9 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800">
                                            <option value="">All Checklists</option>
                                            @foreach ($checklistTitles as $checklistTitle)
                                                <option value="{{ $checklistTitle }}" @selected($checklistFilter === $checklistTitle)>{{ $checklistTitle }}</option>
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
                                    <a href="{{ route('approvals.index') }}"
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
                        No offboarding requests match "<span x-text="search"></span>".
                    </p>
                </div>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($approvals as $approval)
                    @php
                        $badge = $statusBadges[$approval['displayStatus']] ?? $statusBadges['pending'];
                    @endphp
                    <div x-show="search.trim() === '' || @js($searchHaystacks[$loop->index]).includes(search.trim().toLowerCase())"
                        @click="$dispatch('open-offboardee-modal', @js($approval))"
                        data-card-id="{{ $approval['id'] }}"
                        class="group cursor-pointer rounded-2xl border border-gray-200 bg-white p-5 transition-all duration-200 hover:-translate-y-1 hover:border-[#145a3a]/40 hover:shadow-lg dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-[#3aa876]/40">
                        <div class="flex items-start justify-between">
                            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-base font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                {{ collect(explode(' ', $approval['name']))->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}
                            </div>
                            <span data-status-badge class="rounded-full px-2.5 py-1 text-xs font-medium {{ $badge['class'] }}">
                                {{ $badge['label'] }}
                            </span>
                        </div>

                        <h4 class="mt-4 text-base font-semibold text-gray-800 group-hover:text-[#145a3a] dark:text-white/90 dark:group-hover:text-[#3aa876]">
                            {{ $approval['name'] }}
                        </h4>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $approval['designation'] }}</p>
                        @if (!empty($approval['checklistTemplates']))
                            <p class="mt-2 text-sm font-medium text-[#145a3a] dark:text-[#3aa876]">
                                {{ implode(', ', $approval['checklistTemplates']) }}
                            </p>
                        @elseif (($approval['kind'] ?? null) === 'general_signatory')
                            <p class="mt-2 text-sm font-medium text-[#145a3a] dark:text-[#3aa876]">
                                General Signatory Clearance
                            </p>
                        @endif

                        @if ($approval['isMonitoring'] ?? false)
                            <p class="mt-1 text-xs font-medium text-[#145a3a] dark:text-[#3aa876]">
                                Monitoring — Task Assignee's Checklist
                            </p>
                        @elseif (!$approval['isPrimaryApprover'])
                            <p class="mt-1 text-xs text-gray-400">
                                Assigned by: {{ $approval['assignedByName'] }}
                            </p>
                        @endif

                        <div class="mt-4 space-y-1.5 border-t border-gray-100 pt-4 dark:border-gray-800">
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-gray-400">Department</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $approval['department'] }}</span>
                            </div>
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-gray-400">Reason</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $approval['reason'] }}</span>
                            </div>
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-gray-400">Last Working Day</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $approval['lastWorkingDay'] }}</span>
                            </div>
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-gray-400">Approval Mode</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $approval['approvalMode'] }}</span>
                            </div>
                            @if ($approval['dueAt'])
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-gray-400">Checklist Due</span>
                                    <span class="font-medium {{ $approval['isOverdue'] ? 'text-error-600 dark:text-error-400' : 'text-gray-700 dark:text-gray-300' }}">{{ $approval['dueAt'] }}</span>
                                </div>
                            @endif
                            {{-- Clearance Signing Due Date is the Clearance Signatory's/General
                                 Signatory's OWN deadline — shown only to whoever that actually
                                 is (`isPrimaryApprover`), never to a delegate, a monitoring
                                 Group/Immediate/Department Head, or a plain per-item Task
                                 Assignee's own card. --}}
                            @if (!empty($approval['isPrimaryApprover']) && !empty($approval['clearanceSigningDueAt']))
                                <div class="flex items-center justify-between"
                                    :class="{
                                        'text-xs': clearanceSigningUrgency(@js($approval['clearanceSigningDueAtIso'] ?? null)) === 'none',
                                        'text-sm font-semibold text-orange-600 dark:text-orange-400': clearanceSigningUrgency(@js($approval['clearanceSigningDueAtIso'] ?? null)) === 'warning',
                                        'text-base font-bold text-error-600 dark:text-error-400': clearanceSigningUrgency(@js($approval['clearanceSigningDueAtIso'] ?? null)) === 'danger',
                                    }">
                                    <span :class="clearanceSigningUrgency(@js($approval['clearanceSigningDueAtIso'] ?? null)) === 'none' ? 'text-gray-400' : ''">Clearance Signing Due</span>
                                    <span :class="clearanceSigningUrgency(@js($approval['clearanceSigningDueAtIso'] ?? null)) === 'none' ? 'font-medium text-gray-700 dark:text-gray-300' : ''">{{ $approval['clearanceSigningDueAt'] }}</span>
                                </div>
                            @endif
                            @if (!empty($approval['delegations']))
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-gray-400">Assigned To</span>
                                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ collect($approval['delegations'])->pluck('delegatedEmployeeName')->unique()->implode(', ') }}</span>
                                </div>
                            @endif
                        </div>

                        <div class="mt-4 flex items-center gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                            @if (($approval['kind'] ?? null) === 'general_signatory')
                                {{-- Merges in any client-side patch left by
                                     general-signatory-modal.blade.php's own
                                     rememberCardOverride() (declined-then-
                                     reopened this session) — see that
                                     method's own docblock for why the plain
                                     @js($approval) snapshot below, baked in
                                     at this page's render time, would
                                     otherwise show stale pre-decline data
                                     on every later reopen. --}}
                                <button type="button" @click.stop="$dispatch('open-general-signatory-modal', Object.assign({}, @js($approval), window.__approvalCardOverrides?.['{{ $approval['id'] }}'] || {}))"
                                    class="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-[#145a3a] px-3 py-2 text-sm font-medium text-white hover:bg-[#0f4630]">
                                    <svg width="16" height="16" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" clip-rule="evenodd" d="M3 4.5C3 4.08579 3.33579 3.75 3.75 3.75H14.25C14.6642 3.75 15 4.08579 15 4.5C15 4.91421 14.6642 5.25 14.25 5.25H3.75C3.33579 5.25 3 4.91421 3 4.5ZM3 9C3 8.58579 3.33579 8.25 3.75 8.25H14.25C14.6642 8.25 15 8.58579 15 9C15 9.41421 14.6642 9.75 14.25 9.75H3.75C3.33579 9.75 3 9.41421 3 9ZM3.75 12.75C3.33579 12.75 3 13.0858 3 13.5C3 13.9142 3.33579 14.25 3.75 14.25H10.5C10.9142 14.25 11.25 13.9142 11.25 13.5C11.25 13.0858 10.9142 12.75 10.5 12.75H3.75Z" fill="currentColor" />
                                    </svg>
                                    Review & Approve
                                </button>
                            @elseif (($approval['kind'] ?? null) === 'final_approval')
                                <button type="button" @click.stop="$dispatch('open-final-approval-modal', @js($approval))"
                                    class="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-[#145a3a] px-3 py-2 text-sm font-medium text-white hover:bg-[#0f4630]">
                                    <svg width="16" height="16" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" clip-rule="evenodd" d="M3 4.5C3 4.08579 3.33579 3.75 3.75 3.75H14.25C14.6642 3.75 15 4.08579 15 4.5C15 4.91421 14.6642 5.25 14.25 5.25H3.75C3.33579 5.25 3 4.91421 3 4.5ZM3 9C3 8.58579 3.33579 8.25 3.75 8.25H14.25C14.6642 8.25 15 8.58579 15 9C15 9.41421 14.6642 9.75 14.25 9.75H3.75C3.33579 9.75 3 9.41421 3 9ZM3.75 12.75C3.33579 12.75 3 13.0858 3 13.5C3 13.9142 3.33579 14.25 3.75 14.25H10.5C10.9142 14.25 11.25 13.9142 11.25 13.5C11.25 13.0858 10.9142 12.75 10.5 12.75H3.75Z" fill="currentColor" />
                                    </svg>
                                    Review & Approve
                                </button>
                            @else
                                {{-- Merges in any client-side patch left by
                                     checklist-modal.blade.php's own
                                     rememberCardOverride() — see that
                                     method's own docblock. --}}
                                <button type="button" @click.stop="$dispatch('open-checklist-modal', Object.assign({}, @js($approval), window.__approvalCardOverrides?.['{{ $approval['id'] }}'] || {}))"
                                    class="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-[#145a3a] px-3 py-2 text-sm font-medium text-white hover:bg-[#0f4630]">
                                    <svg width="16" height="16" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" clip-rule="evenodd" d="M3 4.5C3 4.08579 3.33579 3.75 3.75 3.75H14.25C14.6642 3.75 15 4.08579 15 4.5C15 4.91421 14.6642 5.25 14.25 5.25H3.75C3.33579 5.25 3 4.91421 3 4.5ZM3 9C3 8.58579 3.33579 8.25 3.75 8.25H14.25C14.6642 8.25 15 8.58579 15 9C15 9.41421 14.6642 9.75 14.25 9.75H3.75C3.33579 9.75 3 9.41421 3 9ZM3.75 12.75C3.33579 12.75 3 13.0858 3 13.5C3 13.9142 3.33579 14.25 3.75 14.25H10.5C10.9142 14.25 11.25 13.9142 11.25 13.5C11.25 13.0858 10.9142 12.75 10.5 12.75H3.75Z" fill="currentColor" />
                                    </svg>
                                    View Checklist
                                </button>
                                {{-- Hidden once this card combines more than one checklist —
                                     bulk "Assign Checklist" below takes over for that case,
                                     since whole-card delegation to a single person no longer
                                     makes sense once several distinct checklists are involved.
                                     Also hidden for a headless "Use Task Assignee as Clearance
                                     Signatory" card (`assignUrl` null) — there's no owner to
                                     delegate FROM on a checklist that's designed to have none. --}}
                                @if ($approval['isPrimaryApprover'] && count($approval['checklistTemplates']) <= 1 && $approval['assignUrl'])
                                    <button type="button" title="Assign To" @click.stop="$dispatch('open-assign-modal', @js($approval))"
                                        class="flex items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M10 10a3.75 3.75 0 100-7.5 3.75 3.75 0 000 7.5ZM3.5 17.25a6.5 6.5 0 0113 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                            <path d="M16.25 6.25v4M18.25 8.25h-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </button>
                                @endif
                                {{-- Bulk "Assign Checklist": only shown when this card actually
                                     combines more than one checklist template (e.g. the same
                                     person is both a Clearance Signatory and the Immediate Head)
                                     — a single-checklist card has nothing else to bulk-open. --}}
                                @if ($approval['showAssignChecklistPool'])
                                    <button type="button" title="Assign Checklist" @click.stop="$dispatch('open-assign-checklist-pool-modal', @js($approval))"
                                        class="flex items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M8.75 4.167h-2.5A1.667 1.667 0 0 0 4.583 5.833v9.167A1.667 1.667 0 0 0 6.25 16.667h7.5a1.667 1.667 0 0 0 1.667-1.667V5.833a1.667 1.667 0 0 0-1.667-1.666h-2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                            <path d="M8.75 2.5h2.5a.833.833 0 0 1 .833.833V4.167a.833.833 0 0 1-.833.833h-2.5a.833.833 0 0 1-.833-.833V3.333A.833.833 0 0 1 8.75 2.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                            <path d="M7.5 10.833l1.667 1.667L12.5 9.167" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </button>
                                @endif
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            </div>
        @endif

        <!-- Approval Status Modal — non-admin approvers reviewing an
             offboardee from this page only ever see the Timeline tab; the
             Offboarding Status tab (per-checklist approval cards) stays
             hidden here for them specifically. Admins keep both tabs, same
             as every other page that renders this component. -->
        <x-offboarding.status-timeline-modal :hide-status-tab="! (auth()->user()?->isAdmin() ?? false)" />

        <!-- Approval Checklist Modal -->
        <x-approvals.checklist-modal />

        <!-- General Signatory Approval Modal -->
        <x-approvals.general-signatory-modal />

        <!-- Final Approval Modal -->
        <x-approvals.final-approval-modal />

        <!-- Assign To Modal (whole checklist delegation) -->
        <x-approvals.assign-modal :employees="$employees" />

        <!-- Assign Checklist Item Modal (Department Head reassigns a single item) -->
        <x-approvals.assign-item-modal :employees="$employees" />

        <!-- Bulk Assign Checklist Modal (opens a shared claimable pool on one or more checklists) -->
        <x-approvals.assign-checklist-pool-modal />
    </div>
@endsection
