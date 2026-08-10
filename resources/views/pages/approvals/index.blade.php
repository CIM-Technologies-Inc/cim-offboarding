@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="Your Approval" />

    @if (session('success'))
        <div class="mb-6 rounded-lg border border-success-500 bg-success-50 px-4 py-3 text-sm text-success-600 dark:border-success-500/30 dark:bg-success-500/10 dark:text-success-400">
            {{ session('success') }}
        </div>
    @endif

    <div>
        <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
            Review pending offboarding requests and approve or decline them.
        </p>

        @if ($approvals->isEmpty())
            <div class="rounded-2xl border border-gray-200 bg-white p-10 text-center dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No offboarding requests are waiting for approval.
                </p>
            </div>
        @else
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($approvals as $approval)
                    <div x-data="{ processing: false }" @click="$dispatch('open-offboardee-modal', @js($approval))"
                        class="group cursor-pointer rounded-2xl border border-gray-200 bg-white p-5 transition-all duration-200 hover:-translate-y-1 hover:border-[#145a3a]/40 hover:shadow-lg dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-[#3aa876]/40">
                        <div class="flex items-start justify-between">
                            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-base font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                {{ collect(explode(' ', $approval['name']))->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode('') }}
                            </div>
                            <span class="rounded-full bg-yellow-50 px-2.5 py-1 text-xs font-medium text-yellow-700 dark:bg-yellow-500/15 dark:text-yellow-400">
                                Pending
                            </span>
                        </div>

                        <h4 class="mt-4 text-base font-semibold text-gray-800 group-hover:text-[#145a3a] dark:text-white/90 dark:group-hover:text-[#3aa876]">
                            {{ $approval['name'] }}
                        </h4>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $approval['designation'] }}</p>

                        <div class="mt-4 space-y-1.5 border-t border-gray-100 pt-4 dark:border-gray-800">
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-gray-400">Department</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $approval['department'] }}</span>
                            </div>
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-gray-400">Reason</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $approval['reason'] }}</span>
                            </div>
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-gray-400">Notice Period</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $approval['noticePeriod'] }}</span>
                            </div>
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-gray-400">Last Working Day</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $approval['lastWorkingDay'] }}</span>
                            </div>
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-gray-400">Approval Mode</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300">{{ $approval['approvalMode'] }}</span>
                            </div>
                        </div>

                        <div class="mt-4 flex items-center gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                            <button type="button" @click.stop="$dispatch('open-checklist-modal', @js($approval))"
                                class="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-[#145a3a] px-3 py-2 text-sm font-medium text-white hover:bg-[#0f4630]">
                                <svg width="16" height="16" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" clip-rule="evenodd" d="M3 4.5C3 4.08579 3.33579 3.75 3.75 3.75H14.25C14.6642 3.75 15 4.08579 15 4.5C15 4.91421 14.6642 5.25 14.25 5.25H3.75C3.33579 5.25 3 4.91421 3 4.5ZM3 9C3 8.58579 3.33579 8.25 3.75 8.25H14.25C14.6642 8.25 15 8.58579 15 9C15 9.41421 14.6642 9.75 14.25 9.75H3.75C3.33579 9.75 3 9.41421 3 9ZM3.75 12.75C3.33579 12.75 3 13.0858 3 13.5C3 13.9142 3.33579 14.25 3.75 14.25H10.5C10.9142 14.25 11.25 13.9142 11.25 13.5C11.25 13.0858 10.9142 12.75 10.5 12.75H3.75Z" fill="currentColor" />
                                </svg>
                                View Checklist
                            </button>
                            <form method="POST" action="{{ route('approvals.decline', $approval['id']) }}" class="flex-1"
                                @click.stop
                                @submit="if (!processing) {
                                    $event.preventDefault();
                                    Swal.fire({
                                        title: 'Decline this request?',
                                        text: 'You are about to decline {{ $approval['name'] }}\'s offboarding request. This action cannot be undone.',
                                        icon: 'warning',
                                        input: 'textarea',
                                        inputLabel: 'Reason (optional)',
                                        inputPlaceholder: 'e.g. Company equipment has not yet been returned.',
                                        showCancelButton: true,
                                        confirmButtonText: 'Decline',
                                        cancelButtonText: 'Cancel',
                                        confirmButtonColor: '#dc2626',
                                        cancelButtonColor: '#145a3a',
                                        reverseButtons: true
                                    }).then((result) => {
                                        if (result.isConfirmed) {
                                            $el.querySelector('input[name=comment]').value = result.value || '';
                                            processing = true;
                                            $el.requestSubmit();
                                        }
                                    });
                                }">
                                @csrf
                                <input type="hidden" name="comment" value="" />
                                <button type="submit" :disabled="processing"
                                    :class="processing ? 'opacity-50 cursor-not-allowed' : 'hover:bg-error-50 dark:hover:bg-error-500/10'"
                                    class="flex w-full items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-error-500 dark:border-gray-700">
                                    <svg width="16" height="16" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M13.5 4.5L4.5 13.5M4.5 4.5L13.5 13.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                    Decline
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <!-- Approval Status Modal -->
        <x-offboarding.status-timeline-modal />

        <!-- Approval Checklist Modal -->
        <x-approvals.checklist-modal />
    </div>
@endsection
