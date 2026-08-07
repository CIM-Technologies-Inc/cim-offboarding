@props([
    'monthLabels' => [],
    'initiatedSeries' => [],
    'completedSeries' => [],
])

<div class="rounded-2xl border border-gray-200 bg-white px-5 pb-5 pt-5 dark:border-gray-800 dark:bg-white/[0.03] sm:px-6 sm:pt-6">
    <div class="flex flex-col gap-5 mb-6 sm:flex-row sm:justify-between">
        <div class="w-full">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
                Offboarding Trend
            </h3>
            <p class="mt-1 text-gray-500 text-theme-sm dark:text-gray-400">
                Initiated vs. completed requests over the last 12 months
            </p>
        </div>
    </div>
    <div class="max-w-full overflow-x-auto custom-scrollbar">
        <div id="offboardingStatusTrendChart" class="-ml-4 min-w-[700px] pl-2 xl:min-w-full"></div>
    </div>
</div>

<script>
    (() => {
        const chartElement = document.querySelector('#offboardingStatusTrendChart');
        if (!chartElement || typeof ApexCharts === 'undefined') return;

        const chart = new ApexCharts(chartElement, {
            series: [
                { name: 'Initiated', data: @json($initiatedSeries) },
                { name: 'Completed', data: @json($completedSeries) },
            ],
            legend: { show: true, position: 'top', horizontalAlign: 'left' },
            colors: ['#145A3A', '#9CB9FF'],
            chart: {
                fontFamily: 'Outfit, sans-serif',
                height: 310,
                type: 'area',
                toolbar: { show: false },
            },
            fill: {
                gradient: { enabled: true, opacityFrom: 0.55, opacityTo: 0 },
            },
            stroke: { curve: 'straight', width: [2, 2] },
            markers: { size: 0 },
            grid: {
                xaxis: { lines: { show: false } },
                yaxis: { lines: { show: true } },
            },
            dataLabels: { enabled: false },
            xaxis: {
                type: 'category',
                categories: @json($monthLabels),
                axisBorder: { show: false },
                axisTicks: { show: false },
            },
            yaxis: {
                title: { style: { fontSize: '0px' } },
            },
        });

        chart.render();
    })();
</script>
