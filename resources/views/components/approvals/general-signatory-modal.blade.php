<div x-data="{
        selected: null,
        processing: false,
        remarksText: '',
        declining: false,
        declineReasonDraft: '',
        setSelected(detail) {
            this.processing = false;
            this.declining = false;
            this.selected = detail;
            // Reset for every newly opened request — remarks (and any
            // still-unsaved decline reason draft) are entered fresh per
            // request, never carried over from whichever one this General
            // Signatory last had open.
            this.remarksText = '';
            this.declineReasonDraft = '';
        },
        // Same badge color convention as the Approvals page's own
        // `$statusBadges` map (index.blade.php) and the Offboardee page,
        // applied here to `requestStatus` — the offboarding request's real
        // overall processing status — so a General Signatory sees the
        // identical color/label the Admin/HR pages already use.
        requestStatusClass() {
            return {
                'bg-yellow-50 text-yellow-700 dark:bg-yellow-500/15 dark:text-yellow-400': this.selected?.requestStatus === 'pending',
                'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400': this.selected?.requestStatus === 'in_progress',
                'bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400': this.selected?.requestStatus === 'overdue',
                'bg-[#145a3a]/10 text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]': this.selected?.requestStatus === 'completed',
                'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400': ['declined', 'cancelled'].includes(this.selected?.requestStatus),
            };
        },
        // The On Hold counterpart to a card removal — a held General
        // Signatory approval must STAY on the queue, so instead of
        // removing the card this patches its status badge in place, same
        // data-card-id targeting convention as checklist-modal.blade.php's
        // own patchListCardBadge(). Matches the [data-status-badge] span
        // pages/approvals/index.blade.php renders per card from its own
        // $statusBadges map.
        patchListCardBadge(cardId, badgeClass, badgeLabel) {
            if (!cardId) {
                return;
            }
            const badge = document.querySelector(`[data-card-id='${CSS.escape(String(cardId))}'] [data-status-badge]`);
            if (!badge) {
                return;
            }
            badge.className = `rounded-full px-2.5 py-1 text-xs font-medium ${badgeClass}`;
            badge.textContent = badgeLabel;
        },
        // The card behind this modal is otherwise server-rendered — its
        // click handler dispatches a static JSON snapshot baked into the
        // page at render time (see pages/approvals/index.blade.php's own
        // matching comment), so mutating `this.selected` in place only
        // ever fixes the CURRENTLY open modal. Persisting the patch here,
        // in the same plain global cache checklist-modal.blade.php's own
        // rememberCardOverride() uses, keeps a reopen of the SAME card
        // correct too, without ever reloading the whole page.
        rememberCardOverride(cardId, patch) {
            if (!cardId) {
                return;
            }
            window.__approvalCardOverrides = window.__approvalCardOverrides || {};
            window.__approvalCardOverrides[cardId] = Object.assign({}, window.__approvalCardOverrides[cardId] || {}, patch);
        },
        // Declining now places this General Signatory's approval ON HOLD
        // (see GeneralSignatoryApprovalController::decline()) — it does NOT
        // resolve it, no signature is recorded, and it stays on this
        // signatory's own queue (never removed — see removeHold() below
        // for the only way out). Same mandatory-reason confirm +
        // fetch()-based flow as the checklist modal's own
        // declineChecklist() — patched in place on success, modal stays
        // open, no reload.
        declineGeneralSignatory() {
            if (this.declining || !this.selected?.declineUrl) {
                return;
            }
            Swal.fire({
                title: 'Decline This Offboarding Request?',
                text: 'Your assigned clearance for this offboarding request will be placed ON HOLD and cannot proceed — Submit and further decline actions will be disabled until you remove the hold. Please provide a reason.',
                icon: 'warning',
                input: 'textarea',
                inputLabel: 'Reason for Declining',
                inputPlaceholder: 'Explain why this offboarding request is being declined...',
                inputValue: this.declineReasonDraft,
                inputValidator: (value) => {
                    if (!value || !value.trim()) {
                        return 'A reason is required to decline this offboarding request.';
                    }
                },
                showCancelButton: true,
                confirmButtonText: 'Yes, Decline',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                reverseButtons: true,
            }).then((result) => {
                if (!result.isConfirmed) {
                    return;
                }

                this.declining = true;
                this.declineReasonDraft = (result.value || '').trim();

                const targetItem = this.selected;
                const formData = new FormData();
                formData.append('reason', this.declineReasonDraft);

                window.fetchWithTimeout(targetItem.declineUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: formData,
                }).then(async (res) => {
                    const data = await res.json();

                    if (!res.ok) {
                        throw new Error(data.message || 'request failed');
                    }

                    this.declining = false;

                    // Patched in place — modal stays open, no reload.
                    // rememberCardOverride()/patchListCardBadge() keep a
                    // later reopen of this same card, and its badge on the
                    // list, correct too (see those methods' own
                    // docblocks).
                    targetItem.isOnHold = true;
                    targetItem.declinedAt = data.declinedAt;
                    targetItem.declineReason = this.declineReasonDraft;
                    this.rememberCardOverride(targetItem.id, {
                        isOnHold: true,
                        declinedAt: targetItem.declinedAt,
                        declineReason: targetItem.declineReason,
                    });
                    this.patchListCardBadge(targetItem.id, 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400', 'On Hold');

                    // Only cleared on genuine success, and only after the
                    // patch above already read it — a failed attempt (see
                    // .catch below) deliberately leaves this alone so the
                    // next Decline click re-opens pre-filled with what was
                    // already typed.
                    this.declineReasonDraft = '';

                    window.Swal?.fire({
                        icon: 'success',
                        title: 'Placed On Hold',
                        text: 'This offboarding request has been placed on hold and the reason has been recorded. It will stay unavailable until you remove the hold.',
                        confirmButtonColor: '#145a3a',
                    });
                }).catch((e) => {
                    this.declining = false;
                    Swal.fire({
                        icon: 'error',
                        title: 'Failed to Decline',
                        text: e?.name === 'AbortError'
                            ? 'This is taking longer than expected. Please check before trying again.'
                            : (e?.message || 'This offboarding request could not be declined. Please try again.'),
                        confirmButtonColor: '#145a3a',
                    });
                });
            });
        },
        // The only way out of On Hold (see declineGeneralSignatory() above)
        // — the SAME General Signatory reviews and confirms, with its own
        // mandatory reason, independent of the original decline reason.
        // Reloads on success, same reasoning as `checklist-modal.blade.php`'s
        // own removeHold(): a rare, deliberate action, so re-deriving every
        // affected field's correct value client-side isn't worth it.
        removingHold: false,
        onHoldRemovalReasonDraft: '',
        removeHold() {
            if (this.removingHold || !this.selected?.onHoldRemoveUrl) {
                return;
            }
            Swal.fire({
                title: 'Remove On Hold?',
                text: 'This offboarding request will become available for review and action again. Please provide a reason.',
                icon: 'question',
                input: 'textarea',
                inputLabel: 'Reason for Removing the Hold',
                inputPlaceholder: 'Explain why this hold is being removed...',
                inputValue: this.onHoldRemovalReasonDraft,
                inputValidator: (value) => {
                    if (!value || !value.trim()) {
                        return 'A reason is required to remove this hold.';
                    }
                },
                showCancelButton: true,
                confirmButtonText: 'Yes, Remove Hold',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#145a3a',
                cancelButtonColor: '#6b7280',
                reverseButtons: true,
            }).then((result) => {
                if (!result.isConfirmed) {
                    return;
                }

                this.removingHold = true;
                this.onHoldRemovalReasonDraft = (result.value || '').trim();

                const formData = new FormData();
                formData.append('reason', this.onHoldRemovalReasonDraft);

                window.fetchWithTimeout(this.selected.onHoldRemoveUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: formData,
                }).then(async (res) => {
                    const data = await res.json();

                    if (!res.ok) {
                        throw new Error(data.message || 'request failed');
                    }

                    window.Swal?.fire({
                        icon: 'success',
                        title: 'Hold Removed',
                        text: 'This offboarding request is available for review again. Reloading...',
                        confirmButtonColor: '#145a3a',
                        timer: 1500,
                        showConfirmButton: false,
                    }).then(() => window.location.reload());
                }).catch((e) => {
                    this.removingHold = false;
                    Swal.fire({
                        icon: 'error',
                        title: 'Failed to Remove Hold',
                        text: e?.name === 'AbortError'
                            ? 'This is taking longer than expected. Please check before trying again.'
                            : (e?.message || 'The hold could not be removed. Please try again.'),
                        confirmButtonColor: '#145a3a',
                    });
                });
            });
        },
        // Live 5-days-before / on-or-after Clearance Signing Due Date
        // urgency — same reasoning and thresholds as checklist-modal.blade.php's
        // own clearanceSigningUrgency()/nowTick, kept as an independent
        // copy since this component has no shared JS module to pull it
        // from.
        nowTick: Date.now(),
        init() {
            setInterval(() => {
                this.nowTick = Date.now();
            }, 60000);
        },
        clearanceSigningUrgency(iso) {
            if (!iso) {
                return 'none';
            }
            const due = new Date(iso).getTime();
            if (Number.isNaN(due)) {
                return 'none';
            }
            if (this.nowTick >= due) {
                return 'danger';
            }
            if (due - this.nowTick <= 5 * 24 * 60 * 60 * 1000) {
                return 'warning';
            }
            return 'none';
        },
    }" @open-general-signatory-modal.window="setSelected($event.detail)">
    <x-ui.modal x-data="{ open: false }" @open-general-signatory-modal.window="open = true" @close-general-signatory-modal.window="open = false" :isOpen="false" class="w-full sm:max-w-[480px]">
        <div class="no-scrollbar relative max-h-[85vh] w-full overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="selected" x-cloak>
            <template x-if="selected">
                <div>
                    <div class="mb-1 flex items-start justify-between gap-3">
                        <h4 class="text-xl font-semibold text-gray-800 dark:text-white/90" x-text="selected.name"></h4>
                        <!-- <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium" :class="requestStatusClass()" x-text="selected.requestStatusLabel"></span> -->
                    </div>

                    <!-- On Hold banner — shown from page load (selected.isOnHold, computed
                         server-side off this assignment's actual `status`) or the instant a
                         decline succeeds (patched in place by declineGeneralSignatory() above,
                         no reload needed). Submit/Decline stay disabled until the assigned
                         General Signatory clicks Remove On Hold below. -->
                    <template x-if="selected.isOnHold">
                        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm dark:border-amber-500/30 dark:bg-amber-500/10">
                            <p class="font-medium text-amber-700 dark:text-amber-400">This is On Hold.</p>
                            <p class="mt-1 text-amber-600 dark:text-amber-400" x-show="selected.declinedAt">
                                Declined: <span x-text="selected.declinedAt"></span>
                            </p>
                            <p class="mt-1 text-amber-600 dark:text-amber-400" x-show="selected.declineReason">
                                Reason: <span x-text="selected.declineReason"></span>
                            </p>
                            <button type="button" @click="removeHold()" :disabled="removingHold"
                                :class="removingHold ? 'opacity-70 cursor-not-allowed' : 'hover:bg-amber-100 dark:hover:bg-amber-500/20'"
                                class="mt-3 flex items-center justify-center gap-1.5 rounded-lg border border-amber-500 px-4 py-2 text-sm font-medium text-amber-700 dark:border-amber-400 dark:text-amber-400">
                                <span x-show="removingHold" class="h-4 w-4 animate-spin rounded-full border-2 border-solid border-amber-600 border-t-transparent dark:border-amber-400"></span>
                                <span x-text="removingHold ? 'Removing Hold...' : 'Remove On Hold'"></span>
                            </button>
                        </div>
                    </template>
                    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400">
                        You are assigned as a General Signatory (Clearance Signatory) on this offboarding request.
                    </p>

                    {{-- Status section: Offboardee details plus the request's
                         current overall processing status, so a General
                         Signatory sees this without navigating to the
                         Admin/HR Offboarding Status page. --}}
                    <div class="mb-5 space-y-1.5 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-xs dark:border-gray-800 dark:bg-white/[0.03]">
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Employee No.</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.employeeCode"></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Position</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.designation"></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Department</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.department"></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Separation Date</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.noticeDate || '—'"></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Last Working Day</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.lastWorkingDay"></span>
                        </div>
                        <template x-if="selected.clearanceSigningDueAt">
                            <div class="flex items-center justify-between"
                                :class="{
                                    'text-sm font-semibold text-orange-600 dark:text-orange-400': clearanceSigningUrgency(selected.clearanceSigningDueAtIso) === 'warning',
                                    'text-base font-bold text-error-600 dark:text-error-400': clearanceSigningUrgency(selected.clearanceSigningDueAtIso) === 'danger',
                                }">
                                <span :class="clearanceSigningUrgency(selected.clearanceSigningDueAtIso) === 'none' ? 'text-gray-400' : ''">Clearance Signing Due Date</span>
                                <span :class="clearanceSigningUrgency(selected.clearanceSigningDueAtIso) === 'none' ? 'font-medium text-gray-700 dark:text-gray-300' : ''">
                                    <span x-text="selected.clearanceSigningDueAt"></span>
                                    <span x-show="clearanceSigningUrgency(selected.clearanceSigningDueAtIso) === 'danger'"> — Overdue</span>
                                </span>
                            </div>
                        </template>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Current Offboarding Status</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.requestStatusLabel"></span>
                        </div>
                    </div>

                    <template x-if="selected.clearanceSigningDueAt">
                        <p class="mb-5 -mt-3 text-xs text-gray-500 dark:text-gray-400">
                            Please complete and approve your assigned checklist on or before the displayed due date.
                        </p>
                    </template>

                    <template x-if="selected.generalSignatoryTasks && selected.generalSignatoryTasks.length">
                        <div class="mb-5">
                            <p class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">Task List</p>
                            <div class="max-h-56 space-y-2 overflow-y-auto pr-1">
                                <template x-for="(task, index) in selected.generalSignatoryTasks" :key="index">
                                    <div class="rounded-lg border border-gray-200 px-4 py-2.5 dark:border-gray-800">
                                        <span class="block text-sm font-medium text-gray-800 dark:text-white/90" x-text="task.title"></span>
                                        <span class="block text-xs text-gray-400" x-show="task.assigneeName"
                                            x-text="'Assignee: ' + task.assigneeName"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                    <template x-if="!selected.generalSignatoryTasks || !selected.generalSignatoryTasks.length">
                        <p class="mb-5 rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-500 dark:bg-white/[0.03] dark:text-gray-400">
                            No task list is configured for this General Signatory — only your clearance approval is required.
                        </p>
                    </template>

                    <form method="POST" :action="selected.submitUrl" @submit="processing = true">
                        @csrf
                        <template x-if="!selected.isOnHold">
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Remarks <span class="font-normal text-gray-400">(optional)</span>
                                </label>
                                <textarea name="remarks" x-model="remarksText" rows="3" placeholder="Add any remarks before approving..."
                                    class="dark:bg-dark-900 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30"></textarea>
                            </div>
                        </template>
                        <div class="mt-4 flex flex-wrap items-center justify-end gap-3">
                            <button @click="open = false" type="button"
                                class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                                Cancel
                            </button>
                            <!-- Decline: places this approval On Hold — no signature required (a pause,
                                 not a final decision) but a mandatory reason via the confirm dialog.
                                 Hides once already on hold — see selected.isOnHold. -->
                            <template x-if="!selected.isOnHold">
                                <button type="button" @click="declineGeneralSignatory()" :disabled="declining"
                                    :class="declining ? 'opacity-70 cursor-not-allowed' : 'hover:bg-red-50 dark:hover:bg-red-500/10'"
                                    class="flex items-center justify-center gap-1.5 rounded-lg border border-red-500 px-4 py-2.5 text-sm font-medium text-red-600 dark:border-red-400 dark:text-red-400">
                                    <span x-show="declining" class="h-4 w-4 animate-spin rounded-full border-2 border-solid border-red-600 border-t-transparent dark:border-red-400"></span>
                                    <span x-text="declining ? 'Declining...' : 'Decline'"></span>
                                </button>
                            </template>
                            <template x-if="!selected.isOnHold">
                                <button type="submit" :disabled="processing" data-turbo-submits-with="Submitting..."
                                    :class="processing ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#0f4630]'"
                                    class="flex items-center justify-center gap-1.5 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white">
                                    Submit
                                </button>
                            </template>
                        </div>
                    </form>
                </div>
            </template>
        </div>
    </x-ui.modal>
</div>
