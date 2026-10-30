{{-- Logs module tabs: Activity Logs | Email Notifications. --}}
@php
    $tabs = [
        ['label' => 'Activity Logs', 'route' => 'logs.index'],
        ['label' => 'Email Notifications', 'route' => 'logs.email-notifications'],
    ];
@endphp
<div class="mb-6 flex flex-wrap items-center gap-2 border-b border-gray-200 dark:border-gray-800">
    @foreach ($tabs as $tab)
        @php $active = request()->routeIs($tab['route']); @endphp
        <a href="{{ route($tab['route']) }}"
            style="margin-bottom: -1px;" class="border-b-2 px-4 py-2.5 text-sm font-medium {{ $active ? 'border-[#145a3a] text-[#145a3a] dark:border-[#3aa876] dark:text-[#3aa876]' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' }}"
            @if ($active) aria-current="page" @endif>
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>
