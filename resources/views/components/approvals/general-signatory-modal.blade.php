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
        // Declining is a COMPLETED signatory action (see
        // GeneralSignatoryApprovalController::decline()) — same
        // mandatory-reason confirm + fetch()-based flow as the checklist
        // modal's own declineChecklist(), so the modal never navigates away
        // on its own. State is patched into `selected` in place on success
        // (same convention every other action in this app follows) rather
        // than reloading — see the button's own :disabled/spinner and the
        // Declined banner below for how `declining`/`selected.isDeclined`
        // drive the loading/confirmed states.
        declineGeneralSignatory() {
            if (this.declining || !this.selected?.declineUrl) {
                return;
            }
            Swal.fire({
                title: 'Decline This Checklist?',
                text: 'This checklist will be declined. This does not stop the offboarding request — it will continue processing normally. Please provide a reason.',
                icon: 'warning',
                input: 'textarea',
                inputLabel: 'Reason for Declining',
                inputPlaceholder: 'Explain why this checklist is being declined...',
                inputValue: this.declineReasonDraft,
                inputValidator: (value) => {
                    if (!value || !value.trim()) {
                        return 'A reason is required to decline this checklist.';
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

                    targetItem.isDeclined = true;
                    targetItem.declinedAt = data.declinedAt;
                    targetItem.declineReason = this.declineReasonDraft;

                    // Only cleared on genuine success, and only after the
                    // patch above already read it — a failed attempt (see
                    // .catch below) deliberately leaves this alone so the
                    // next Decline click re-opens pre-filled with what was
                    // already typed.
                    this.declineReasonDraft = '';

                    window.Swal?.fire({
                        icon: 'success',
                        title: 'Checklist Declined Successfully',
                        text: 'The checklist has been successfully declined and the decline reason has been recorded.',
                        confirmButtonColor: '#145a3a',
                    });
                }).catch((e) => {
                    this.declining = false;
                    Swal.fire({
                        icon: 'error',
                        title: 'Failed to Decline Checklist',
                        text: e?.name === 'AbortError'
                            ? 'This is taking longer than expected. Please check before trying again.'
                            : (e?.message || 'The checklist could not be declined. Please try again.'),
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
    <x-ui.modal x-data="{ open: false }" @open-general-signatory-modal.window="open = true" :isOpen="false" class="w-full sm:max-w-[480px]">
        <div class="no-scrollbar relative w-full overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="selected" x-cloak>
            <template x-if="selected">
                <div>
                    <div class="mb-1 flex items-start justify-between gap-3">
                        <h4 class="text-xl font-semibold text-gray-800 dark:text-white/90" x-text="selected.name"></h4>
                        <!-- <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium" :class="requestStatusClass()" x-text="selected.requestStatusLabel"></span> -->
                    </div>

                    <!-- Declined banner — shown the instant a decline succeeds (patched in place by
                         declineGeneralSignatory() above, no reload needed). -->
                    <template x-if="selected.isDeclined">
                        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm dark:border-red-500/30 dark:bg-red-500/10">
                            <p class="font-medium text-red-700 dark:text-red-400">This checklist has been declined.</p>
                            <p class="mt-1 text-red-600 dark:text-red-400" x-show="selected.declinedAt">
                                Declined: <span x-text="selected.declinedAt"></span>
                            </p>
                            <p class="mt-1 text-red-600 dark:text-red-400" x-show="selected.declineReason">
                                Reason: <span x-text="selected.declineReason"></span>
                            </p>
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
                        <template x-if="!selected.isDeclined">
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Remarks <span class="font-normal text-gray-400">(optional)</span>
                                </label>
                                <textarea name="remarks" x-model="remarksText" rows="3" placeholder="Add any remarks before approving..."
                                    class="dark:bg-dark-900 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30"></textarea>
                            </div>
                        </template>
                        <div class="mt-4 flex items-center justify-end gap-3">
                            <button @click="open = false" type="button"
                                class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                                Cancel
                            </button>
                            <!-- Decline: a completed signatory action, not a stop — requires the same
                                 e-signature Submit does (validated server-side) and a mandatory reason via
                                 the confirm dialog. Hides once already declined — see selected.isDeclined. -->
                            <template x-if="!selected.isDeclined">
                                <button type="button" @click="declineGeneralSignatory()" :disabled="declining"
                                    :class="declining ? 'opacity-70 cursor-not-allowed' : 'hover:bg-red-50 dark:hover:bg-red-500/10'"
                                    class="flex items-center justify-center gap-1.5 rounded-lg border border-red-500 px-4 py-2.5 text-sm font-medium text-red-600 dark:border-red-400 dark:text-red-400">
                                    <span x-show="declining" class="h-4 w-4 animate-spin rounded-full border-2 border-solid border-red-600 border-t-transparent dark:border-red-400"></span>
                                    <span x-text="declining ? 'Declining...' : 'Decline'"></span>
                                </button>
                            </template>
                            <template x-if="!selected.isDeclined">
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
