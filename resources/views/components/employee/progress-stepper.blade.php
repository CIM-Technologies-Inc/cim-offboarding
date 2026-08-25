@props(['stages' => []])

@php
    $currentIndex = collect($stages)->search(fn ($stage) => ! $stage['done']);
    $currentIndex = $currentIndex === false ? count($stages) - 1 : $currentIndex;
@endphp

<div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] sm:p-6">
    <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Offboarding Progress</h3>

    <div class="mt-6 flex flex-col sm:flex-row sm:items-start">
        @foreach ($stages as $index => $stage)
            <div class="flex flex-1 items-center gap-3 sm:flex-col sm:items-center sm:gap-2 sm:text-center">
                <div class="relative flex w-full items-center sm:justify-center">
                    @unless ($loop->first)
                        <div class="absolute right-1/2 hidden h-px w-full -translate-y-1/2 sm:block {{ $stage['done'] || $index - 1 === $currentIndex ? 'bg-[#145a3a] dark:bg-[#3aa876]' : 'bg-gray-200 dark:bg-gray-700' }}" style="top: 16px;"></div>
                    @endunless
                    <div class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold
                        {{ $stage['done']
                            ? 'bg-[#145a3a] text-white'
                            : ($index === $currentIndex ? 'border-2 border-[#145a3a] bg-white text-[#145a3a] dark:border-[#3aa876] dark:bg-gray-900 dark:text-[#3aa876]' : 'bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500') }}">
                        @if ($stage['done'])
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path fill-rule="evenodd" clip-rule="evenodd" d="M13.4767 4.10714C13.7788 4.38292 13.8008 4.85162 13.5257 5.15436L6.83817 12.5211C6.69758 12.6759 6.49882 12.7644 6.29008 12.7644C6.08134 12.7644 5.88258 12.6759 5.74199 12.5211L2.47426 8.9211C2.19916 8.61836 2.22119 8.14966 2.52326 7.87388C2.82533 7.5981 3.29283 7.62018 3.56793 7.92292L6.29008 10.9184L12.4321 4.15582C12.7072 3.85308 13.1746 3.83137 13.4767 4.10714Z" fill="currentColor" />
                            </svg>
                        @else
                            {{ $index + 1 }}
                        @endif
                    </div>
                </div>
                <p class="text-theme-xs font-medium sm:mt-2 {{ $stage['done'] || $index === $currentIndex ? 'text-gray-800 dark:text-white/90' : 'text-gray-400 dark:text-gray-500' }}">
                    {{ $stage['label'] }}
                </p>
            </div>
        @endforeach
    </div>
</div>
