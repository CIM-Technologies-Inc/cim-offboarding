@extends('layouts.fullscreen-layout')

@section('content')
    <div class="relative z-1 bg-white p-6 sm:p-0 dark:bg-gray-900">
        <div class="relative flex h-screen w-full flex-col justify-center sm:p-0 lg:flex-row dark:bg-gray-900">
            <div class="flex w-full flex-1 flex-col lg:w-1/2">
                <div class="mx-auto flex w-full max-w-md flex-1 flex-col justify-center">
                    <div>
                        <div class="mb-5 sm:mb-8">
                            <h1 class="text-title-sm sm:text-title-md mb-2 font-semibold text-gray-800 dark:text-white/90">
                                Checklist Approval
                            </h1>
                            <p id="statusText" class="text-sm text-gray-500 dark:text-gray-400">{{ $message }}</p>
                        </div>

                        @if ($state !== 'invalid')
                            <div class="mb-6 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm dark:border-gray-800 dark:bg-white/[0.03]">
                                <p class="mb-1.5 flex items-center justify-between gap-4">
                                    <span class="shrink-0 text-gray-400">Employee</span>
                                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ $offboardeeName }} ({{ $offboardeeEmployeeCode }})</span>
                                </p>
                                <p class="mb-1.5 flex items-center justify-between gap-4">
                                    <span class="shrink-0 text-gray-400">Checklist</span>
                                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ $checklistTitle }}</span>
                                </p>
                                <p class="flex items-center justify-between gap-4">
                                    <span class="shrink-0 text-gray-400">Department Head</span>
                                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ $departmentHeadName }}</span>
                                </p>
                            </div>
                        @endif

                        <p class="text-center text-sm text-gray-500 dark:text-gray-400">
                            <a href="{{ route('login') }}" class="text-brand-500 hover:text-brand-600 dark:text-brand-400 font-medium">
                                Go to CIM Offboarding
                            </a>
                        </p>
                    </div>
                </div>
            </div>

            <div class="bg-brand-950 relative hidden h-full w-full items-center lg:grid lg:w-1/2 dark:bg-white/5">
                <div class="z-1 flex items-center justify-center">
                    <x-common.common-grid-shape/>
                    <div class="flex max-w-xs flex-col items-center">
                        <a href="/" class="block">
                            <img src="/images/logo/signin-logo.svg" alt="Logo" />
                        </a>
                        <p class="text-center text-gray-400 dark:text-white/60">
                            Welcome To Employee Offboarding Platform
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const state = @json($state);
            const confirmUrl = @json($confirmUrl);
            const checklistTitle = @json($checklistTitle);
            const offboardeeName = @json($offboardeeName);
            const offboardeeEmployeeCode = @json($offboardeeEmployeeCode);
            const departmentHeadName = @json($departmentHeadName);
            const initialMessage = @json($message);
            const statusText = document.getElementById('statusText');

            const escapeHtml = (value) => {
                const div = document.createElement('div');
                div.textContent = value ?? '';
                return div.innerHTML;
            };

            function submitApproval() {
                Swal.fire({
                    title: 'Approving…',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: () => Swal.showLoading(),
                });

                window.fetchWithTimeout(confirmUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                }).then((res) => res.json()).then((data) => {
                    statusText.textContent = data.message;
                    Swal.fire({
                        icon: data.state === 'approved' ? 'success' : 'info',
                        title: data.state === 'approved' ? 'Approved!' : 'Notice',
                        text: data.message,
                        confirmButtonColor: '#145a3a',
                    });
                }).catch((e) => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Something went wrong',
                        text: e?.name === 'AbortError'
                            ? 'The request took too long. Please check whether it went through before trying again.'
                            : 'Please try again later.',
                        confirmButtonColor: '#145a3a',
                    });
                });
            }

            if (state === 'confirm') {
                Swal.fire({
                    title: 'Approve this checklist?',
                    html: 'You are about to approve the <b>' + escapeHtml(checklistTitle) + '</b> checklist '
                        + 'for <b>' + escapeHtml(offboardeeName) + ' (' + escapeHtml(offboardeeEmployeeCode) + ')</b> '
                        + 'as the assigned Department Head, <b>' + escapeHtml(departmentHeadName) + '</b>.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Approve',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#145a3a',
                    cancelButtonColor: '#6b7280',
                    reverseButtons: true,
                }).then((result) => {
                    if (result.isConfirmed) {
                        submitApproval();
                    } else {
                        statusText.textContent = 'Approval cancelled — no changes were made.';
                    }
                });
            } else if (state === 'already_approved') {
                Swal.fire({ icon: 'info', title: 'Already Approved', text: initialMessage, confirmButtonColor: '#145a3a' });
            } else if (state === 'not_ready' || state === 'not_actionable') {
                Swal.fire({ icon: 'warning', title: 'Cannot Approve', text: initialMessage, confirmButtonColor: '#145a3a' });
            } else if (state === 'no_signature') {
                Swal.fire({ icon: 'warning', title: 'E-Signature Required', text: initialMessage, confirmButtonColor: '#145a3a' });
            } else {
                Swal.fire({ icon: 'error', title: 'Invalid Link', text: initialMessage, confirmButtonColor: '#145a3a' });
            }
        });
    </script>
@endsection
