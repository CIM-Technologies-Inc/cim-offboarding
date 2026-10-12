{{--
    Retract Offboarding — the exact same button, form, and inline
    Swal/fetch handler for every view that renders it (Card View and List
    View both @include this one file), so the retraction logic exists in
    exactly one place and can never drift between presentations. Only the
    wrapper/button CLASSES vary, via the `$compact` flag, between the
    full-width Card layout and the inline List row layout.
--}}
@php
    $compact = $compact ?? false;
@endphp
@can('offboarding-requests.cancel')
    @if ($offboardee['cancelOffboardingUrl'])
        {{-- Reactive, not a static Blade `@if`, for the same reason
             `canBulkExtendDue` gates Extend Due below: a successful
             extension can push the Last Working Day back into the
             future and must reopen this button immediately, with no
             page reload — see the `canRetractOffboarding`/`status`
             patch-in-place after a successful Extend Due submit. --}}
        <div class="{{ $compact ? '' : 'mt-4 border-t border-gray-100 pt-4 dark:border-gray-800' }}"
            x-show="canRetractOffboarding && status !== 'cancelled' && status !== 'completed'"
            @click.stop="">
            {{-- Deliberately not a native form submit: cancellation must stay on
                 THIS page and remove just this one card the instant the server
                 confirms success, with no full-page reload — a plain
                 `$el.requestSubmit()`/Turbo navigation can't do that. The fetch
                 call awaits the full response (DB transaction + notification
                 dispatch all complete server-side before it resolves), so the
                 loading state below covers the entire cancellation, not just the
                 network round trip start. --}}
            <form method="POST" action="{{ $offboardee['cancelOffboardingUrl'] }}"
                @submit.prevent="if (cancelling) return;
                    Swal.fire({
                        title: 'Retract Offboarding Request?',
                        html: '<p style=\'text-align:left;font-size:14px;margin:0 0 12px;\'>Are you sure you want to retract this offboarding request for ' + @js($offboardee['name']) + '? The request will be marked Retracted and kept as a read-only historical record — nothing is deleted.</p>'
                            + '<label style=\'display:block;text-align:left;font-size:12px;color:#6b7280;margin:0 0 4px;\'>Retraction Reason/Remarks</label>'
                            + '<textarea id=\'cancel-offboarding-reason-input\' class=\'swal2-textarea\' style=\'margin:0;width:100%;box-sizing:border-box;\' rows=\'3\' placeholder=\'Explain why this offboarding request is being retracted...\'></textarea>',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Confirm Retraction',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#dc2626',
                        cancelButtonColor: '#6b7280',
                        reverseButtons: true,
                        // Requirement: the dialog itself must show a loading state on
                        // its own Confirm Retraction button and stay open — not close
                        // immediately and hand off to a second, separate popup — for
                        // the network round trip, only closing on genuine success and
                        // staying open (with the error shown inline, via
                        // `showValidationMessage`) on failure so the reason stays typed
                        // and the user can retry without starting over. Same
                        // async-`preConfirm` + `showLoaderOnConfirm` pair the Extend
                        // Last Working Day dialog already uses, for consistency.
                        showLoaderOnConfirm: true,
                        preConfirm: async () => {
                            const reason = (document.getElementById('cancel-offboarding-reason-input').value || '').trim();
                            if (!reason) {
                                Swal.showValidationMessage('A reason is required to retract this offboarding request.');
                                return false;
                            }
                            cancelling = true;
                            try {
                                const response = await fetch($el.action, {
                                    method: 'POST',
                                    headers: {
                                        'Accept': 'application/json',
                                        'X-Requested-With': 'XMLHttpRequest',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                        'Content-Type': 'application/json',
                                    },
                                    body: JSON.stringify({ reason: reason }),
                                });
                                const data = await response.json().catch(() => ({}));
                                if (!response.ok) {
                                    // Same prefer-the-specific-field-error-over-Laravel's-
                                    // generic-422-wrapper convention the Extend Due flow
                                    // already uses — the client-side check above blocks an
                                    // empty reason before this is ever reached in normal use,
                                    // but a direct/bypassed request still gets a real message.
                                    const fieldError = data.errors ? Object.values(data.errors)[0]?.[0] : null;
                                    throw new Error(fieldError || data.message || 'Retraction failed. Please try again.');
                                }
                                return data;
                            } catch (error) {
                                // Surfaced INSIDE the still-open dialog (SweetAlert2's own
                                // validation-message banner) rather than a separate error
                                // popup — the reason already typed stays exactly as it
                                // was, ready to retry without reopening the dialog.
                                Swal.showValidationMessage(error.message);
                                return false;
                            } finally {
                                cancelling = false;
                            }
                        }
                    }).then((result) => {
                        if (!result.isConfirmed) return;
                        const data = result.value;
                        // Patch in place — not `.remove()` — so the retracted
                        // request stays visible as a read-only historical card
                        // (see OffboardingRequestController::cancel()'s own
                        // docblock) instead of disappearing. Same flip-the-
                        // reactive-status, let-the-existing-badge/action-
                        // bindings-react convention Extend Due's own success
                        // handler already uses on this same card.
                        status = 'cancelled';
                        // Read back from the server's own response — the actual
                        // persisted `cancelled_by`/`cancellation_reason` columns
                        // (see cancel()'s own docblock) — never the currently
                        // logged-in viewer or a client-side echo of the typed
                        // reason, so this always matches the real historical
                        // record even if it's ever read back by someone else.
                        cancelledByName = data.cancelledByName;
                        cancellationReason = data.cancellationReason;
                        cancelledAt = data.cancelledAt;
                        // Retracting reverts the employee to `active` server-side
                        // (see cancel()'s own docblock), which makes them eligible
                        // for a brand new request immediately — but the New
                        // Offboarding Request modal's own employee/Immediate Head
                        // pickers each build their list ONCE, at page load, baked
                        // into their own x-data (see new-request-modal.blade.php).
                        // Dispatched here, listened for there, so this employee is
                        // selectable again with no page reload — same cross-
                        // component window-event convention that modal's own
                        // immediate-head-auto-select already uses.
                        $dispatch('employee-available-for-offboarding', {
                            id: @js((string) $offboardee['employeeId']),
                            name: @js($offboardee['name']),
                            code: @js($offboardee['employeeCode']),
                            department: @js($offboardee['department']),
                            position: @js($offboardee['designation']),
                        });
                        Swal.fire({
                            icon: 'success',
                            title: 'Retracted',
                            text: data.message || 'Offboarding request has been retracted.',
                            confirmButtonColor: '#145a3a',
                        });
                    });
                ">
                @csrf
                <button type="submit" :disabled="cancelling"
                    class="{{ $compact
                        ? 'inline-flex items-center justify-center gap-1.5 rounded-lg border border-error-200 px-3.5 py-2 text-sm font-medium text-error-600 transition-colors hover:border-error-600 hover:bg-error-600 hover:text-white disabled:cursor-not-allowed disabled:opacity-60 dark:border-error-500/30 dark:text-error-400 dark:hover:border-error-500 dark:hover:bg-error-500 dark:hover:text-white'
                        : 'flex w-full items-center justify-center gap-1.5 rounded-lg border border-error-200 px-3 py-2 text-xs font-medium text-error-600 transition-colors hover:border-error-600 hover:bg-error-600 hover:text-white disabled:cursor-not-allowed disabled:opacity-60 dark:border-error-500/30 dark:text-error-400 dark:hover:border-error-500 dark:hover:bg-error-500 dark:hover:text-white' }}">
                    <svg x-show="!cancelling" width="{{ $compact ? 16 : 14 }}" height="{{ $compact ? 16 : 14 }}" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M10 17.5C14.1421 17.5 17.5 14.1421 17.5 10C17.5 5.85786 14.1421 2.5 10 2.5C5.85786 2.5 2.5 5.85786 2.5 10C2.5 14.1421 5.85786 17.5 10 17.5Z" stroke="currentColor" stroke-width="1.5" />
                        <path d="M7.5 7.5L12.5 12.5M12.5 7.5L7.5 12.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                    </svg>
                    <svg x-show="cancelling" x-cloak class="{{ $compact ? 'h-4 w-4' : 'h-3.5 w-3.5' }} animate-spin" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    <span x-text="cancelling ? 'Retracting...' : (@js($compact) ? 'Retract' : 'Retract Offboarding')"></span>
                </button>
            </form>
        </div>
    @endif
@endcan
