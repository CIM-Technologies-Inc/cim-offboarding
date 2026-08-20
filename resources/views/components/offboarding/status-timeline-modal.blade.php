@props(['initial' => null])

<div x-data="{
        selected: @js($initial),
        csrfToken: document.querySelector('meta[name=csrf-token]').content,
        isAdmin: @js(auth()->user()?->isAdmin() ?? false),
        activeTab: 'status',
        richSteps() {
            return this.selected?.timeline?.filter((step) => step.rich) ?? [];
        },
    }"
    @open-offboardee-modal.window="selected = $event.detail; activeTab = 'status'">
    <x-ui.modal x-data="{ open: false }" @open-offboardee-modal.window="open = true" :isOpen="$initial !== null" class="max-w-[600px]">
        <div class="relative flex max-h-[85vh] w-full max-w-[600px] flex-col rounded-3xl bg-white dark:bg-gray-900" x-show="selected" x-cloak>
            <template x-if="selected">
                <div class="flex min-h-0 flex-1 flex-col">
                    <!-- Pinned header: stays visible while the timeline below scrolls -->
                    <div class="shrink-0 p-6 pb-0 lg:p-8 lg:pb-0">
                        <div class="flex items-start justify-between pr-8">
                            <div>
                                <h4 class="text-xl font-semibold text-gray-800 dark:text-white/90" x-text="selected.name"></h4>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    <span x-text="selected.designation"></span> &middot; <span x-text="selected.department"></span>
                                </p>
                            </div>
                        </div>

                        <div class="mt-4 flex items-center gap-4 rounded-xl bg-gray-50 px-4 py-3 text-sm dark:bg-white/[0.03]">
                            <div>
                                <p class="text-xs text-gray-400">Employee Code</p>
                                <p class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.employeeCode"></p>
                            </div>
                            <div class="h-8 w-px bg-gray-200 dark:bg-gray-700"></div>
                            <div>
                                <p class="text-xs text-gray-400">Last Working Day</p>
                                <p class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.lastWorkingDay || '—'"></p>
                            </div>
                            <template x-if="selected.immediateHead">
                                <div class="contents">
                                    <div class="h-8 w-px bg-gray-200 dark:bg-gray-700"></div>
                                    <div>
                                        <p class="text-xs text-gray-400">Immediate Head</p>
                                        <p class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.immediateHead"></p>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <template x-if="selected.checklistTemplates && selected.checklistTemplates.length">
                            <div class="mt-4">
                                <p class="mb-1.5 text-xs font-medium text-gray-400">Clearance Checklist(s)</p>
                                <div class="flex flex-wrap gap-2">
                                    <template x-for="checklistName in selected.checklistTemplates" :key="checklistName">
                                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300" x-text="checklistName"></span>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <div class="mt-7 flex items-center gap-6 border-b border-gray-200 dark:border-gray-800">
                            <button type="button" @click="activeTab = 'status'"
                                class="border-b-2 pb-3 text-sm font-medium transition-colors"
                                :class="activeTab === 'status'
                                    ? 'border-[#145a3a] text-[#145a3a] dark:border-[#3aa876] dark:text-[#3aa876]'
                                    : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'">
                                Offboarding Status
                            </button>
                            <button type="button" @click="activeTab = 'timeline'"
                                class="border-b-2 pb-3 text-sm font-medium transition-colors"
                                :class="activeTab === 'timeline'
                                    ? 'border-[#145a3a] text-[#145a3a] dark:border-[#3aa876] dark:text-[#3aa876]'
                                    : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'">
                                Timeline
                            </button>
                        </div>
                    </div>

                    <!-- Scrollable content area: only this region scrolls, both tabs share the same scroll container -->
                    <div class="custom-scrollbar min-h-0 flex-1 overflow-y-auto px-6 pb-6 lg:px-8 lg:pb-8">

                    <!-- TAB 1: Offboarding Status — one card per checklist, each showing its own
                         details/status plus that checklist's own movement/history (assigned,
                         due, first viewed, cleared/declined, delegation, reminders). Default tab. -->
                    <div x-show="activeTab === 'status'" x-cloak>
                        <template x-if="richSteps().length === 0">
                            <p class="py-8 text-center text-sm text-gray-400">No checklists assigned yet.</p>
                        </template>
                        <div class="space-y-4">
                            <template x-for="(step, index) in richSteps()" :key="index">
                                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <p class="text-sm font-semibold text-gray-800 dark:text-white/90" x-text="step.department || 'Department'"></p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">Approver: <span x-text="step.approverName"></span></p>
                                        </div>
                                        <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium"
                                            :class="{
                                                'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300': step.status === 'pending',
                                                'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400': step.status === 'viewed',
                                                'bg-[#145a3a]/10 text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]': step.status === 'approved',
                                                'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400': step.status === 'declined'
                                            }"
                                            x-text="step.status === 'approved' ? 'Cleared' : (step.status.charAt(0).toUpperCase() + step.status.slice(1))"></span>
                                    </div>

                                    <div class="mt-2 space-y-1 text-xs text-gray-500 dark:text-gray-400">
                                        <p>Date Assigned: <span x-text="step.assignedAt || '—'"></span></p>
                                        <template x-if="step.dueAt">
                                            <p :class="step.isOverdue ? 'font-medium text-error-600 dark:text-error-400' : ''">
                                                Due: <span x-text="step.dueAt"></span>
                                                <span x-show="step.isOverdue"> — Overdue</span>
                                            </p>
                                        </template>
                                        <p>First Viewed: <span x-text="step.firstViewedAt || 'Not viewed yet'"></span></p>
                                        <template x-if="step.status === 'approved'">
                                            <p>Cleared: <span x-text="step.approvedAt"></span></p>
                                        </template>
                                        <template x-if="step.status === 'declined'">
                                            <p>Declined: <span x-text="step.declinedAt"></span></p>
                                        </template>
                                        <template x-if="step.declineReason">
                                            <p>Reason: <span x-text="step.declineReason"></span></p>
                                        </template>
                                    </div>

                                    @include('components.offboarding.partials.checklist-item-status-list')

                                    <template x-if="step.delegatedTo">
                                        <div class="mt-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs dark:border-gray-800 dark:bg-white/[0.03]">
                                            <p class="text-gray-500 dark:text-gray-400">
                                                Assigned To: <span class="font-medium text-gray-700 dark:text-gray-300" x-text="step.delegatedTo + ' (' + step.delegatedToCode + ')'"></span>
                                            </p>
                                            <p class="mt-0.5 text-gray-500 dark:text-gray-400">
                                                Delegated Approver Status:
                                                <span class="font-medium capitalize text-gray-700 dark:text-gray-300" x-text="step.delegationStatus === 'done' ? '✓ Done' : step.delegationStatus"></span>
                                            </p>
                                            <template x-if="step.delegateCompletedAt">
                                                <p class="mt-0.5 text-gray-500 dark:text-gray-400">
                                                    Completed: <span class="font-medium text-gray-700 dark:text-gray-300" x-text="step.delegateCompletedAt"></span>
                                                </p>
                                            </template>
                                        </div>
                                    </template>

                                    <div class="mt-2 space-y-2">
                                        <template x-if="step.reminderSentAt">
                                            <p class="text-xs text-gray-400">Last reminder sent: <span x-text="step.reminderSentAt"></span></p>
                                        </template>
                                        <template x-if="step.canRemind">
                                            <form method="POST" :action="step.remindUrl" x-data="{ confirmed: false }"
                                                @submit="if (!confirmed) {
                                                    $event.preventDefault();
                                                    Swal.fire({
                                                        title: 'Send reminder to ' + step.approverName + '?',
                                                        icon: 'question',
                                                        showCancelButton: true,
                                                        confirmButtonText: 'Send Reminder',
                                                        confirmButtonColor: '#145a3a',
                                                        cancelButtonColor: '#6b7280',
                                                        reverseButtons: true
                                                    }).then((result) => {
                                                        if (result.isConfirmed) {
                                                            confirmed = true;
                                                            $el.requestSubmit();
                                                        }
                                                    });
                                                }">
                                                <input type="hidden" name="_token" :value="csrfToken" />
                                                <button type="submit" :disabled="confirmed"
                                                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                                    Notify Approver
                                                </button>
                                            </form>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- TAB 2: Timeline — the pre-existing full chronological timeline, unchanged. -->
                    <div x-show="activeTab === 'timeline'" x-cloak>
                    <div class="relative">
                        <template x-for="(step, index) in selected.timeline" :key="index">
                            <div class="relative flex gap-4 pb-7 last:pb-0">
                                <div class="absolute top-3 left-[11px] h-full w-px bg-gray-200 dark:bg-gray-700"
                                    x-show="index < selected.timeline.length - 1"></div>
                                <div class="relative z-10 flex h-6 w-6 shrink-0 items-center justify-center rounded-full"
                                    :class="step.rich
                                        ? (step.status === 'declined' ? 'bg-error-500' : step.status === 'approved' ? 'bg-[#145a3a]' : step.status === 'viewed' ? 'bg-blue-500' : 'bg-gray-200 dark:bg-gray-700')
                                        : (step.cancelled ? 'bg-error-500' : (step.hold ? 'bg-amber-500' : (step.done ? 'bg-[#145a3a]' : 'bg-gray-200 dark:bg-gray-700')))">
                                    <svg x-show="step.done" width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" clip-rule="evenodd" d="M13.4767 4.10714C13.7788 4.38292 13.8008 4.85162 13.5257 5.15436L6.83817 12.5211C6.69758 12.6759 6.49882 12.7644 6.29008 12.7644C6.08134 12.7644 5.88258 12.6759 5.74199 12.5211L2.47426 8.9211C2.19916 8.61836 2.22119 8.14966 2.52326 7.87388C2.82533 7.5981 3.29283 7.62018 3.56793 7.92292L6.29008 10.9184L12.4321 4.15582C12.7072 3.85308 13.1746 3.83137 13.4767 4.10714Z" fill="white" />
                                    </svg>
                                    <svg x-show="step.hold" width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M8 4.5V9M8 11.5H8.007" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </div>

                                <template x-if="!step.rich">
                                    <div class="pt-0.5">
                                        <p class="text-sm font-medium"
                                            :class="step.done ? 'text-gray-800 dark:text-white/90' : 'text-gray-400 dark:text-gray-500'"
                                            x-text="step.label"></p>
                                        <template x-if="step.comment">
                                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                                Reason: <span x-text="step.comment"></span>
                                            </p>
                                        </template>
                                        <p class="text-xs text-gray-400" x-text="step.date || 'Not yet reached'"></p>
                                    </div>
                                </template>

                                <template x-if="step.rich">
                                    <div class="flex-1 pt-0.5">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div>
                                                <p class="text-sm font-semibold text-gray-800 dark:text-white/90" x-text="step.department || 'Department'"></p>
                                                <p class="text-xs text-gray-500 dark:text-gray-400">Approver: <span x-text="step.approverName"></span></p>
                                            </div>
                                            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium"
                                                :class="{
                                                    'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300': step.status === 'pending',
                                                    'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400': step.status === 'viewed',
                                                    'bg-[#145a3a]/10 text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]': step.status === 'approved',
                                                    'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400': step.status === 'declined'
                                                }"
                                                x-text="step.status === 'approved' ? 'Cleared' : (step.status.charAt(0).toUpperCase() + step.status.slice(1))"></span>
                                        </div>

                                        <div class="mt-2 space-y-1 text-xs text-gray-500 dark:text-gray-400">
                                            <p>Date Assigned: <span x-text="step.assignedAt || '—'"></span></p>
                                            <template x-if="step.dueAt">
                                                <p :class="step.isOverdue ? 'font-medium text-error-600 dark:text-error-400' : ''">
                                                    Due: <span x-text="step.dueAt"></span>
                                                    <span x-show="step.isOverdue"> — Overdue</span>
                                                </p>
                                            </template>
                                            <p>First Viewed: <span x-text="step.firstViewedAt || 'Not viewed yet'"></span></p>
                                            <template x-if="step.status === 'approved'">
                                                <p>Cleared: <span x-text="step.approvedAt"></span></p>
                                            </template>
                                            <template x-if="step.status === 'declined'">
                                                <p>Declined: <span x-text="step.declinedAt"></span></p>
                                            </template>
                                            <template x-if="step.declineReason">
                                                <p>Reason: <span x-text="step.declineReason"></span></p>
                                            </template>
                                        </div>

                                        @include('components.offboarding.partials.checklist-item-status-list')

                                        <template x-if="step.delegatedTo">
                                            <div class="mt-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs dark:border-gray-800 dark:bg-white/[0.03]">
                                                <p class="text-gray-500 dark:text-gray-400">
                                                    Assigned To: <span class="font-medium text-gray-700 dark:text-gray-300" x-text="step.delegatedTo + ' (' + step.delegatedToCode + ')'"></span>
                                                </p>
                                                <p class="mt-0.5 text-gray-500 dark:text-gray-400">
                                                    Delegated Approver Status:
                                                    <span class="font-medium capitalize text-gray-700 dark:text-gray-300" x-text="step.delegationStatus === 'done' ? '✓ Done' : step.delegationStatus"></span>
                                                </p>
                                                <template x-if="step.delegateCompletedAt">
                                                    <p class="mt-0.5 text-gray-500 dark:text-gray-400">
                                                        Completed: <span class="font-medium text-gray-700 dark:text-gray-300" x-text="step.delegateCompletedAt"></span>
                                                    </p>
                                                </template>
                                            </div>
                                        </template>

                                        <div class="mt-2 space-y-2">
                                            <template x-if="step.reminderSentAt">
                                                <p class="text-xs text-gray-400">Last reminder sent: <span x-text="step.reminderSentAt"></span></p>
                                            </template>
                                            <template x-if="step.canRemind">
                                                <form method="POST" :action="step.remindUrl" x-data="{ confirmed: false }"
                                                    @submit="if (!confirmed) {
                                                        $event.preventDefault();
                                                        Swal.fire({
                                                            title: 'Send reminder to ' + step.approverName + '?',
                                                            icon: 'question',
                                                            showCancelButton: true,
                                                            confirmButtonText: 'Send Reminder',
                                                            confirmButtonColor: '#145a3a',
                                                            cancelButtonColor: '#6b7280',
                                                            reverseButtons: true
                                                        }).then((result) => {
                                                            if (result.isConfirmed) {
                                                                confirmed = true;
                                                                $el.requestSubmit();
                                                            }
                                                        });
                                                    }">
                                                    <input type="hidden" name="_token" :value="csrfToken" />
                                                    <button type="submit" :disabled="confirmed"
                                                        class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                                        Notify Approver
                                                    </button>
                                                </form>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                    </div>
                    </div>

                    <!-- Pinned footer: Generate/Print/Close stay visible regardless of scroll position -->
                    <div class="shrink-0 border-t border-gray-100 p-6 dark:border-gray-800 lg:px-8 lg:py-6">
                    <div class="flex flex-wrap items-center justify-end gap-3">
                        <template x-if="(isAdmin || selected.status === 'completed') && selected.clearanceFormUrl">
                            <a :href="selected.clearanceFormUrl" target="_blank" rel="noopener"
                                class="flex items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                <svg width="16" height="16" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M4.5 12V15.75C4.5 16.1642 4.83579 16.5 5.25 16.5H12.75C13.1642 16.5 13.5 16.1642 13.5 15.75V12M9 1.5V11.25M9 11.25L5.625 7.875M9 11.25L12.375 7.875" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                Generate Clearance Form
                            </a>
                        </template>
                        <template x-if="(isAdmin || selected.status === 'completed') && selected.printClearanceFormUrl">
                            <a :href="selected.printClearanceFormUrl" target="_blank" rel="noopener"
                                class="flex items-center justify-center gap-1.5 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630]">
                                <svg width="16" height="16" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M4.5 6.75V2.25H13.5V6.75M4.5 14.25H3C2.17157 14.25 1.5 13.5784 1.5 12.75V8.25C1.5 7.42157 2.17157 6.75 3 6.75H15C15.8284 6.75 16.5 7.42157 16.5 8.25V12.75C16.5 13.5784 15.8284 14.25 15 14.25H13.5M4.5 14.25V16.5H13.5V14.25M4.5 14.25H13.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                Print Clearance Form
                            </a>
                        </template>
                        <button @click="open = false" type="button"
                            class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                            Close
                        </button>
                    </div>
                    </div>
                </div>
            </template>
        </div>
    </x-ui.modal>
</div>
