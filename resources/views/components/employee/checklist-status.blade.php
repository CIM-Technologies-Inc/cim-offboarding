@props(['checklists' => []])

@php
    $statusStyles = [
        'Cleared' => 'bg-[#145a3a]/10 text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]',
        'Declined' => 'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400',
        'Hold' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
        'Overdue' => 'bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400',
        'In Progress' => 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400',
        'Pending' => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
    ];
@endphp

<div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] sm:p-6">
    <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Checklist Status</h3>
    <p class="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
        Every checklist assigned for your clearance, and who's responsible for each one.
    </p>

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
        @forelse ($checklists as $checklist)
            @php
                $badgeClass = $statusStyles[$checklist['status']] ?? $statusStyles['Pending'];
            @endphp
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ $checklist['title'] }}</p>
                        <p class="text-theme-xs text-gray-400">{{ $checklist['department'] ?? '—' }}</p>
                    </div>
                    <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium {{ $badgeClass }}">
                        {{ $checklist['status'] }}
                    </span>
                </div>

                <div class="mt-2 space-y-1 text-theme-xs text-gray-500 dark:text-gray-400">
                    <p>Department Head/Approver: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $checklist['approverName'] ?? 'Unassigned' }}</span></p>
                    @if ($checklist['dueAt'])
                        <p class="{{ $checklist['isOverdue'] ? 'font-medium text-error-600 dark:text-error-400' : '' }}">
                            Due: {{ $checklist['dueAt'] }}
                            @if ($checklist['isOverdue']) — Overdue @endif
                        </p>
                    @endif
                    @if ($checklist['completedAt'])
                        <p>Completed: {{ $checklist['completedAt'] }}</p>
                    @endif
                    <p>Items: {{ $checklist['completedItemsCount'] }} / {{ $checklist['totalItemsCount'] }} completed</p>
                </div>

                @if (!empty($checklist['items']))
                    <div class="mt-3 space-y-1.5 border-t border-gray-100 pt-3 dark:border-gray-800">
                        @foreach ($checklist['items'] as $item)
                            <div class="flex items-center justify-between gap-2 text-theme-xs">
                                <span class="flex items-center gap-1.5 {{ $item['checked'] ? 'text-gray-500 line-through dark:text-gray-500' : 'text-gray-700 dark:text-gray-300' }}">
                                    @if ($item['checked'])
                                        <svg width="12" height="12" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" class="shrink-0 text-[#145a3a] dark:text-[#3aa876]">
                                            <path fill-rule="evenodd" clip-rule="evenodd" d="M13.4767 4.10714C13.7788 4.38292 13.8008 4.85162 13.5257 5.15436L6.83817 12.5211C6.69758 12.6759 6.49882 12.7644 6.29008 12.7644C6.08134 12.7644 5.88258 12.6759 5.74199 12.5211L2.47426 8.9211C2.19916 8.61836 2.22119 8.14966 2.52326 7.87388C2.82533 7.5981 3.29283 7.62018 3.56793 7.92292L6.29008 10.9184L12.4321 4.15582C12.7072 3.85308 13.1746 3.83137 13.4767 4.10714Z" fill="currentColor" />
                                        </svg>
                                    @endif
                                    {{ $item['title'] }}
                                </span>
                                @if ($item['onHold'])
                                    <span class="shrink-0 rounded-full bg-amber-50 px-2 py-0.5 font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-400">Hold</span>
                                @elseif ($item['signatoryName'])
                                    <span class="shrink-0 text-gray-400">{{ $item['signatoryName'] }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($checklist['followUpEligible'])
                    <div class="mt-3 border-t border-gray-100 pt-3 dark:border-gray-800" x-data="{ processing: false }">
                        @if ($checklist['followUpLastSentAt'])
                            <p class="mb-2 text-theme-xs text-gray-400">
                                Last followed up: {{ $checklist['followUpLastSentAt'] }}
                                &middot; {{ $checklist['followUpAttemptsUsed'] }}/{{ $checklist['followUpMaxAttempts'] }} follow-ups used
                            </p>
                        @endif

                        @if ($checklist['followUpMaxReached'])
                            <p class="text-theme-xs font-medium text-error-600 dark:text-error-400">
                                You've reached the maximum number of follow-up attempts for this offboarding request.
                            </p>
                        @elseif (!$checklist['followUpCanSendNow'])
                            <p class="text-theme-xs text-gray-400">
                                You can follow up on this checklist again starting {{ $checklist['followUpNextAllowedAt'] }}.
                            </p>
                        @else
                            <form method="POST" :action="@js($checklist['followUpUrl'])"
                                x-data="{ confirmed: false }"
                                @submit="if (!confirmed) {
                                    $event.preventDefault();
                                    Swal.fire({
                                        title: 'Send Follow-Up?',
                                        html: 'This will notify HR/Admin that <b>' + @js($checklist['title']) + '</b> is still pending.',
                                        icon: 'question',
                                        showCancelButton: true,
                                        confirmButtonText: 'Send Follow-Up',
                                        cancelButtonText: 'Cancel',
                                        confirmButtonColor: '#145a3a',
                                        cancelButtonColor: '#6b7280',
                                        reverseButtons: true,
                                    }).then((result) => {
                                        if (result.isConfirmed) {
                                            confirmed = true;
                                            processing = true;
                                            $el.requestSubmit();
                                        }
                                    });
                                }">
                                @csrf
                                <button type="submit" :disabled="processing"
                                    :class="processing ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-50 dark:hover:bg-white/5'"
                                    class="flex w-full items-center justify-center gap-1.5 rounded-lg border border-[#145a3a] px-3 py-1.5 text-xs font-medium text-[#145a3a] dark:border-[#3aa876] dark:text-[#3aa876]">
                                    <svg width="14" height="14" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M2.25 4.5h13.5v9a.75.75 0 01-.75.75H3a.75.75 0 01-.75-.75v-9z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                                        <path d="M2.25 4.5L9 9.75l6.75-5.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                    Follow Up
                                </button>
                            </form>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <p class="col-span-full text-theme-sm text-gray-500 dark:text-gray-400">No checklists have been assigned yet.</p>
        @endforelse
    </div>
</div>
