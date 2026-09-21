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
            {{-- Fixed-height icon slot so every card's icon occupies the exact
                 same box regardless of whether it renders an image or the
                 SVG fallback (e.g. "Overdue", which has no matching icon
                 image) — previously the fallback sat in its own shaded
                 square at a different visual size, the most visible source
                 of misalignment across the row. --}}
            <div class="flex h-16 w-16 items-center justify-center">
                @if (isset($card['image']))
                    <img src="{{ $card['image'] }}" alt="{{ $card['label'] }}"
                        class="h-14 w-14 object-contain brightness-0 dark:invert" />
                @else
                    <svg class="stroke-gray-800 dark:stroke-white/90" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg">
                        {!! $card['icon'] !!}
                    </svg>
                @endif
            </div>

            {{-- The label's own box reserves space for up to two lines
                 (text-sm's own 1.25rem line-height × 2) and bottom-aligns
                 its text within it — "Total Employees"/"In Progress" wrap
                 to two lines while "Pending"/"Overdue"/"Completed" don't,
                 which previously left each card's number sitting at a
                 different height. Anchoring every label to the same
                 bottom edge means every value below it lines up on the
                 same row regardless of how its own label wraps. --}}
            <div class="mt-5">
                <div class="flex min-h-[2.5rem] items-end">
                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</span>
                </div>
                <h4 class="mt-2 font-bold text-[#145a3a] text-title-sm dark:text-[#3aa876]">{{ $card['value'] }}</h4>
            </div>
        </{{ $tag }}>
    @endforeach
</div>
