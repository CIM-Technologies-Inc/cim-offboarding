@props([
    'monthLabels' => [],
    'initiatedSeries' => [],
])

<div class="overflow-hidden rounded-2xl border border-gray-200 bg-white px-5 pt-5 sm:px-6 sm:pt-6 dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="flex items-center justify-between">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">
            Monthly Offboarding Requests
        </h3>
    </div>

    <div class="max-w-full overflow-x-auto custom-scrollbar">
        <div id="offboardingMonthlyChart" class="-ml-5 h-full min-w-[690px] pl-2 xl:min-w-full"></div>
    </div>
</div>

<script>
    (() => {
        const chartElement = document.querySelector('#offboardingMonthlyChart');
        if (!chartElement || typeof ApexCharts === 'undefined') return;

        const chart = new ApexCharts(chartElement, {
            series: [{
                name: 'Requests',
                data: @json($initiatedSeries),
            }],
            colors: ['#145A3A'],
            chart: {
                fontFamily: 'Outfit, sans-serif',
                type: 'bar',
                height: 180,
                toolbar: { show: false },
            },
            plotOptions: {
                bar: {
                    horizontal: false,
                    columnWidth: '39%',
                    borderRadius: 5,
                    borderRadiusApplication: 'end',
                },
            },
            dataLabels: { enabled: false },
            stroke: { show: true, width: 4, colors: ['transparent'] },
            xaxis: {
                categories: @json($monthLabels),
                axisBorder: { show: false },
                axisTicks: { show: false },
            },
            legend: { show: false },
            yaxis: { title: false },
            grid: { yaxis: { lines: { show: true } } },
            fill: { opacity: 1 },
            tooltip: {
                x: { show: false },
                y: { formatter: (val) => val + ' requests' },
            },
        });

        chart.render();
    })();
</script>
