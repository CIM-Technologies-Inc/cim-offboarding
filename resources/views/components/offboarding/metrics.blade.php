@props([
    'totalEmployees' => 0,
    'pendingCount' => 0,
    'inProgressCount' => 0,
    'overdueCount' => 0,
    'completedCount' => 0,
])

@php
    $cards = [
        [
            'label' => 'Total Employees',
            'value' => $totalEmployees,
            'icon' => '<circle cx="12" cy="8" r="10"/><path d="M4 20a8 4 0 0 1 16 0"/>',
            'image' => '/images/user/total.png',
        ],
        [
            'label' => 'Pending',
            'value' => $pendingCount,
            'icon' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
            'image' => '/images/user/pending.png',
            'status' => 'pending',
        ],
        [
            'label' => 'In Progress',
            'value' => $inProgressCount,
            'icon' => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
            'image' => '/images/user/progress.png',
            'status' => 'in_progress',
        ],
        [
            'label' => 'Overdue',
            'value' => $overdueCount,
            'icon' => '<path d="M12 9v4M12 17h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>',
            'status' => 'overdue',
        ],
        [
            'label' => 'Completed',
            'value' => $completedCount,
            'icon' => '<circle cx="12" cy="12" r="9"/><polyline points="8 12 11 15 16 9"/>',
            'image' => '/images/user/complete.png',
            'status' => 'completed',
        ],
    ];
@endphp

<div class="grid grid-cols-2 gap-4 lg:grid-cols-5 md:gap-6">
    @foreach ($cards as $card)
        @php
            $tag = isset($card['status']) ? 'a' : 'div';
            $href = isset($card['status']) ? route('offboardees.index', ['status' => $card['status']]) : null;
        @endphp
        <{{ $tag }}
            @if ($href) href="{{ $href }}" @endif
            class="rounded-2xl border border-gray-200 bg-white p-5 transition-all duration-200 hover:-translate-y-1 hover:border-[#145a3a]/40 hover:shadow-lg dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-[#3aa876]/40 md:p-6 {{ $href ? 'block cursor-pointer' : '' }}"
        >
            <div class="flex items-center justify-center w-16 h-16 {{ isset($card['image']) ? '' : 'bg-gray-100 rounded-xl dark:bg-gray-800' }}">
                @if (isset($card['image']))
                    <img src="{{ $card['image'] }}" alt="{{ $card['label'] }}"
                        class="w-18 h-18 object-contain brightness-0 dark:invert" />
                @else
                    <svg class="stroke-gray-800 dark:stroke-white/90" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg">
                        {!! $card['icon'] !!}
                    </svg>
                @endif
            </div>

            <div class="mt-5">
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</span>
                <h4 class="mt-2 font-bold text-[#145a3a] text-title-sm dark:text-[#3aa876]">{{ $card['value'] }}</h4>
            </div>
        </{{ $tag }}>
    @endforeach
</div>
