{{--
    Extend Last Working Day (bulk Extend Due) — the exact same button,
    form, and inline Swal/fetch/flatpickr handler for every view that
    renders it (Card View and List View both @include this one file), so
    the extension logic exists in exactly one place and can never drift
    between presentations. Only the wrapper/button CLASSES vary, via the
    `$compact` flag, between the full-width Card layout and the inline
    List row layout.
--}}
@php
    $compact = $compact ?? false;
@endphp
@can('checklists.extend-due')
    @if ($offboardee['extendAllDueUrl'])
        <div class="{{ $compact ? '' : 'border-t border-gray-100 pt-4 dark:border-gray-800' }}" x-show="canBulkExtendDue && status !== 'cancelled'" :class="{ 'pointer-events-none opacity-50': cancelling }" @click.stop="">
            {{-- Same fetch-based (not native form submit) convention as Cancel
                 Offboarding above — the loading state must cover the ENTIRE
                 bulk extension (every checklist's due date pushed forward, its
                 own audit row + Timeline entry, and the extension emails), not
                 just the network round trip start. --}}
            <form method="POST" action="{{ $offboardee['extendAllDueUrl'] }}"
                @submit.prevent="if (extending) return;
                    let extendDueFlatpickr = null;
                    // Per-checklist breakdown — replaces a single combined 'Current Due
                    // Date' value with each applicable checklist's OWN due date and a
                    // Due/Not Due badge, so it's clear exactly WHICH checklist(s)
                    // triggered the button rather than only the latest date across all
                    // of them (a request can have some checklists already overdue
                    // alongside others that aren't yet). Reads the CARD's own reactive
                    // `extendDueChecklists` (a bare identifier — Alpine resolves this
                    // via its own `with()` scope chaining, same as `extending`/
                    // `cancelling` elsewhere on this card; an explicit `this.x` or a
                    // `const self = this` capture does NOT reliably reach the
                    // component's reactive data from inside an event-handler
                    // expression like this one, and silently reads `undefined`
                    // instead) so a second click after a successful extension shows
                    // fresh data without a page reload.
                    const extendDueChecklistRows = (extendDueChecklists || []).map((c) => (
                        '<div style=\'display:flex;justify-content:space-between;align-items:center;gap:12px;padding:6px 0;border-bottom:1px solid #f3f4f6;font-size:13px;\'>'
                        + '<span>' + c.title + '</span>'
                        + '<span style=\'display:flex;align-items:center;gap:8px;flex-shrink:0;\'>'
                        + '<span style=\'color:' + (c.hasReachedDueDate ? '#dc2626' : '#374151') + ';\'>' + c.dueDate + '</span>'
                        + '<span style=\'background:' + (c.hasReachedDueDate ? '#fee2e2' : '#f3f4f6') + ';color:' + (c.hasReachedDueDate ? '#dc2626' : '#6b7280') + ';font-size:11px;font-weight:600;padding:2px 8px;border-radius:9999px;white-space:nowrap;\'>' + (c.hasReachedDueDate ? 'Due' : 'Not Due') + '</span>'
                        + '</span>'
                        + '</div>'
                    )).join('');
                    Swal.fire({
                        title: 'Extend Last Working Day',
                        html: '<div style=\'text-align:left;border-bottom:1px solid #e5e7eb;padding-bottom:10px;margin-bottom:10px;\'>'
                            + '<p style=\'font-size:15px;font-weight:700;margin:0 0 2px;\'>' + @js($offboardee['name'] ?? '—') + '</p>'
                            + '<p style=\'font-size:12px;color:#6b7280;margin:0;\'>' + @js($offboardee['designation'] ?? '—') + ' &middot; ' + @js($offboardee['department'] ?? '—') + '</p>'
                            + '</div>'
                            + '<p style=\'text-align:left;font-size:13px;line-height:1.6;\'>'
                            + 'At least one checklist has reached its due date. Select the new Last Working Day — every checklist still awaiting approval will have its own due date recalculated from it, using its configured Due Date Extension (Days).</p>'
                            + '<p style=\'text-align:left;font-size:12px;color:#6b7280;margin:10px 0 2px;\'>Current Last Working Day</p>'
                            + '<p style=\'text-align:left;font-size:15px;font-weight:700;margin:0 0 10px;\'>' + (lastWorkingDay || '—') + '</p>'
                            + '<p style=\'text-align:left;font-size:12px;color:#6b7280;margin:0 0 2px;\'>Checklist Due Dates</p>'
                            + '<div style=\'margin-bottom:12px;\'>' + (extendDueChecklistRows || '<p style=\'font-size:12px;color:#9ca3af;margin:4px 0;\'>No applicable checklists.</p>') + '</div>'
                            + '<label style=\'display:block;text-align:left;font-size:12px;color:#6b7280;margin:0 0 4px;\'>Extend Last Working Day</label>'
                            + '<input id=\'extend-due-date-input\' type=\'text\' class=\'swal2-input\' style=\'margin:0;width:100%;\' placeholder=\'Select date\' autocomplete=\'off\' readonly />'
                            + '<div id=\'extend-due-date-spacer\' style=\'height:0;transition:height 0.15s ease;\'></div>'
                            // Hidden until the first click of the Extend Due button (see
                            // `preConfirm` below) — asking for a reason before a date is
                            // even picked reads as premature, so it only appears once
                            // there's actually something to justify.
                            + '<div id=\'extend-due-reason-wrapper\' style=\'display:none;\'>'
                            + '<label style=\'display:block;text-align:left;font-size:12px;color:#6b7280;margin:12px 0 4px;\'>Reason for Extension</label>'
                            + '<textarea id=\'extend-due-reason-input\' class=\'swal2-textarea\' style=\'margin:0;width:100%;box-sizing:border-box;\' rows=\'3\' placeholder=\'Explain why the Last Working Day is being extended...\'></textarea>'
                            + '</div>',
                        didOpen: () => {
                            // flatpickr's `static: true` calendar is `position: absolute`,
                            // so — unlike a normal in-flow element — it contributes NOTHING
                            // to its container's height: the modal never grows for it, and
                            // the calendar just renders past the popup's bottom edge, either
                            // getting clipped or forcing `.swal2-html-container`'s own
                            // overflow:auto to kick in as a cramped inner scrollbar. The
                            // spacer div right after the input is a normal in-flow element
                            // that DOES count — onOpen/onClose below resize it to exactly
                            // match the calendar's own rendered height, so the modal grows
                            // to make room for it (pushing Cancel/Extend Due down) the
                            // instant it opens, and shrinks back the instant it closes.
                            const spacer = document.getElementById('extend-due-date-spacer');
                            // flatpickr parses a STRING `minDate` using this same instance's
                            // own `dateFormat` ('M d, Y') — a plain 'Y-m-d' string like
                            // '2026-09-22' fails that parse silently (logs 'Invalid date
                            // provided' and disables nothing at all), so it's converted to a
                            // real JS Date object here instead, which bypasses string
                            // parsing entirely. Reads the CARD's reactive
                            // `extendDueMinSelectableDateIso`, same reasoning as
                            // `extendDueChecklistRows` above.
                            const minSelectableIso = extendDueMinSelectableDateIso;
                            extendDueFlatpickr = flatpickr(document.getElementById('extend-due-date-input'), {
                                dateFormat: 'M d, Y',
                                static: true,
                                monthSelectorType: 'static',
                                minDate: minSelectableIso ? new Date(minSelectableIso + 'T00:00:00') : null,
                                onOpen: (selectedDates, dateStr, instance) => {
                                    spacer.style.height = instance.calendarContainer.offsetHeight + 'px';
                                },
                                onClose: () => {
                                    spacer.style.height = '0px';
                                },
                            });
                        },
                        willClose: () => {
                            if (extendDueFlatpickr) { extendDueFlatpickr.destroy(); extendDueFlatpickr = null; }
                        },
                        showCancelButton: true,
                        confirmButtonText: 'Extend Date',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#145a3a',
                        cancelButtonColor: '#6b7280',
                        reverseButtons: true,
                        // Requirement: the dialog itself must show a loading state on
                        // its own Extend Due button and stay open — not close
                        // immediately and hand off to a second, separate popup — for
                        // the network round trip, only closing on genuine success and
                        // staying open (with the error shown inline, via
                        // `showValidationMessage`) on failure so the admin can correct
                        // the date/reason and retry without starting over. SweetAlert2's
                        // own async-`preConfirm` + `showLoaderOnConfirm` pair is exactly
                        // built for this: it disables both buttons and shows a spinner
                        // on Extend Due for as long as this returned promise is
                        // pending, and only closes the dialog if it resolves to a
                        // truthy value — a validation failure inside it (via `return
                        // false` after `showValidationMessage`) keeps the dialog open
                        // exactly like the two-step date/reason checks below already
                        // do, unifying both kinds of not-ready-yet into the same
                        // mechanism.
                        showLoaderOnConfirm: true,
                        preConfirm: async () => {
                            const selected = extendDueFlatpickr?.selectedDates?.[0];
                            if (!selected) {
                                Swal.showValidationMessage('Extend the Last Working Day.');
                                return false;
                            }
                            // Two-step confirm: the Reason field stays hidden until a
                            // date is actually picked and this button is clicked once —
                            // that first click only reveals it (and stops here, without
                            // submitting), so it's never shown before there's a date to
                            // justify. A second click, with the reason now visible and
                            // filled in, actually submits.
                            const reasonWrapper = document.getElementById('extend-due-reason-wrapper');
                            const reasonInput = document.getElementById('extend-due-reason-input');
                            if (reasonWrapper.style.display === 'none') {
                                reasonWrapper.style.display = '';
                                reasonInput.focus();
                                return false;
                            }
                            const reason = (reasonInput.value || '').trim();
                            if (!reason) {
                                Swal.showValidationMessage('A reason is required to extend the Last Working Day.');
                                return false;
                            }
                            const y = selected.getFullYear();
                            const m = String(selected.getMonth() + 1).padStart(2, '0');
                            const d = String(selected.getDate()).padStart(2, '0');

                            extending = true;
                            try {
                                const response = await fetch($el.action, {
                                    method: 'POST',
                                    headers: {
                                        'Accept': 'application/json',
                                        'X-Requested-With': 'XMLHttpRequest',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                        'Content-Type': 'application/json',
                                    },
                                    body: JSON.stringify({ new_last_working_day: y + '-' + m + '-' + d, reason: reason }),
                                });
                                const data = await response.json().catch(() => ({}));
                                if (!response.ok) {
                                    // A 422 validation failure (e.g. the picked date turned
                                    // out to be no longer later than the request's CURRENT
                                    // Last Working Day — this can genuinely happen if it was
                                    // changed elsewhere since the dialog opened) carries its
                                    // specific field message under `data.errors`, not
                                    // `data.message` (that's just Laravel's own generic
                                    // wrapper text) — prefer the first real field error
                                    // when present.
                                    const fieldError = data.errors ? Object.values(data.errors)[0]?.[0] : null;
                                    throw new Error(fieldError || data.message || 'Extending the Last Working Day failed. Please try again.');
                                }
                                return data;
                            } catch (error) {
                                // Surfaced INSIDE the still-open dialog (SweetAlert2's own
                                // validation-message banner) rather than a separate error
                                // popup — the date/reason the admin already entered stay
                                // exactly as they were, ready to correct and retry.
                                Swal.showValidationMessage(error.message);
                                return false;
                            } finally {
                                extending = false;
                            }
                        }
                    }).then((result) => {
                        if (!result.isConfirmed) return;
                        const data = result.value;
                        // Reactive update — no page reload. The card's own state
                        // (Last Working Day text, whether Extend Due stays visible,
                        // and the data a SUBSEQUENT click of it would show) all come
                        // straight from this response, matching
                        // `OffboardeeController::index()`'s own fields exactly — and
                        // since the Offboarding Status/Timeline modal reads this SAME
                        // card's reactive state the next time it's opened (see its own
                        // `open-offboardee-modal` dispatch above), both tabs pick up
                        // the fresh Last Working Day with no separate wiring needed.
                        // `timeline` in particular is what makes each checklist's OWN
                        // due date and the new extension activity entries themselves
                        // show up reactively — every other field above only ever
                        // refreshed the pinned header summary, never the tab content.
                        if (data.status !== undefined) status = data.status;
                        if (data.lastWorkingDay !== undefined) lastWorkingDay = data.lastWorkingDay;
                        if (data.originalLastWorkingDay !== undefined) originalLastWorkingDay = data.originalLastWorkingDay;
                        if (data.isLastWorkingDayExtended !== undefined) isLastWorkingDayExtended = data.isLastWorkingDayExtended;
                        if (data.canBulkExtendDue !== undefined) canBulkExtendDue = data.canBulkExtendDue;
                        if (data.canRetractOffboarding !== undefined) canRetractOffboarding = data.canRetractOffboarding;
                        if (data.extendDueChecklists !== undefined) extendDueChecklists = data.extendDueChecklists;
                        if (data.extendDueMinSelectableDateIso !== undefined) extendDueMinSelectableDateIso = data.extendDueMinSelectableDateIso;
                        if (data.timeline !== undefined) timeline = data.timeline;
                        Swal.fire({
                            icon: 'success',
                            title: 'Last Working Day Extended',
                            text: data.message || 'Last Working Day extended and checklist due dates recalculated.',
                            confirmButtonColor: '#145a3a',
                        });
                    });
                ">
                @csrf
                <button type="submit" :disabled="extending"
                    class="{{ $compact
                        ? 'inline-flex items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-3.5 py-2 text-sm font-medium text-gray-700 transition-colors hover:border-[#145a3a] hover:bg-[#145a3a] hover:text-white disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:text-gray-300 dark:hover:border-[#3aa876] dark:hover:bg-[#3aa876] dark:hover:text-white'
                        : 'flex w-full items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700 transition-colors hover:border-[#145a3a] hover:bg-[#145a3a] hover:text-white disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:text-gray-300 dark:hover:border-[#3aa876] dark:hover:bg-[#3aa876] dark:hover:text-white' }}">
                    <svg x-show="!extending" width="{{ $compact ? 16 : 14 }}" height="{{ $compact ? 16 : 14 }}" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M10 5.83334V10L12.9167 12.9167" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M17.5 10C17.5 14.1421 14.1421 17.5 10 17.5C5.85786 17.5 2.5 14.1421 2.5 10C2.5 5.85786 5.85786 2.5 10 2.5C14.1421 2.5 17.5 5.85786 17.5 10Z" stroke="currentColor" stroke-width="1.5" />
                    </svg>
                    <svg x-show="extending" x-cloak class="{{ $compact ? 'h-4 w-4' : 'h-3.5 w-3.5' }} animate-spin" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    <span x-text="extending ? 'Extending...' : (@js($compact) ? 'Extend' : 'Extend Last Working Day')"></span>
                </button>
            </form>
        </div>
    @endif
@endcan
