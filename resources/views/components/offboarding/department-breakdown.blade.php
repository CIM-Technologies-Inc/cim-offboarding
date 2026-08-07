@props(['departments' => []])

<div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] sm:p-6">
    <div class="flex justify-between">
        <div>
            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                Offboarding by Department
            </h3>
            <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
                Share of offboarding requests per department
            </p>
        </div>
    </div>

    <div class="mt-6 space-y-5">
        @forelse ($departments as $department)
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-[#145A3A]/10 text-xs font-semibold text-[#145A3A] dark:bg-[#145A3A]/20">
                        {{ strtoupper(substr($department['name'], 0, 2)) }}
                    </div>
                    <div>
                        <p class="text-theme-sm font-semibold text-gray-800 dark:text-white/90">
                            {{ $department['name'] }}
                        </p>
                        <span class="block text-theme-xs text-gray-500 dark:text-gray-400">
                            {{ $department['count'] }} {{ Str::plural('request', $department['count']) }}
                        </span>
                    </div>
                </div>

                <div class="flex w-full max-w-[140px] items-center gap-3">
                    <div class="relative block h-2 w-full max-w-[100px] rounded-sm bg-gray-200 dark:bg-gray-800">
                        <div
                            class="absolute left-0 top-0 flex h-full items-center justify-center rounded-sm bg-[#145A3A] text-xs font-medium text-white"
                            style="width: {{ $department['percentage'] }}%"
                        ></div>
                    </div>
                    <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                        {{ $department['percentage'] }}%
                    </p>
                </div>
            </div>
        @empty
            <p class="text-theme-sm text-gray-500 dark:text-gray-400">No offboarding activity yet.</p>
        @endforelse
    </div>
</div>
