{{-- Dashboard Calendar card — an offboarding schedule by Last Working Day.
     Data comes live from `DashboardController::calendar()` (existing
     offboarding request records only), one month at a time. A month grid
     marks dates with scheduled LWDs (dot, plus a count when more than one
     offboardee leaves that day); clicking a date lists that day's
     offboardees below in the agenda style of the reference design. Styles
     are scoped here because the app's Tailwind bundle is prebuilt. --}}
@once
    <style>
        .lwd-cal { display: flex; flex-direction: column; height: 100%; }
        .lwd-cal__grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: .25rem; }
        .lwd-cal__weekday { text-align: center; font-size: .7rem; font-weight: 600; letter-spacing: .04em; color: #98a2b3; text-transform: uppercase; padding-bottom: .25rem; }
        .lwd-cal__day {
            position: relative;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            height: 2.6rem; border-radius: .75rem;
            font-size: .85rem; font-weight: 500; color: #344054;
            border: 1px solid transparent;
            transition: background-color .15s ease, color .15s ease, border-color .15s ease;
        }
        .lwd-cal__day:hover { background: rgba(20, 90, 58, .07); }
        .lwd-cal__day.is-outside { color: #c0c6cf; }
        .lwd-cal__day.has-events { font-weight: 700; color: #145a3a; }
        .lwd-cal__day.is-today { border-color: #145a3a; color: #145a3a; font-weight: 700; }
        .lwd-cal__day.is-selected { background: linear-gradient(145deg, #23885a, #145a3a); color: #fff; border-color: transparent; box-shadow: 0 6px 14px -6px rgba(20, 90, 58, .6); }
        .lwd-cal__dot { position: absolute; bottom: .3rem; width: .3rem; height: .3rem; border-radius: 9999px; background: #145a3a; }
        .lwd-cal__dot.is-completed { background: #98a2b3; }
        .lwd-cal__day.is-selected .lwd-cal__dot { background: #fff; }
        .lwd-cal__count {
            position: absolute; top: -.3rem; right: -.15rem;
            min-width: 1.05rem; height: 1.05rem; padding: 0 .25rem;
            border-radius: 9999px; background: #145a3a; color: #fff;
            font-size: .62rem; font-weight: 700; line-height: 1.05rem; text-align: center;
            box-shadow: 0 0 0 2px #fff;
        }
        .lwd-cal__day.is-selected .lwd-cal__count { background: #fff; color: #145a3a; box-shadow: 0 0 0 2px #145a3a; }

        /* Agenda scrolls inside the card on busy days instead of stretching the dashboard row. */
        .lwd-cal__agenda { position: relative; max-height: 19rem; overflow-y: auto; padding-right: .25rem; }
        .lwd-cal__row { display: grid; grid-template-columns: 3.6rem minmax(0, 1fr); gap: .75rem; align-items: start; padding: .55rem 0; border-top: 1px dashed #e4e7ec; }
        .lwd-cal__row:first-child { border-top: 0; }
        .lwd-cal__time { font-size: .75rem; color: #98a2b3; padding-top: .55rem; line-height: 1.1; }
        .lwd-cal__time strong { display: block; font-size: 1rem; color: #344054; }
        .lwd-cal__event {
            border-radius: 1rem; padding: .75rem .9rem;
            background: rgba(20, 90, 58, .07);
            border: 1px solid rgba(20, 90, 58, .14);
            box-shadow: 0 2px 6px -3px rgba(16, 24, 40, .08);
        }
        .lwd-cal__event + .lwd-cal__event { margin-top: .5rem; }
        .lwd-cal__event.is-overdue { background: #fef6e7; border-color: #fbe0a8; }
        .lwd-cal__event.is-completed { background: #f2f4f7; border-color: #e4e7ec; }
        .lwd-cal__avatar {
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
            width: 1.9rem; height: 1.9rem; border-radius: 9999px;
            font-size: .68rem; font-weight: 700; color: #fff; letter-spacing: .02em;
            background: linear-gradient(145deg, #23885a, #145a3a 60%, #0f4630);
            border: 2px solid #fff; box-shadow: 0 0 0 1.5px #145a3a;
        }
        .lwd-cal__event.is-completed .lwd-cal__avatar { background: #98a2b3; box-shadow: 0 0 0 1.5px #98a2b3; }
        .lwd-cal__badge { border-radius: 9999px; padding: .1rem .5rem; font-size: .65rem; font-weight: 600; white-space: nowrap; }
        .lwd-cal__badge.is-pending { background: #fef7c3; color: #a15c07; }
        .lwd-cal__badge.is-in_progress { background: rgba(20, 90, 58, .12); color: #145a3a; }
        .lwd-cal__badge.is-overdue { background: #fdead7; color: #c4320a; }
        .lwd-cal__badge.is-completed { background: #e4e7ec; color: #475467; }

        .dark .lwd-cal__weekday { color: #667085; }
        .dark .lwd-cal__day { color: #d0d5dd; }
        .dark .lwd-cal__day:hover { background: rgba(58, 168, 118, .12); }
        .dark .lwd-cal__day.is-outside { color: #475467; }
        .dark .lwd-cal__day.has-events { color: #3aa876; }
        .dark .lwd-cal__day.is-today { border-color: #3aa876; color: #3aa876; }
        .dark .lwd-cal__day.is-selected { background: linear-gradient(145deg, #2f9e6b, #145a3a); color: #fff; }
        .dark .lwd-cal__dot { background: #3aa876; }
        .dark .lwd-cal__count { background: #3aa876; color: #0c111d; box-shadow: 0 0 0 2px #1a2231; }
        .dark .lwd-cal__row { border-top-color: rgba(255, 255, 255, .08); }
        .dark .lwd-cal__time strong { color: #e4e7ec; }
        .dark .lwd-cal__event { background: rgba(58, 168, 118, .10); border-color: rgba(58, 168, 118, .22); box-shadow: none; }
        .dark .lwd-cal__event.is-overdue { background: rgba(247, 144, 9, .10); border-color: rgba(247, 144, 9, .25); }
        .dark .lwd-cal__event.is-completed { background: rgba(255, 255, 255, .04); border-color: rgba(255, 255, 255, .08); }
        .dark .lwd-cal__avatar { border-color: #1a2231; box-shadow: 0 0 0 1.5px #3aa876; }
        .dark .lwd-cal__badge.is-pending { background: rgba(250, 204, 21, .15); color: #fde272; }
        .dark .lwd-cal__badge.is-in_progress { background: rgba(58, 168, 118, .18); color: #3aa876; }
        .dark .lwd-cal__badge.is-overdue { background: rgba(247, 144, 9, .15); color: #fdb022; }
        .dark .lwd-cal__badge.is-completed { background: rgba(255, 255, 255, .08); color: #98a2b3; }
    </style>
    <script>
        window.offboardingCalendar = function (url) {
            const pad = (n) => String(n).padStart(2, '0');
            const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
            const parse = (s) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); };

            return {
                month: null,
                monthLabel: '',
                today: null,
                selected: null,
                gridStart: null,
                days: {},
                upcoming: [],
                loading: true,
                error: '',
                showMonth: true,
                init() { this.load(); },
                load(month = null) {
                    this.loading = true;
                    this.error = '';
                    const target = url + (month ? `?month=${month}` : '');
                    window.fetchWithTimeout(target, { headers: { Accept: 'application/json' } })
                        .then((response) => (response.ok ? response.json() : Promise.reject(new Error('Could not load the calendar.'))))
                        .then((data) => {
                            this.month = data.month;
                            this.monthLabel = data.monthLabel;
                            this.today = data.today;
                            this.gridStart = data.gridStart;
                            this.days = data.days || {};
                            this.upcoming = data.upcoming || [];
                            if (!this.selected) this.selected = data.today;
                        })
                        .catch((error) => { this.error = error.message || 'Could not load the calendar.'; })
                        .finally(() => { this.loading = false; });
                },
                shiftMonth(offset) {
                    const [y, m] = this.month.split('-').map(Number);
                    const next = new Date(y, m - 1 + offset, 1);
                    this.load(`${next.getFullYear()}-${pad(next.getMonth() + 1)}`);
                },
                goToday() {
                    this.selected = this.today;
                    this.load(this.today.slice(0, 7));
                },
                cells() {
                    if (!this.gridStart) return [];
                    const start = parse(this.gridStart);
                    return Array.from({ length: 42 }, (_, i) => {
                        const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
                        const key = iso(d);
                        const events = this.days[key] || [];
                        return {
                            key,
                            day: d.getDate(),
                            outside: key.slice(0, 7) !== this.month,
                            events,
                            activeCount: events.filter((e) => e.isActive).length,
                        };
                    });
                },
                select(key) { this.selected = key; },
                selectedEvents() { return this.days[this.selected] || []; },
                selectedLabel() {
                    if (!this.selected) return '';
                    const d = parse(this.selected);
                    const label = d.toLocaleDateString('en-US', { day: '2-digit', month: 'short' });
                    return (this.selected === this.today ? 'Today - ' : '') + label;
                },
                dayParts(key) {
                    const d = parse(key);
                    return { weekday: d.toLocaleDateString('en-US', { weekday: 'short' }), day: pad(d.getDate()), month: d.toLocaleDateString('en-US', { month: 'short' }) };
                },
                initials(name) {
                    return (name || '').split(/\s+/).filter(Boolean).map((p) => p[0]).slice(0, 2).join('').toUpperCase();
                },
                statusLabel(status) {
                    return { pending: 'Pending', in_progress: 'In Progress', overdue: 'Overdue', completed: 'Completed' }[status] || status;
                },
                dayTitle(cell) {
                    if (!cell.events.length) return '';
                    return cell.events.length + ' offboardee' + (cell.events.length > 1 ? 's' : '') + ' — Last Working Day';
                },
            };
        };
    </script>
@endonce

<div x-data="offboardingCalendar(@js(route('dashboard.calendar')))"
    class="lwd-cal rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] sm:p-6">

    {{-- Header — mirrors the reference: title left, "Today - 27 Nov ⌄" right
         (toggles the month grid). --}}
    <div class="flex items-center justify-between gap-3">
        <div>
            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Calendar</h3>
            <p class="mt-0.5 text-theme-sm text-gray-500 dark:text-gray-400">Offboardees by Last Working Day</p>
        </div>
        <button type="button" @click="showMonth = !showMonth"
            class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-sm font-medium text-gray-800 hover:bg-gray-100 dark:text-white/90 dark:hover:bg-white/5"
            :aria-expanded="showMonth.toString()">
            <span x-text="selectedLabel()"></span>
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" class="transition-transform" :class="showMonth ? 'rotate-180' : ''">
                <path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
    </div>

    {{-- Month grid --}}
    <div x-show="showMonth" x-transition.opacity class="mt-4">
        <div class="mb-3 flex items-center justify-between">
            <button type="button" @click="shiftMonth(-1)" :disabled="loading || !month" aria-label="Previous month"
                class="flex h-8 w-8 items-center justify-center rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M12.5 15L7.5 10L12.5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" /></svg>
            </button>
            <div class="flex items-center gap-2">
                <span class="text-sm font-semibold text-gray-800 dark:text-white/90" x-text="monthLabel || '—'"></span>
                <button type="button" @click="goToday()" x-show="month && today && (month !== today.slice(0, 7) || selected !== today)"
                    class="rounded-full bg-[#145a3a]/10 px-2.5 py-0.5 text-xs font-medium text-[#145a3a] hover:bg-[#145a3a]/15 dark:bg-[#3aa876]/15 dark:text-[#3aa876]">Today</button>
            </div>
            <button type="button" @click="shiftMonth(1)" :disabled="loading || !month" aria-label="Next month"
                class="flex h-8 w-8 items-center justify-center rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                <svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M7.5 15L12.5 10L7.5 5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" /></svg>
            </button>
        </div>

        <div class="lwd-cal__grid">
            <template x-for="weekday in ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']" :key="weekday">
                <div class="lwd-cal__weekday" x-text="weekday"></div>
            </template>
        </div>
        <div class="lwd-cal__grid" :class="loading ? 'opacity-60' : ''">
            <template x-for="cell in cells()" :key="cell.key">
                <button type="button" @click="select(cell.key)" class="lwd-cal__day"
                    :class="{ 'is-outside': cell.outside, 'has-events': cell.events.length, 'is-today': cell.key === today, 'is-selected': cell.key === selected }"
                    :title="dayTitle(cell)"
                    :aria-label="cell.key + (cell.events.length ? ', ' + dayTitle(cell) : '')">
                    <span x-text="cell.day"></span>
                    <template x-if="cell.events.length">
                        <span class="lwd-cal__dot" :class="cell.activeCount ? '' : 'is-completed'"></span>
                    </template>
                    <template x-if="cell.events.length > 1">
                        <span class="lwd-cal__count" x-text="cell.events.length"></span>
                    </template>
                </button>
            </template>
        </div>
        <p x-show="error" x-cloak class="mt-2 text-xs text-error-600 dark:text-error-400" x-text="error"></p>
    </div>

    {{-- Agenda for the selected date (reference design: label column +
         soft rounded event cards with avatars). --}}
    <div class="mt-5 border-t border-gray-100 pt-4 dark:border-gray-800">
        <div class="mb-2 flex items-center justify-between">
            <p class="text-sm font-semibold text-gray-800 dark:text-white/90">
                <span x-text="selected ? dayParts(selected).weekday + ', ' + dayParts(selected).month + ' ' + dayParts(selected).day : ''"></span>
            </p>
            <span x-show="selectedEvents().length" class="text-xs text-gray-500 dark:text-gray-400"
                x-text="selectedEvents().length + ' offboardee' + (selectedEvents().length > 1 ? 's' : '') + ' leaving'"></span>
        </div>

        <div class="lwd-cal__agenda">
            <template x-if="selectedEvents().length">
                <div class="lwd-cal__row">
                    <div class="lwd-cal__time">LWD<strong x-text="dayParts(selected).day"></strong><span x-text="dayParts(selected).month"></span></div>
                    <div>
                        <template x-for="event in selectedEvents()" :key="event.id">
                            <div class="lwd-cal__event" :class="'is-' + event.status">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="min-w-0 text-sm font-semibold text-gray-800 dark:text-white/90" style="overflow-wrap: anywhere;" x-text="event.name"></p>
                                    <span class="lwd-cal__badge" :class="'is-' + event.status" x-text="statusLabel(event.status)"></span>
                                </div>
                                <div class="mt-2 flex items-center" style="gap: .65rem;">
                                    <span class="lwd-cal__avatar" x-text="initials(event.name)"></span>
                                    <div class="min-w-0 text-xs text-gray-500 dark:text-gray-400">
                                        <p class="truncate"><span x-text="event.employeeCode || '—'"></span> &middot; <span x-text="event.department || '—'"></span></p>
                                        <p class="truncate" x-text="event.separationType || '—'"></p>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            <template x-if="!loading && !selectedEvents().length">
                <div>
                    <p class="rounded-xl border border-dashed border-gray-200 px-4 py-3 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        No offboardees scheduled to leave on this day.
                    </p>
                    <template x-if="upcoming.length">
                        <div class="mt-4">
                            <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Upcoming Last Working Days</p>
                            <template x-for="event in upcoming.slice(0, 3)" :key="'up-' + event.id">
                                <button type="button" class="lwd-cal__row w-full text-left" @click="select(event.lastWorkingDay); if (event.lastWorkingDay.slice(0, 7) !== month) load(event.lastWorkingDay.slice(0, 7))">
                                    <div class="lwd-cal__time"><span x-text="dayParts(event.lastWorkingDay).month"></span><strong x-text="dayParts(event.lastWorkingDay).day"></strong></div>
                                    <div class="lwd-cal__event" :class="'is-' + event.status">
                                        <div class="flex items-center" style="gap: .65rem;">
                                            <span class="lwd-cal__avatar" x-text="initials(event.name)"></span>
                                            <div class="min-w-0">
                                                <p class="text-sm font-semibold text-gray-800 dark:text-white/90" style="overflow-wrap: anywhere;" x-text="event.name"></p>
                                                <p class="truncate text-xs text-gray-500 dark:text-gray-400" x-text="(event.employeeCode || '—') + ' · ' + (event.department || '—')"></p>
                                            </div>
                                        </div>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>
</div>
