@props([
    'completionRate' => 0,
    'completedThisMonth' => 0,
    'dueThisMonth' => 0,
    'pendingCount' => 0,
])

<div class="rounded-2xl border border-gray-200 bg-gray-100 dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="shadow-default rounded-2xl bg-white px-5 pb-11 pt-5 dark:bg-gray-900 sm:px-6 sm:pt-6">
        <div class="flex justify-between">
            <div>
                <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                    This Month's Completion Rate
                </h3>
                <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
                    Offboarding requests completed vs. initiated this month
                </p>
            </div>
        </div>
        <div class="relative max-h-[195px]">
            <div id="offboardingCompletionGauge" class="h-full"></div>
        </div>
    </div>

    <div class="flex items-center justify-center gap-5 px-6 py-3.5 sm:gap-8 sm:py-5">
        <div>
            <p class="mb-1 text-center text-theme-xs text-gray-500 dark:text-gray-400 sm:text-sm">
                Initiated
            </p>
            <p class="flex items-center justify-center gap-1 text-base font-semibold text-gray-800 dark:text-white/90 sm:text-lg">
                {{ $dueThisMonth }}
            </p>
        </div>

        <div class="h-7 w-px bg-gray-200 dark:bg-gray-800"></div>

        <div>
            <p class="mb-1 text-center text-theme-xs text-gray-500 dark:text-gray-400 sm:text-sm">
                Completed
            </p>
            <p class="flex items-center justify-center gap-1 text-base font-semibold text-gray-800 dark:text-white/90 sm:text-lg">
                {{ $completedThisMonth }}
            </p>
        </div>

        <div class="h-7 w-px bg-gray-200 dark:bg-gray-800"></div>

        <div>
            <p class="mb-1 text-center text-theme-xs text-gray-500 dark:text-gray-400 sm:text-sm">
                Still Pending
            </p>
            <p class="flex items-center justify-center gap-1 text-base font-semibold text-gray-800 dark:text-white/90 sm:text-lg">
                {{ $pendingCount }}
            </p>
        </div>
    </div>
</div>

<script>
    (() => {
        const chartElement = document.querySelector('#offboardingCompletionGauge');
        if (!chartElement || typeof ApexCharts === 'undefined') return;

        const chart = new ApexCharts(chartElement, {
            series: [{{ (int) $completionRate }}],
            colors: ['#145A3A'],
            chart: {
                fontFamily: 'Outfit, sans-serif',
                type: 'radialBar',
                height: 330,
                sparkline: { enabled: true },
            },
            plotOptions: {
                radialBar: {
                    startAngle: -90,
                    endAngle: 90,
                    hollow: { size: '80%' },
                    track: { background: '#E4E7EC', strokeWidth: '100%', margin: 5 },
                    dataLabels: {
                        name: { show: false },
                        value: {
                            fontSize: '36px',
                            fontWeight: '600',
                            offsetY: 60,
                            color: '#1D2939',
                            formatter: (val) => val + '%',
                        },
                    },
                },
            },
            fill: { type: 'solid', colors: ['#145A3A'] },
            stroke: { lineCap: 'round' },
            labels: ['Completion Rate'],
        });

        chart.render();
    })();
</script>
