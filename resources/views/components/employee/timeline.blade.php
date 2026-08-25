@props(['timeline' => []])

<div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] sm:p-6">
    <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Offboarding Timeline</h3>

    <div class="relative mt-5">
        @foreach ($timeline as $index => $step)
            <div class="relative flex gap-4 pb-7 last:pb-0">
                @if (!$loop->last)
                    <div class="absolute left-[11px] top-3 h-full w-px bg-gray-200 dark:bg-gray-700"></div>
                @endif
                <div class="relative z-10 flex h-6 w-6 shrink-0 items-center justify-center rounded-full
                    {{ ($step['cancelled'] ?? false) ? 'bg-error-500' : (($step['hold'] ?? false) ? 'bg-amber-500' : ($step['done'] ? 'bg-[#145a3a]' : 'bg-gray-200 dark:bg-gray-700')) }}">
                    @if ($step['done'] && ! ($step['hold'] ?? false))
                        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M13.4767 4.10714C13.7788 4.38292 13.8008 4.85162 13.5257 5.15436L6.83817 12.5211C6.69758 12.6759 6.49882 12.7644 6.29008 12.7644C6.08134 12.7644 5.88258 12.6759 5.74199 12.5211L2.47426 8.9211C2.19916 8.61836 2.22119 8.14966 2.52326 7.87388C2.82533 7.5981 3.29283 7.62018 3.56793 7.92292L6.29008 10.9184L12.4321 4.15582C12.7072 3.85308 13.1746 3.83137 13.4767 4.10714Z" fill="white" />
                        </svg>
                    @endif
                    @if ($step['hold'] ?? false)
                        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M8 4.5V9M8 11.5H8.007" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    @endif
                </div>
                <div class="pt-0.5">
                    <p class="text-sm font-medium {{ $step['done'] ? 'text-gray-800 dark:text-white/90' : 'text-gray-400 dark:text-gray-500' }}">
                        {{ $step['label'] }}
                    </p>
                    @if (!empty($step['comment']))
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $step['comment'] }}</p>
                    @endif
                    <p class="text-xs text-gray-400">{{ $step['date'] ?? 'Not yet reached' }}</p>
                </div>
            </div>
        @endforeach
    </div>
</div>
