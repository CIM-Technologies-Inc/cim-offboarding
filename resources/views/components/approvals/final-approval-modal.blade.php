<div x-data="{
        selected: null,
        processing: false,
        remarksText: '',
        setSelected(detail) {
            this.processing = false;
            this.selected = detail;
            // Reset for every newly opened request — remarks are entered
            // fresh per approval, never carried over from whichever
            // request this Final Approver last submitted.
            this.remarksText = '';
        },
    }" @open-final-approval-modal.window="setSelected($event.detail)">
    <x-ui.modal x-data="{ open: false }" @open-final-approval-modal.window="open = true" :isOpen="false" class="w-full sm:max-w-[560px]">
        <div class="no-scrollbar relative max-h-[85vh] w-full overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="selected" x-cloak>
            <template x-if="selected">
                <div>
                    <h4 class="mb-1 text-xl font-semibold text-gray-800 dark:text-white/90" x-text="selected.name"></h4>
                    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400">
                        You are the configured Final Approver for this offboarding request. Every checklist and clearance requirement has already been completed — review the details below, then give Final Approval.
                    </p>

                    {{-- Requirement #3's field list: employee identity, separation
                         details, Last Working Day (original + extended, if
                         applicable), and the request's overall status. --}}
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
                            <span class="text-gray-400">Separation Type</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.reason || '—'"></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Resignation Date</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.noticeDate || '—'"></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400" x-text="selected.isLastWorkingDayExtended ? 'Original Last Working Day' : 'Last Working Day'"></span>
                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.isLastWorkingDayExtended ? selected.originalLastWorkingDay : selected.lastWorkingDay"></span>
                        </div>
                        <template x-if="selected.isLastWorkingDayExtended">
                            <div class="flex items-center justify-between">
                                <span class="text-gray-400">Extended Last Working Day</span>
                                <span class="font-medium text-[#145a3a] dark:text-[#3aa876]" x-text="selected.lastWorkingDay"></span>
                            </div>
                        </template>
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Current Offboarding Status</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.requestStatusLabel"></span>
                        </div>
                    </div>

                    {{-- Checklist completion/clearance status + supporting documents
                         — the Clearance Form itself IS that summary (every
                         checklist and signatory sign-off it lists is already
                         complete by the time a request reaches Final Approval). --}}
                    <div class="mb-5">
                        <p class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">Supporting Documents</p>
                        <div class="flex flex-wrap gap-2">
                            <a :href="selected.clearanceFormUrl" target="_blank" rel="noopener"
                                class="flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                View Clearance Form
                            </a>
                            <a :href="selected.printClearanceFormUrl" target="_blank" rel="noopener"
                                class="flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                Print Clearance Form
                            </a>
                        </div>
                    </div>

                    {{-- Previous approval/signatory status — the same request
                         timeline every checklist card's modal already shows,
                         so the Final Approver can see what's already been
                         cleared without navigating elsewhere. --}}
                    <template x-if="selected.timeline && selected.timeline.length">
                        <div class="mb-5">
                            <p class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">Approval History</p>
                            <div class="max-h-56 space-y-2 overflow-y-auto pr-1">
                                <template x-for="(step, index) in selected.timeline" :key="index">
                                    <div class="flex items-center justify-between rounded-lg border border-gray-200 px-4 py-2.5 text-xs dark:border-gray-800">
                                        <span class="font-medium text-gray-700 dark:text-gray-300" x-text="step.label"></span>
                                        <span class="text-gray-400" x-text="step.date || (step.done ? 'Done' : 'Pending')"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>

                    <form method="POST" :action="selected.submitUrl" @submit="processing = true">
                        @csrf
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                Remarks <span class="font-normal text-gray-400">(optional)</span>
                            </label>
                            <textarea name="remarks" x-model="remarksText" rows="3" placeholder="Add any remarks before giving Final Approval..."
                                class="dark:bg-dark-900 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30"></textarea>
                        </div>
                        <div class="mt-4 flex flex-wrap items-center justify-end gap-3">
                            <button @click="open = false" type="button"
                                class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                                Cancel
                            </button>
                            <button type="submit" :disabled="processing" data-turbo-submits-with="Approving..."
                                :class="processing ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#0f4630]'"
                                class="flex items-center justify-center gap-1.5 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white">
                                Approve
                            </button>
                        </div>
                    </form>
                </div>
            </template>
        </div>
    </x-ui.modal>
</div>
