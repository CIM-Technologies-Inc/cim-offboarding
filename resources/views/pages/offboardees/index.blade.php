@extends('layouts.app')

@section('content')
    {{-- Card View card surface. Scoped here because the app's Tailwind
         bundle is prebuilt. A defined green-tinted border + soft shadow so
         each card stands out from the page in Light Mode, a gentle lift with
         a brand-green border on hover, and matching Dark Mode values. --}}
    <style>
        .offboardee-card {
            border-radius: 1rem;
            background-color: #ffffff;
            border: 1px solid #d3ded8;
            box-shadow: 0 1px 2px rgba(16, 24, 40, .06), 0 3px 10px -4px rgba(20, 90, 58, .12);
            transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
        }
        .offboardee-card:hover {
            transform: translateY(-2px);
            border-color: rgba(20, 90, 58, .45);
            box-shadow: 0 2px 4px rgba(16, 24, 40, .05), 0 12px 24px -12px rgba(20, 90, 58, .35);
        }
        .offboardee-card:focus-visible { outline: 2px solid #3aa876; outline-offset: 2px; }

        .dark .offboardee-card {
            background-color: rgba(255, 255, 255, .03);
            border-color: rgba(255, 255, 255, .10);
            box-shadow: 0 1px 2px rgba(0, 0, 0, .45), 0 4px 12px -6px rgba(0, 0, 0, .6);
        }
        .dark .offboardee-card:hover {
            border-color: rgba(58, 168, 118, .50);
            box-shadow: 0 12px 26px -12px rgba(0, 0, 0, .8), 0 0 0 1px rgba(58, 168, 118, .12);
        }

        /* Offboardee initials: dark-green circle with white initials,
           framed by a full round border (white inner edge + green ring). */
        .offboardee-avatar {
            color: #ffffff;
            letter-spacing: .03em;
            background: linear-gradient(145deg, #23885a 0%, #145a3a 60%, #0f4630 100%);
            border: 2px solid #ffffff;
            box-shadow: 0 0 0 2px #145a3a, 0 4px 10px -3px rgba(20, 90, 58, .45);
            text-shadow: 0 1px 1px rgba(0, 0, 0, .25);
            transition: box-shadow .2s ease;
        }
        .group:hover .offboardee-avatar { box-shadow: 0 0 0 2px #3aa876, 0 6px 14px -4px rgba(20, 90, 58, .55); }
        .dark .offboardee-avatar {
            background: linear-gradient(145deg, #2f9e6b 0%, #1a6e48 60%, #145a3a 100%);
            border-color: #1a2231;
            box-shadow: 0 0 0 2px #3aa876, 0 4px 12px -3px rgba(0, 0, 0, .7);
        }
        .dark .group:hover .offboardee-avatar { box-shadow: 0 0 0 2px #5cc496, 0 6px 16px -4px rgba(58, 168, 118, .35); }

        @media (prefers-reduced-motion: reduce) {
            .offboardee-card, .offboardee-avatar { transition: none; }
            .offboardee-card:hover { transform: none; }
        }
    </style>

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
        },
        // Card View / List View — `viewMode` is the user's own explicit
        // choice, persisted per `sessionStorage` (mirrors the `theme`
        // store's localStorage pattern in
        // resources/views/partials/theme-init.blade.php, but scoped to
        // this session/navigation rather than kept indefinitely).
        // `totalCount` is this page's already-filtered (status/department)
        // offboardee count — forcing List View at 20+ is a pure DISPLAY
        // override via `effectiveView()`: it never calls `setViewMode()`,
        // so a remembered preference survives a temporary excursion above
        // the threshold and resumes the instant the count drops back
        // below it, instead of being permanently reset to List.
        viewMode: (() => {
            try {
                return sessionStorage.getItem('offboardeesViewMode') || 'card';
            } catch (e) {
                return 'card';
            }
        })(),
        totalCount: {{ $offboardees->count() }},
        isForcedListView() {
            return this.totalCount >= 20;
        },
        effectiveView() {
            return this.isForcedListView() ? 'list' : this.viewMode;
        },
        setViewMode(mode) {
            this.viewMode = mode;
            try {
                sessionStorage.setItem('offboardeesViewMode', mode);
            } catch (e) {
                // ignore — view just won't persist across navigations
            }
        }
    }">
        @php
            $statusLabelsForFilter = [
                'pending' => 'Pending',
                'in_progress' => 'In Progress',
                'overdue' => 'Overdue',
                'completed' => 'Completed',
                'cancelled' => 'Retracted',
            ];
        @endphp

        @if ($statusFilter || $departmentFilter)
            <div class="mb-6 flex flex-wrap items-center gap-2">
                <span class="text-sm text-gray-500 dark:text-gray-400">Filtered by:</span>
                @if ($statusFilter)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[#145a3a]/10 px-3 py-1 text-xs font-medium text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]">
                        {{ $statusLabelsForFilter[$statusFilter] ?? ucfirst($statusFilter) }}
                        <a href="{{ route('offboardees.index', ['department' => $departmentFilter, 'sort' => $sortFilter]) }}" class="hover:text-error-500">
                            <svg width="12" height="12" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M13.5 4.5L4.5 13.5M4.5 4.5L13.5 13.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </a>
                    </span>
                @endif
                @if ($departmentFilter)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[#145a3a]/10 px-3 py-1 text-xs font-medium text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]">
                        {{ $departmentFilter }}
                        <a href="{{ route('offboardees.index', ['status' => $statusFilter, 'sort' => $sortFilter]) }}" class="hover:text-error-500">
                            <svg width="12" height="12" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M13.5 4.5L4.5 13.5M4.5 4.5L13.5 13.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </a>
                    </span>
                @endif
                <a href="{{ route('offboardees.index', ['sort' => $sortFilter]) }}" class="text-xs font-medium text-gray-400 hover:text-error-500 underline">
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

                <!-- Sort By Button + Dropdown -->
                <div x-data="{ open: false }" class="relative" @click.away="open = false">
                    <button type="button" @click="open = !open"
                        class="shadow-theme-xs flex h-11 items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                        <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M4.5 4.5V13.5M4.5 13.5L2.25 11.25M4.5 13.5L6.75 11.25M13.5 13.5V4.5M13.5 4.5L11.25 6.75M13.5 4.5L15.75 6.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        Sort By
                    </button>

                    <div x-show="open" x-cloak x-transition
                        class="shadow-theme-lg absolute right-0 z-40 mt-2 w-64 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                        {{-- Created Date/Time only — never name/Last Working Day/any
                             other field (see OffboardeeController::index()'s own
                             `$sortFilter`). Preserves the current Status/Department
                             filters via the two hidden fields below, same reasoning
                             as the Filter form's own hidden `sort` field. --}}
                        <form method="GET" action="{{ route('offboardees.index') }}" class="space-y-4">
                            <input type="hidden" name="status" value="{{ $statusFilter }}" />
                            <input type="hidden" name="department" value="{{ $departmentFilter }}" />
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Created Date/Time
                                </label>
                                <div class="relative">
                                    {{-- `requestSubmit()`, not `submit()` — the native `.submit()`
                                         method does NOT dispatch a real `submit` event (per the DOM
                                         spec), which is exactly what Turbo Drive listens for to
                                         intercept navigation and avoid a full page reload. Without
                                         this, changing the sort silently falls back to a genuine
                                         full-page load instead of Turbo's fast in-page transition —
                                         `requestSubmit()` fires a real event, so Turbo picks it up
                                         the same way it already does for the Filter form's own
                                         (real button click) submit. --}}
                                    <select name="sort" onchange="this.form.requestSubmit()"
                                        class="dark:bg-dark-900 h-10 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-3 pr-9 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800">
                                        <option value="newest" @selected($sortFilter === 'newest')>Newest Created</option>
                                        <option value="oldest" @selected($sortFilter === 'oldest')>Oldest Created</option>
                                    </select>
                                    <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-gray-500 dark:text-gray-400">
                                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M4.79175 7.396L10.0001 12.6043L15.2084 7.396" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </span>
                                </div>
                            </div>
                            <noscript>
                                <button type="submit" class="w-full rounded-lg bg-[#145a3a] px-3 py-2 text-sm font-medium text-white hover:bg-[#0f4630]">
                                    Apply
                                </button>
                            </noscript>
                        </form>
                    </div>
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
                            {{-- Preserves the current Sort By selection when applying a
                                 Status/Department filter — this form only has its own two
                                 fields, so without this the sort would silently reset to
                                 the default the moment a filter is applied. --}}
                            <input type="hidden" name="sort" value="{{ $sortFilter }}" />
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

                <!-- Card View / List View toggle — hidden entirely once the
                     20-request threshold forces List View, since there's no
                     real choice to offer at that point (see the page-level
                     `isForcedListView()`/`effectiveView()` above). -->
                <div class="flex items-center gap-1 rounded-lg border border-gray-300 bg-white p-1 dark:border-gray-700 dark:bg-gray-900"
                    x-show="!isForcedListView()" x-cloak>
                    <button type="button" @click="setViewMode('card')"
                        :class="viewMode === 'card' ? 'bg-[#145a3a] text-white' : 'text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/[0.03]'"
                        class="flex h-9 items-center gap-1.5 rounded-md px-3 text-sm font-medium transition-colors"
                        :aria-pressed="viewMode === 'card'" title="Card View">
                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="2.5" y="2.5" width="6.5" height="6.5" rx="1.25" stroke="currentColor" stroke-width="1.5" />
                            <rect x="11" y="2.5" width="6.5" height="6.5" rx="1.25" stroke="currentColor" stroke-width="1.5" />
                            <rect x="2.5" y="11" width="6.5" height="6.5" rx="1.25" stroke="currentColor" stroke-width="1.5" />
                            <rect x="11" y="11" width="6.5" height="6.5" rx="1.25" stroke="currentColor" stroke-width="1.5" />
                        </svg>
                        <span class="hidden sm:inline">Card</span>
                    </button>
                    <button type="button" @click="setViewMode('list')"
                        :class="viewMode === 'list' ? 'bg-[#145a3a] text-white' : 'text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/[0.03]'"
                        class="flex h-9 items-center gap-1.5 rounded-md px-3 text-sm font-medium transition-colors"
                        :aria-pressed="viewMode === 'list'" title="List View">
                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M3.5 5.5h13M3.5 10h13M3.5 14.5h13" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                        </svg>
                        <span class="hidden sm:inline">List</span>
                    </button>
                </div>
                <span x-show="isForcedListView()" x-cloak
                    class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-500 dark:bg-white/[0.03] dark:text-gray-400">
                    List View (automatic for 20+ requests)
                </span>
            </div>

            <div x-show="!hasMatches()" class="rounded-2xl border border-gray-200 bg-white p-10 text-center dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No employees match "<span x-text="search"></span>".
                </p>
            </div>

            <div :class="effectiveView() === 'card'
                    ? 'grid grid-cols-1 gap-5 sm:grid-cols-2 md:gap-6 lg:grid-cols-3 xl:grid-cols-4'
                    : 'flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]'">
                @foreach ($offboardees as $offboardee)
                    @php
                        // 'cancelled' IS shown here now — a retracted request
                        // is kept permanently as a read-only historical card
                        // instead of disappearing (see
                        // OffboardeeController::index()'s additive retracted-
                        // requests query). User-facing label is "Retracted";
                        // the stored status value stays 'cancelled' (see
                        // OffboardingRequest::isReadOnly()'s own docblock).
                        $statusStyles = [
                            'pending' => 'bg-yellow-50 text-yellow-700 dark:bg-yellow-500/15 dark:text-yellow-400',
                            'in_progress' => 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400',
                            'overdue' => 'bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400',
                            'completed' => 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400',
                            'cancelled' => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
                        ];
                        $statusLabels = [
                            'pending' => 'Pending',
                            'in_progress' => 'In Progress',
                            'overdue' => 'Overdue',
                            'completed' => 'Completed',
                            'cancelled' => 'Retracted',
                        ];
                    @endphp
                    <div x-data="{
                            cancelling: false,
                            extending: false,
                            // The status badge's own reactive copy — see
                            // below. `statusStyles`/`statusLabels` come
                            // straight from this same page's own PHP arrays
                            // (single source of truth), so a successful
                            // extension can update the badge without ever
                            // duplicating that class/label mapping in JS.
                            status: @js($offboardee['status']),
                            statusStyles: @js($statusStyles),
                            statusLabels: @js($statusLabels),
                            // Reactive copies of the server-rendered Extend Due
                            // fields — initialized from the same page-load data
                            // as everything else on this card, but updated in
                            // place (not via a page reload) after a successful
                            // extension, so both the visible Last Working Day
                            // text and a SUBSEQUENT click of Extend Due (which
                            // re-reads these, not the original server-rendered
                            // literals) reflect the new state immediately.
                            lastWorkingDay: @js($offboardee['lastWorkingDay'] ?? '—'),
                            canBulkExtendDue: @js($offboardee['canBulkExtendDue']),
                            // Same reactive-copy convention as canBulkExtendDue
                            // above — a successful extension can push the Last
                            // Working Day back into the future, which must
                            // reopen Retract Offboarding immediately, with no
                            // page reload. See x-show on that button below.
                            canRetractOffboarding: @js($offboardee['canRetractOffboarding']),
                            // Retraction history — read-only, frozen the moment
                            // a request is retracted (see
                            // OffboardingRequestController::cancel()'s own
                            // docblock: cancelled_by/cancellation_reason are
                            // never updated again afterward). Reactive copies,
                            // same convention as every other field here, so a
                            // retraction performed on THIS page shows the real
                            // actor/reason immediately below, with no reload.
                            cancelledByName: @js($offboardee['cancelledByName']),
                            cancellationReason: @js($offboardee['cancellationReason']),
                            cancelledAt: @js($offboardee['cancelledAt']),
                            extendDueChecklists: @js($offboardee['extendDueChecklists'] ?? []),
                            extendDueMinSelectableDateIso: @js($offboardee['extendDueMinSelectableDateIso'] ?? null),
                            // Original vs. latest-extended Last Working Day —
                            // same reactive-copy convention as the fields
                            // above, kept in sync after a successful
                            // extension so the Status modal (opened via the
                            // dispatch below) shows the current state
                            // without a page reload.
                            originalLastWorkingDay: @js($offboardee['originalLastWorkingDay'] ?? '—'),
                            isLastWorkingDayExtended: @js($offboardee['isLastWorkingDayExtended'] ?? false),
                            // The Offboarding Status/Timeline modal's own
                            // tab content (every checklist's own due date,
                            // and the activity list itself) is driven
                            // entirely by this one field — see the
                            // Extend Due handler below, which is the only
                            // place that overwrites it after page load.
                            // Without a reactive copy here, the dispatch
                            // below would keep spreading the ORIGINAL
                            // page-load `@js($offboardee)` timeline forever,
                            // showing every checklist's PRE-extension due
                            // date and missing this action's own new
                            // activity entries until a real page reload.
                            timeline: @js($offboardee['timeline'] ?? []),
                        }"
                        data-offboardee-card="{{ $offboardee['id'] }}"
                        @click="if (!cancelling && !extending) $dispatch('open-offboardee-modal', { ...@js($offboardee), status, lastWorkingDay, originalLastWorkingDay, isLastWorkingDayExtended, timeline })"
                        x-show="search.trim() === '' || @js(Str::lower($offboardee['name'])).includes(search.trim().toLowerCase())"
                        :class="effectiveView() === 'card'
                            ? 'offboardee-card group cursor-pointer p-5'
                            : 'group cursor-pointer border-b border-gray-100 px-4 py-3 transition-colors last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]'">
                    {{-- ============================= CARD VIEW ============================= --}}
                    <template x-if="effectiveView() === 'card'">
                        <div>
                        <div class="flex items-start justify-between">
                            <div class="offboardee-avatar flex h-12 w-12 shrink-0 items-center justify-center rounded-full text-base font-semibold">
                                {{ collect(explode(' ', $offboardee['name']))->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}
                            </div>
                            <span class="rounded-full px-2.5 py-1 text-xs font-medium"
                                :class="statusStyles[status] || statusStyles.pending"
                                x-text="statusLabels[status] || (status.charAt(0).toUpperCase() + status.slice(1))">
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
                                <span class="font-medium text-gray-700 dark:text-gray-300" x-text="lastWorkingDay"></span>
                            </div>
                        </div>

                        @include('pages.offboardees.partials.retraction-history', ['compact' => false])

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

                        @include('pages.offboardees.partials.retract-button', ['offboardee' => $offboardee, 'compact' => false])

                        @include('pages.offboardees.partials.extend-due-button', ['offboardee' => $offboardee, 'compact' => false])

                        @include('pages.offboardees.partials.final-approval-button', ['offboardee' => $offboardee, 'compact' => false])
                        </div>
                    </template>

                    {{-- ============================= LIST VIEW ============================= --}}
                    <template x-if="effectiveView() === 'list'">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-6">
                            <div class="flex min-w-0 items-center gap-3 sm:w-64 sm:shrink-0">
                                <div class="offboardee-avatar flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold">
                                    {{ collect(explode(' ', $offboardee['name']))->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-gray-800 group-hover:text-[#145a3a] dark:text-white/90 dark:group-hover:text-[#3aa876]">{{ $offboardee['name'] }}</p>
                                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $offboardee['designation'] }} &middot; {{ $offboardee['employeeCode'] ?? '—' }}</p>
                                    @include('pages.offboardees.partials.retraction-history', ['compact' => true])
                                </div>
                            </div>

                            <div class="sm:w-56 sm:shrink-0">
                                <p class="text-sm text-gray-500 dark:text-gray-400">Department: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $offboardee['department'] }}</span></p>
                            </div>

                            <div class="sm:w-52 sm:shrink-0">
                                <p class="text-sm text-gray-500 dark:text-gray-400">Last Working Day: <span class="font-medium text-gray-700 dark:text-gray-300" x-text="lastWorkingDay"></span></p>
                            </div>

                            <div class="sm:w-28 sm:shrink-0">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium"
                                    :class="statusStyles[status] || statusStyles.pending"
                                    x-text="statusLabels[status] || (status.charAt(0).toUpperCase() + status.slice(1))">
                                </span>
                            </div>

                            <div class="flex flex-1 flex-wrap items-center gap-2 sm:justify-end" @click.stop="">
                                @include('pages.offboardees.partials.retract-button', ['offboardee' => $offboardee, 'compact' => true])
                                @include('pages.offboardees.partials.extend-due-button', ['offboardee' => $offboardee, 'compact' => true])
                                @include('pages.offboardees.partials.final-approval-button', ['offboardee' => $offboardee, 'compact' => true])
                            </div>
                        </div>
                    </template>
                    </div>
                @endforeach
            </div>
        @endif

        <!-- Offboardee Status Modal -->
        <x-offboarding.status-timeline-modal :initial="$deepLinkOffboardee" :email-templates="$activeEmailTemplates" />

        <!-- Complete Offboarding (on behalf of the Final Approver) Modal -->
        <x-offboarding.complete-final-approval-modal />
    </div>
@endsection
