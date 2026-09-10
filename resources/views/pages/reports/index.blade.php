@extends('layouts.app')

@php
    // Every export/tab link needs the CURRENT filter set carried over, only
    // overriding the one thing that link itself changes (report type, or
    // export format) — built once here so the view doesn't repeat this
    // merge logic five times.
    $currentQuery = array_filter([
        'type' => $filters['type'],
        'date_from' => $filters['date_from'],
        'date_to' => $filters['date_to'],
        'separation_type' => $filters['separation_type'],
        'department' => $filters['department'],
        'status' => $filters['status'],
    ]);

    $reportTypes = [
        'processing_time' => 'Offboarding Processing Time',
        'pending_cases' => 'Pending Offboarding Cases',
        'completion_rates' => 'Completion Rates by Separation Type',
    ];
@endphp

@section('content')
    <x-common.page-breadcrumb pageTitle="Reports" />

    {{-- Report type tabs — each a plain link carrying every OTHER current
         filter forward, so switching report type never resets the date
         range/department/etc the admin already picked. --}}
    <div class="mb-6 flex flex-wrap items-center gap-2 border-b border-gray-200 dark:border-gray-800">
        @foreach ($reportTypes as $value => $label)
            <a href="{{ route('reports.index', array_merge($currentQuery, ['type' => $value])) }}"
                class="border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $filters['type'] === $value
                    ? 'border-[#145a3a] text-[#145a3a] dark:border-[#3aa876] dark:text-[#3aa876]'
                    : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
        <h3 class="mb-4 text-base font-semibold text-gray-800 dark:text-white/90">Report Filters</h3>
        <form method="GET" action="{{ route('reports.index') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <input type="hidden" name="type" value="{{ $filters['type'] }}" />

            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Date From</label>
                <x-form.date-picker id="report_date_from" name="date_from" placeholder="Any" :defaultDate="$filters['date_from']" />
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Date To</label>
                <x-form.date-picker id="report_date_to" name="date_to" placeholder="Any" :defaultDate="$filters['date_to']" />
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Separation Type</label>
                <select name="separation_type"
                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent px-3 pr-8 text-sm text-gray-700 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    <option value="">All</option>
                    @foreach ($separationTypes as $type)
                        <option value="{{ $type }}" @selected($filters['separation_type'] === $type)>{{ $type }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Department</label>
                <select name="department"
                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent px-3 pr-8 text-sm text-gray-700 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    <option value="">All</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department }}" @selected($filters['department'] === $department)>{{ $department }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Status</label>
                <select name="status"
                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent px-3 pr-8 text-sm text-gray-700 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    <option value="">All</option>
                    <option value="pending" @selected($filters['status'] === 'pending')>Pending</option>
                    <option value="in_progress" @selected($filters['status'] === 'in_progress')>In Progress</option>
                    <option value="overdue" @selected($filters['status'] === 'overdue')>Overdue</option>
                    <option value="completed" @selected($filters['status'] === 'completed')>Completed</option>
                    <option value="cancelled" @selected($filters['status'] === 'cancelled')>Cancelled</option>
                </select>
            </div>

            <div class="col-span-1 flex items-end gap-3 sm:col-span-2 lg:col-span-5">
                <button type="submit" data-turbo-submits-with="Generating..."
                    class="flex items-center justify-center gap-2 rounded-lg bg-[#145a3a] px-5 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630]">
                    Generate Report
                </button>
                <a href="{{ route('reports.index', ['type' => $filters['type']]) }}"
                    class="flex items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                    Reset Filters
                </a>
            </div>
        </form>
    </div>

    {{-- Summary cards --}}
    @php
        $summaryCards = [
            ['label' => 'Total Offboarding Cases', 'value' => $summary['total']],
            ['label' => 'Completed', 'value' => $summary['completed']],
            ['label' => 'Pending / In Progress', 'value' => $summary['pending_in_progress']],
            ['label' => 'Overdue', 'value' => $summary['overdue']],
            ['label' => 'Completion Rate', 'value' => $summary['completion_rate'] . '%'],
            ['label' => 'Avg. Processing Time', 'value' => $summary['avg_processing_days'] !== null ? $summary['avg_processing_days'] . ' day(s)' : '—'],
        ];
    @endphp
    <div class="mb-6 grid grid-cols-2 gap-4 md:gap-6 lg:grid-cols-3 xl:grid-cols-6">
        @foreach ($summaryCards as $card)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $card['label'] }}</span>
                <h4 class="mt-2 font-bold text-[#145a3a] text-title-sm dark:text-[#3aa876]">{{ $card['value'] }}</h4>
            </div>
        @endforeach
    </div>

    {{-- Chart --}}
    <!-- <div class="mb-6 grid grid-cols-1 gap-4 md:gap-6 {{ $filters['type'] === 'completion_rates' ? 'lg:grid-cols-2' : '' }}">
        <div class="rounded-2xl border border-gray-200 bg-white px-5 pb-5 pt-5 dark:border-gray-800 dark:bg-white/[0.03] sm:px-6 sm:pt-6">
            <h3 class="mb-4 text-base font-semibold text-gray-800 dark:text-white/90">Status Breakdown</h3>
            <div id="reportStatusBreakdownChart"></div>
        </div>

        @if ($filters['type'] === 'completion_rates' && $rows->isNotEmpty())
            <div class="rounded-2xl border border-gray-200 bg-white px-5 pb-5 pt-5 dark:border-gray-800 dark:bg-white/[0.03] sm:px-6 sm:pt-6">
                <h3 class="mb-4 text-base font-semibold text-gray-800 dark:text-white/90">Completion Rate by Separation Type</h3>
                <div id="reportCompletionRateChart"></div>
            </div>
        @endif
    </div> -->

    {{-- Export + results table --}}
    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 p-5 dark:border-gray-800">
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $typeLabel }}</h3>
            <div class="flex items-center gap-2">
                <a href="{{ route('reports.export', array_merge($currentQuery, ['format' => 'xlsx'])) }}"
                    class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                    Export Excel
                </a>
                <a href="{{ route('reports.export', array_merge($currentQuery, ['format' => 'pdf'])) }}" target="_blank" rel="noopener"
                    class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                    Export PDF
                </a>
                <a href="{{ route('reports.export', array_merge($currentQuery, ['format' => 'csv'])) }}"
                    class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                    Export CSV
                </a>
            </div>
        </div>

        <div class="max-w-full overflow-x-auto custom-scrollbar">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        @foreach ($columns as $header)
                            <th class="whitespace-nowrap px-5 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400">{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-gray-100 last:border-b-0 dark:border-gray-800">
                            @foreach (array_keys($columns) as $key)
                                <td class="whitespace-nowrap px-5 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $row[$key] ?? '—' }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($columns) }}" class="px-5 py-8 text-center text-sm text-gray-400">
                                No records match the selected filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <script>
        (() => {
            const breakdownEl = document.querySelector('#reportStatusBreakdownChart');
            if (breakdownEl && typeof ApexCharts !== 'undefined') {
                new ApexCharts(breakdownEl, {
                    series: [{{ $summary['completed'] }}, {{ $summary['pending_in_progress'] }}, {{ $summary['overdue'] }}],
                    labels: ['Completed', 'Pending / In Progress', 'Overdue'],
                    colors: ['#145A3A', '#9CB9FF', '#F97316'],
                    chart: { type: 'donut', height: 280, fontFamily: 'Outfit, sans-serif' },
                    legend: { position: 'bottom' },
                    dataLabels: { enabled: true },
                }).render();
            }

            const rateEl = document.querySelector('#reportCompletionRateChart');
            if (rateEl && typeof ApexCharts !== 'undefined') {
                new ApexCharts(rateEl, {
                    series: [{ name: 'Completion Rate (%)', data: @json($rows->pluck('completion_rate')->values()) }],
                    chart: { type: 'bar', height: 280, fontFamily: 'Outfit, sans-serif', toolbar: { show: false } },
                    colors: ['#145A3A'],
                    plotOptions: { bar: { borderRadius: 4, columnWidth: '45%' } },
                    dataLabels: { enabled: false },
                    xaxis: { categories: @json($rows->pluck('separation_type')->values()) },
                    yaxis: { max: 100, title: { text: '%' } },
                }).render();
            }
        })();
    </script>
@endsection
