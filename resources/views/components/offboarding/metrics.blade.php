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

{{-- 3D metric cards. The app's Tailwind bundle is prebuilt, so the 3D
     surface, depth shadows and tilt live in this scoped stylesheet rather
     than utility classes. Each card tilts toward the pointer (perspective
     + rotateX/Y via CSS variables set by Alpine), its icon tile and value
     float above the surface (translateZ), and a soft sheen follows the
     pointer. Light and dark mode each get their own surface, edge and
     shadow colors in the dark-green theme; motion is disabled for users
     who prefer reduced motion. --}}
@once
    <style>
        .metric-3d-grid { perspective: 1100px; }
        .metric-3d {
            --rx: 0deg; --ry: 0deg; --mx: 50%; --my: 0%; --lift: 0px;
            position: relative;
            display: block;
            border-radius: 1.1rem;
            padding: 1.15rem;
            transform-style: preserve-3d;
            transform: translateY(var(--lift)) rotateX(var(--rx)) rotateY(var(--ry));
            transition: transform .25s cubic-bezier(.2,.8,.2,1), box-shadow .25s ease, border-color .25s ease;
            background: linear-gradient(150deg, #ffffff 0%, #f4faf6 55%, #e9f4ee 100%);
            border: 1px solid rgba(20, 90, 58, .14);
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, .95),
                inset 0 -2px 0 rgba(20, 90, 58, .06),
                0 5px 0 -1px #d5e8dd,
                0 14px 24px -10px rgba(20, 90, 58, .35),
                0 4px 8px -4px rgba(16, 24, 40, .10);
            will-change: transform;
        }
        .metric-3d::before {
            content: "";
            position: absolute;
            inset: 0;
            border-radius: inherit;
            pointer-events: none;
            background: radial-gradient(180px circle at var(--mx) var(--my), rgba(255, 255, 255, .75), transparent 65%);
            opacity: 0;
            transition: opacity .25s ease;
        }
        .metric-3d:hover { --lift: -6px; border-color: rgba(20, 90, 58, .35); }
        .metric-3d:hover::before { opacity: 1; }
        .metric-3d:hover {
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, .95),
                inset 0 -2px 0 rgba(20, 90, 58, .06),
                0 7px 0 -1px #c9e1d3,
                0 26px 36px -14px rgba(20, 90, 58, .45),
                0 8px 14px -6px rgba(16, 24, 40, .12);
        }
        .metric-3d:focus-visible { outline: 2px solid #3aa876; outline-offset: 3px; }

        .metric-3d__icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 3.25rem;
            height: 3.25rem;
            border-radius: .9rem;
            background: linear-gradient(145deg, #24945f 0%, #145a3a 55%, #0d3f28 100%);
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, .35),
                inset 0 -3px 6px rgba(0, 0, 0, .25),
                0 8px 14px -6px rgba(20, 90, 58, .65);
            transform: translateZ(34px);
            transition: transform .25s cubic-bezier(.2,.8,.2,1);
        }
        .metric-3d:hover .metric-3d__icon { transform: translateZ(46px) scale(1.04); }
        .metric-3d__icon img { width: 1.9rem; height: 1.9rem; object-fit: contain; filter: brightness(0) invert(1) drop-shadow(0 1px 1px rgba(0, 0, 0, .3)); }
        .metric-3d__icon svg { stroke: #ffffff; filter: drop-shadow(0 1px 1px rgba(0, 0, 0, .3)); }

        .metric-3d__label { color: #4b6358; transform: translateZ(14px); }
        .metric-3d__value {
            color: #145a3a;
            transform: translateZ(24px);
            text-shadow: 0 1px 0 rgba(255, 255, 255, .9), 0 3px 6px rgba(20, 90, 58, .18);
        }

        .dark .metric-3d {
            background: linear-gradient(150deg, #1d2d26 0%, #15211c 55%, #0f1814 100%);
            border-color: rgba(58, 168, 118, .20);
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, .06),
                inset 0 -2px 0 rgba(0, 0, 0, .35),
                0 5px 0 -1px #0a110e,
                0 16px 26px -10px rgba(0, 0, 0, .75),
                0 0 0 1px rgba(0, 0, 0, .2);
        }
        .dark .metric-3d::before { background: radial-gradient(180px circle at var(--mx) var(--my), rgba(58, 168, 118, .22), transparent 65%); }
        .dark .metric-3d:hover {
            border-color: rgba(58, 168, 118, .45);
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, .08),
                inset 0 -2px 0 rgba(0, 0, 0, .35),
                0 7px 0 -1px #08100c,
                0 28px 40px -14px rgba(0, 0, 0, .85),
                0 0 26px -6px rgba(58, 168, 118, .35);
        }
        .dark .metric-3d__icon {
            background: linear-gradient(145deg, #3aa876 0%, #1f7a51 55%, #145a3a 100%);
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, .25),
                inset 0 -3px 6px rgba(0, 0, 0, .35),
                0 8px 16px -6px rgba(0, 0, 0, .8),
                0 0 18px -6px rgba(58, 168, 118, .55);
        }
        .dark .metric-3d__label { color: #9fb3aa; }
        .dark .metric-3d__value { color: #3aa876; text-shadow: 0 2px 10px rgba(58, 168, 118, .35); }

        @media (prefers-reduced-motion: reduce) {
            .metric-3d, .metric-3d__icon { transition: none; }
            .metric-3d, .metric-3d:hover { transform: none; }
        }
    </style>
@endonce

<div class="metric-3d-grid grid grid-cols-2 gap-4 lg:grid-cols-5 md:gap-6">
    @foreach ($cards as $card)
        @php
            $tag = isset($card['status']) ? 'a' : 'div';
            $href = isset($card['status']) ? route('offboardees.index', ['status' => $card['status']]) : null;
        @endphp
        <{{ $tag }}
            @if ($href) href="{{ $href }}" @endif
            x-data="{ rx: 0, ry: 0, mx: 50, my: 0 }"
            @mousemove="if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) { const r = $el.getBoundingClientRect(); const px = ($event.clientX - r.left) / r.width; const py = ($event.clientY - r.top) / r.height; ry = (px - 0.5) * 14; rx = (0.5 - py) * 14; mx = px * 100; my = py * 100; }"
            @mouseleave="rx = 0; ry = 0; mx = 50; my = 0"
            :style="`--rx: ${rx}deg; --ry: ${ry}deg; --mx: ${mx}%; --my: ${my}%;`"
            class="metric-3d {{ $href ? 'cursor-pointer' : '' }}"
        >
            {{-- Fixed-size raised icon tile so every card's icon occupies the
                 exact same box whether it renders an image or the SVG
                 fallback (e.g. "Overdue", which has no matching image). --}}
            <div class="metric-3d__icon">
                @if (isset($card['image']))
                    <img src="{{ $card['image'] }}" alt="{{ $card['label'] }}" />
                @else
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        {!! $card['icon'] !!}
                    </svg>
                @endif
            </div>

            {{-- The label's own box reserves space for up to two lines and
                 bottom-aligns its text, so every card's number lines up on
                 the same row however its label wraps ("Total Employees" /
                 "In Progress" wrap, the others don't). --}}
            <div class="mt-5" style="transform-style: preserve-3d;">
                <div class="flex min-h-[2.5rem] items-end">
                    <span class="metric-3d__label text-sm font-medium">{{ $card['label'] }}</span>
                </div>
                <h4 class="metric-3d__value mt-2 font-bold text-title-sm">{{ $card['value'] }}</h4>
            </div>
        </{{ $tag }}>
    @endforeach
</div>
