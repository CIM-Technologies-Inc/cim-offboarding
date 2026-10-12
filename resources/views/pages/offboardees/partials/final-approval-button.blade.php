{{--
    Final Approval / Resend Final Approval / "Approved" badge — shared by
    Card View and List View (@include'd from each) so this block's
    permission/status gating lives in exactly one place. Only the
    wrapper/button CLASSES vary, via the `$compact` flag.
--}}
@php
    $compact = $compact ?? false;
@endphp
@can('final-approval.send')
    {{-- Only ever shown for a request whose real `status` column is
         'completed' — never based on `displayStatus()`, which can read
         differently for unrelated reasons (overdue, etc.). --}}
    @if ($offboardee['status'] === 'completed' && $offboardee['finalApprovalUrl'])
        <div class="{{ $compact ? '' : 'mt-4 border-t border-gray-100 pt-4 dark:border-gray-800' }}" @click.stop="">
            @if ($offboardee['finalApprovalStatus'] === 'approved')
                <div class="{{ $compact
                    ? 'inline-flex items-center justify-center gap-1.5 rounded-lg bg-[#145a3a]/10 px-3.5 py-2 text-sm font-medium text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]'
                    : 'flex w-full items-center justify-center gap-1.5 rounded-lg bg-[#145a3a]/10 px-3 py-2 text-xs font-medium text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]' }}">
                    <svg width="{{ $compact ? 16 : 14 }}" height="{{ $compact ? 16 : 14 }}" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd" clip-rule="evenodd" d="M13.4767 4.10714C13.7788 4.38292 13.8008 4.85162 13.5257 5.15436L6.83817 12.5211C6.69758 12.6759 6.49882 12.7644 6.29008 12.7644C6.08134 12.7644 5.88258 12.6759 5.74199 12.5211L2.47426 8.9211C2.19916 8.61836 2.22119 8.14966 2.52326 7.87388C2.82533 7.5981 3.29283 7.62018 3.56793 7.92292L6.29008 10.9184L12.4321 4.15582C12.7072 3.85308 13.1746 3.83137 13.4767 4.10714Z" fill="currentColor" />
                    </svg>
                    Approved
                </div>
            @else
                @php
                    $finalApprovalConfirmTitle = $offboardee['finalApprovalStatus'] === 'pending'
                        ? 'Resend the Final Approval request?'
                        : 'Send this offboarding request for Final Approval?';
                @endphp
                <form method="POST" action="{{ $offboardee['finalApprovalUrl'] }}" x-data="{ confirmed: false }"
                    @submit="if (!confirmed) {
                        $event.preventDefault();
                        Swal.fire({
                            title: @js($finalApprovalConfirmTitle),
                            text: 'This will email the currently active Final Signatory' + (@js($offboardee['finalSignatoryName']) ? (' (' + @js($offboardee['finalSignatoryName']) + ')') : '') + ' the completed Clearance Form for ' + @js($offboardee['name']) + ' and a one-click Approve link.',
                            icon: 'question',
                            showCancelButton: true,
                            confirmButtonText: 'Send',
                            cancelButtonText: 'Cancel',
                            confirmButtonColor: '#145a3a',
                            cancelButtonColor: '#6b7280',
                            reverseButtons: true
                        }).then((result) => { if (result.isConfirmed) { confirmed = true; $el.requestSubmit(); } });
                    }">
                    @csrf
                    <button type="submit" data-turbo-submits-with="Sending..."
                        class="{{ $compact
                            ? 'inline-flex items-center justify-center gap-1.5 rounded-lg border border-[#145a3a]/30 px-3.5 py-2 text-sm font-medium text-[#145a3a] transition-colors hover:border-[#145a3a] hover:bg-[#145a3a] hover:text-white dark:border-[#3aa876]/30 dark:text-[#3aa876] dark:hover:border-[#3aa876] dark:hover:bg-[#3aa876] dark:hover:text-white'
                            : 'flex w-full items-center justify-center gap-1.5 rounded-lg border border-[#145a3a]/30 px-3 py-2 text-xs font-medium text-[#145a3a] transition-colors hover:border-[#145a3a] hover:bg-[#145a3a] hover:text-white dark:border-[#3aa876]/30 dark:text-[#3aa876] dark:hover:border-[#3aa876] dark:hover:bg-[#3aa876] dark:hover:text-white' }}">
                        <svg width="{{ $compact ? 16 : 14 }}" height="{{ $compact ? 16 : 14 }}" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M3.5 5.5L10 10.5L16.5 5.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M3.5 5.5C3.5 4.94772 3.94772 4.5 4.5 4.5H15.5C16.0523 4.5 16.5 4.94772 16.5 5.5V14C16.5 14.5523 16.0523 15 15.5 15H4.5C3.94772 15 3.5 14.5523 3.5 14V5.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                        </svg>
                        {{ $offboardee['finalApprovalStatus'] === 'pending' ? 'Resend Final Approval' : 'Final Approval' }}
                    </button>
                </form>
            @endif
        </div>
    @endif
@endcan
