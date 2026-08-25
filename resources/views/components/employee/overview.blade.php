@props(['overview'])

@php
    $statusStyles = [
        'Pending' => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
        'In progress' => 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400',
        'Overdue' => 'bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400',
        'Completed' => 'bg-[#145a3a]/10 text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]',
        'Cancelled' => 'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400',
    ];
    $badgeClass = $statusStyles[$overview['status']] ?? $statusStyles['Pending'];
@endphp

<div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $overview['name'] }}</h3>
            <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
                {{ $overview['position'] }} &middot; {{ $overview['department'] }}
            </p>
        </div>
        <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $badgeClass }}">
            {{ $overview['status'] }}
        </span>
    </div>

    <div class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div>
            <p class="text-theme-xs text-gray-400">Employee Number</p>
            <p class="mt-0.5 text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $overview['employeeCode'] }}</p>
        </div>
        <div>
            <p class="text-theme-xs text-gray-400">Request Date</p>
            <p class="mt-0.5 text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $overview['requestDate'] }}</p>
        </div>
        <div>
            <p class="text-theme-xs text-gray-400">Last Working Day</p>
            <p class="mt-0.5 text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $overview['lastWorkingDay'] }}</p>
        </div>
        <div>
            <p class="text-theme-xs text-gray-400">Overall Progress</p>
            <p class="mt-0.5 text-theme-sm font-medium text-gray-800 dark:text-white/90">{{ $overview['progressPercent'] }}%</p>
        </div>
    </div>

    <div class="relative mt-3 h-2 w-full rounded-sm bg-gray-200 dark:bg-gray-800">
        <div class="absolute left-0 top-0 h-full rounded-sm bg-[#145A3A]" style="width: {{ $overview['progressPercent'] }}%"></div>
    </div>
</div>
