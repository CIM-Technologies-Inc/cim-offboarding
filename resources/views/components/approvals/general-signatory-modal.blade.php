<div x-data="{
        selected: null,
        processing: false,
        remarksText: '',
        setSelected(detail) {
            this.processing = false;
            this.selected = detail;
            // Reset for every newly opened request — remarks are entered
            // fresh per approval, never carried over from whichever
            // request this General Signatory last submitted.
            this.remarksText = '';
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
    }" @open-general-signatory-modal.window="setSelected($event.detail)">
    <x-ui.modal x-data="{ open: false }" @open-general-signatory-modal.window="open = true" :isOpen="false" class="w-full sm:max-w-[480px]">
        <div class="no-scrollbar relative w-full overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="selected" x-cloak>
            <template x-if="selected">
                <div>
                    <div class="mb-1 flex items-start justify-between gap-3">
                        <h4 class="text-xl font-semibold text-gray-800 dark:text-white/90" x-text="selected.name"></h4>
                        <!-- <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium" :class="requestStatusClass()" x-text="selected.requestStatusLabel"></span> -->
                    </div>
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
                        <div class="flex items-center justify-between">
                            <span class="text-gray-400">Current Offboarding Status</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.requestStatusLabel"></span>
                        </div>
                    </div>

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
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                Remarks <span class="font-normal text-gray-400">(optional)</span>
                            </label>
                            <textarea name="remarks" x-model="remarksText" rows="3" placeholder="Add any remarks before approving..."
                                class="dark:bg-dark-900 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30"></textarea>
                        </div>
                        <div class="mt-4 flex items-center justify-end gap-3">
                            <button @click="open = false" type="button"
                                class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                                Cancel
                            </button>
                            <button type="submit" :disabled="processing" data-turbo-submits-with="Submitting..."
                                :class="processing ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#0f4630]'"
                                class="flex items-center justify-center gap-1.5 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white">
                                Submit
                            </button>
                        </div>
                    </form>
                </div>
            </template>
        </div>
    </x-ui.modal>
</div>
