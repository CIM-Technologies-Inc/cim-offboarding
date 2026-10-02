@props(['initial' => null, 'hideStatusTab' => false, 'emailTemplates' => []])

@php
    // Pages that don't need the reminder-template picker (Approvals,
    // Calendar) don't pass `emailTemplates` at all, leaving the plain-array
    // default above — `collect()` normalizes that to an empty Collection so
    // `firstWhere()` below always works regardless of caller.
    $emailTemplates = collect($emailTemplates);

    // Whichever template `ApprovalController::remind()` would use by
    // default for each of the two reminder kinds it sends — resolved here
    // from the already-loaded `$emailTemplates` list (no extra query),
    // purely to pre-select the right option in the per-step "Select Email
    // Template" picker below. Matches `ApprovalController::REMINDER_TEMPLATE`
    // / `OVERDUE_TEMPLATE` by name exactly.
    $defaultReminderTemplateId = optional($emailTemplates->firstWhere('template_name', 'Offboarding Reminder'))->id;
    $defaultOverdueTemplateId = optional($emailTemplates->firstWhere('template_name', 'Offboarding Overdue Notice'))->id;

    // Default template for a General Signatory's "Notify Approver" —
    // matches `GeneralSignatoryApprovalController::GENERAL_SIGNATORY_NOTIFICATION_TEMPLATE`
    // by name, same resolve-once-here convention as the two checklist
    // defaults above.
    $defaultGeneralSignatoryTemplateId = optional($emailTemplates->firstWhere('template_name', 'General Signatory Offboarding Notification'))->id;
@endphp

<div x-data="{
        selected: @js($initial),
        csrfToken: document.querySelector('meta[name=csrf-token]').content,
        isAdmin: @js(auth()->user()?->isAdmin() ?? false),
        hideStatusTab: @js($hideStatusTab),
        activeTab: '{{ $hideStatusTab ? 'timeline' : 'status' }}',
        emailTemplates: @js($emailTemplates),
        defaultReminderTemplateId: @js($defaultReminderTemplateId),
        defaultOverdueTemplateId: @js($defaultOverdueTemplateId),
        defaultGeneralSignatoryTemplateId: @js($defaultGeneralSignatoryTemplateId),
        richSteps() {
            const steps = this.selected?.timeline?.filter((step) => step.rich) ?? [];
            // The Final Pay Checklist sorts to the top of this (Status tab
            // only) list once it's attached/available — a stable sort, so
            // every other checklist keeps its existing relative order, and
            // this never touches the Timeline tab's own true chronological
            // ordering (that tab reads selected.timeline directly, not
            // through this method).
            return [...steps].sort((a, b) => (b.isFinalPayChecklist ? 1 : 0) - (a.isFinalPayChecklist ? 1 : 0));
        },
        // The template a step's reminder would use if the admin never
        // touches the picker — same before/after-due-date split
        // `ApprovalController::remind()` itself makes server-side for a
        // checklist approver, or the fixed General Signatory notification
        // template for a General Signatory step — so the pre-selected
        // option always matches what would actually be sent either way.
        defaultTemplateIdFor(step) {
            if (step.isGeneralSignatory) {
                return this.defaultGeneralSignatoryTemplateId;
            }
            return step.isOverdue ? this.defaultOverdueTemplateId : this.defaultReminderTemplateId;
        },
        templateNameFor(id) {
            return this.emailTemplates.find((t) => t.id === Number(id))?.template_name ?? '';
        },
        // Generate/Print Clearance Form open the PDF in a NEW tab (plain
        // target=_blank links, not a form submit or fetch()) — this tab
        // never receives any done signal from that navigation, so there's
        // no real response to wait for. A brief, timed disable + spinner
        // still gives the same processing-please-wait feedback the rest
        // of the app shows, and stops a rapid double-click from opening the
        // same document in two tabs at once.
        generatingClearanceForm: false,
        printingClearanceForm: false,
        briefLoadingState(prop) {
            this[prop] = true;
            setTimeout(() => { this[prop] = false; }, 2500);
        },
        // Bumped every time this modal opens (see the event handler right
        // below) — folded into the checklist accordion's own :key below so
        // Alpine always tears down and recreates every panel's own open/
        // collapsed scope on open, rather than reusing a previous panel's
        // DOM node (and its already-toggled state) just because it happens
        // to land on the same array index. Without this, reopening the
        // SAME request — or opening a DIFFERENT one with the same
        // checklist count — would silently keep whichever panels were
        // left expanded from the last time this modal was open.
        openGeneration: 0,
    }"
    @open-offboardee-modal.window="selected = $event.detail; activeTab = hideStatusTab ? 'timeline' : 'status'; openGeneration++">
    <x-ui.modal x-data="{ open: false }" @open-offboardee-modal.window="open = true" :isOpen="$initial !== null" class="w-full sm:w-[60vw] sm:max-w-[60vw]">
        <div class="relative flex max-h-[85vh] w-full sm:max-w-[60vw] flex-col rounded-3xl bg-white dark:bg-gray-900" x-show="selected" x-cloak>
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

                        <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-3 rounded-xl bg-gray-50 px-4 py-3 text-sm dark:bg-white/[0.03]">
                            <template x-if="selected.isLastWorkingDayExtended">
                                <div class="contents">
                                    <div>
                                        <p class="text-xs text-gray-400">Extended Last Working Day</p>
                                        <p class="font-medium text-[#145a3a] dark:text-[#3aa876]" x-text="selected.lastWorkingDay || '—'"></p>
                                    </div>
                                    <div class="h-8 w-px bg-gray-200 dark:bg-gray-700"></div>
                                </div>
                            </template>
                            <div>
                                <p class="text-xs text-gray-400">Employee Code</p>
                                <p class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.employeeCode"></p>
                            </div>
                            <div class="h-8 w-px bg-gray-200 dark:bg-gray-700"></div>
                            <div>
                                <p class="text-xs text-gray-400">Last Working Day</p>
                                <!-- Once extended, this reverts to showing the ORIGINAL
                                     date (see `OffboardingRequest::isLastWorkingDayExtended()`)
                                     — the latest adjusted date lives in the "Extended Last
                                     Working Day" item above instead, per this feature's own
                                     "preserve the original for reference" requirement. -->
                                <p class="font-medium text-gray-700 dark:text-gray-300" x-text="(selected.isLastWorkingDayExtended ? selected.originalLastWorkingDay : selected.lastWorkingDay) || '—'"></p>
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
                            <template x-if="selected.separationType">
                                <div class="contents">
                                    <div class="h-8 w-px bg-gray-200 dark:bg-gray-700"></div>
                                    <div>
                                        <p class="text-xs text-gray-400">Separation Type</p>
                                        <p class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.separationType"></p>
                                    </div>
                                </div>
                            </template>
                            <template x-if="selected.noticePeriodDays !== null && selected.noticePeriodDays !== undefined">
                                <div class="contents">
                                    <div class="h-8 w-px bg-gray-200 dark:bg-gray-700"></div>
                                    <div>
                                        <p class="text-xs text-gray-400">Notice Period</p>
                                        <p class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.noticePeriodDays + ' days'"></p>
                                    </div>
                                </div>
                            </template>
                            <!-- <template x-if="selected.notificationDate">
                                <div class="contents">
                                    <div class="h-8 w-px bg-gray-200 dark:bg-gray-700"></div>
                                    <div>
                                        <p class="text-xs text-gray-400">Notification Date</p>
                                        <div class="flex items-center gap-1.5">
                                            <p class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.notificationDate"></p>
                                            <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                                :class="{
                                                    'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400': selected.noticePeriodStatus === 'upcoming',
                                                    'bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400': selected.noticePeriodStatus === 'today',
                                                    'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400': selected.noticePeriodStatus === 'past'
                                                }"
                                                x-text="{ upcoming: 'Upcoming', today: 'Today', past: 'Past' }[selected.noticePeriodStatus]"></span>
                                        </div>
                                    </div>
                                </div>
                            </template> -->
                        </div>

                        <template x-if="selected.checklistTemplates && selected.checklistTemplates.length">
                            <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2">
                                <span class="text-xs font-medium text-gray-400">Legend:</span>
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full bg-[#145a3a] dark:bg-[#3aa876]"></span>
                                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Completed On Time</span>
                                </span>
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full bg-error-500"></span>
                                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Completed Late</span>
                                </span>
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full bg-blue-500"></span>
                                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">In Progress</span>
                                </span>
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full bg-orange-500"></span>
                                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Hold</span>
                                </span>
                            </div>
                            <div class="mt-4">
                                <p class="mb-1.5 text-xs font-medium text-gray-400">Clearance Checklist(s)</p>
                                <div class="flex flex-wrap gap-2">
                                    <template x-for="checklistName in selected.checklistTemplates" :key="checklistName">
                                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300" x-text="checklistName"></span>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <template x-if="!hideStatusTab">
                            <div class="mt-7 mb-4 flex items-center gap-6 border-b border-gray-200 dark:border-gray-800">
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
                        </template>

                        {{-- Color legend for both tabs below — pinned here (outside the
                             scrollable content area) so it's always visible above whichever
                             tab is active, never scrolled out of view. Every chip reuses the
                             EXACT same classes as the real badges it explains, so it's
                             guaranteed to match what's actually shown: green/red come from
                             the "Cleared"/"Completed" checklist badge and the per-item
                             "Completed" dot (see the `step.wasCompletedLate`-keyed `:class`
                             bindings below and in checklist-item-status-list.blade.php), blue
                             from the "In Progress"/"viewed" badge, and orange from the
                             existing "Overdue" chip's own color scale — the closest already-
                             established "orange" in this tab, since a checklist item's own
                             Hold indicator uses a very similar amber shade. --}}
                        
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
                            <template x-for="(step, index) in richSteps()" :key="openGeneration + '-' + index">
                                <div class="rounded-xl border border-gray-200 dark:border-gray-800" x-data="{ open: false }">
                                    <!-- Collapsed header — always visible: Checklist Title, assigned
                                         Clearance/General Signatory, and Status. Click anywhere to
                                         expand/collapse this checklist independently of any other
                                         (each step has its own `open` state via x-data above, so
                                         opening one never affects the others). -->
                                    <button type="button" @click="open = !open"
                                        class="group flex w-full flex-wrap items-center justify-between gap-2 p-4 text-left">
                                        <div class="min-w-0">
                                            <p class="text-sm font-semibold text-gray-800 group-hover:text-[#145a3a] dark:text-white/90 dark:group-hover:text-[#3aa876]" x-text="step.department || 'Department'"></p>
                                            <p class="text-xs text-gray-500 group-hover:text-[#145a3a] dark:text-gray-400 dark:group-hover:text-[#3aa876]">
                                                <span x-text="step.isGeneralSignatory ? 'General Signatory' : 'Clearance Signatory'"></span>: <span x-text="step.approverName"></span>
                                            </p>
                                        </div>
                                        <div class="flex shrink-0 items-center gap-3">
                                            <span class="rounded-full px-2.5 py-1 text-xs font-medium group-hover:text-[#145a3a] dark:group-hover:text-[#3aa876]"
                                                :class="{
                                                    'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300': step.status === 'pending' && !step.hasRecordedProgress && !step.isOverdue,
                                                    'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400': (step.status === 'viewed' || (step.status === 'pending' && step.hasRecordedProgress)) && !step.isOverdue,
                                                    'bg-[#145a3a]/10 text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]': step.status === 'approved' && !step.wasCompletedLate,
                                                    'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400': (step.status === 'approved' && step.wasCompletedLate) || step.status === 'declined',
                                                    'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400': step.status === 'on_hold' && !step.isOverdue,
                                                    'bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400': step.isOverdue
                                                }"
                                                x-text="step.isOverdue ? 'Overdue' : (step.status === 'approved' ? (step.wasCompletedLate ? 'Completed' : 'Cleared') : ((step.status === 'viewed' || (step.status === 'pending' && step.hasRecordedProgress)) ? 'In Progress' : (step.status === 'on_hold' ? 'On Hold' : (step.status.charAt(0).toUpperCase() + step.status.slice(1)))))"></span>
                                            <svg class="h-4 w-4 shrink-0 text-gray-400 transition-transform duration-200 dark:text-gray-500"
                                                :class="open ? 'rotate-180' : ''"
                                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                            </svg>
                                        </div>
                                    </button>

                                    <!-- Expanded body — full checklist details, unchanged from before;
                                         only its visibility (and independent open/close state) is new. -->
                                    <div x-show="open" x-cloak
                                        x-transition:enter="transition ease-out duration-200"
                                        x-transition:enter-start="opacity-0 -translate-y-1"
                                        x-transition:enter-end="opacity-100 translate-y-0"
                                        x-transition:leave="transition ease-in duration-150"
                                        x-transition:leave-start="opacity-100 translate-y-0"
                                        x-transition:leave-end="opacity-0 -translate-y-1"
                                        class="border-t border-gray-100 px-4 pb-4 dark:border-gray-800">

                                    <div class="mt-2 space-y-1 text-xs text-gray-500 dark:text-gray-400">
                                        <p>Date Assigned: <span x-text="step.assignedAt || '—'"></span></p>
                                        <template x-if="step.dueAt">
                                            <p :class="step.isOverdue ? 'font-medium text-error-600 dark:text-error-400' : ''">
                                                Until End of the Day: <span x-text="step.dueAt"></span>
                                                <span x-show="step.isOverdue"> — Overdue</span>
                                            </p>
                                        </template>
                                        <template x-if="step.clearanceSigningDueAt">
                                            <p :class="step.isClearanceSigningOverdue ? 'font-medium text-error-600 dark:text-error-400' : ''">
                                                Clearance Signing Due Date: <span x-text="step.clearanceSigningDueAt"></span>
                                                <span x-show="step.isClearanceSigningOverdue"> — Overdue</span>
                                            </p>
                                        </template>
                                        <p>First Viewed: <span x-text="step.firstViewedAt || 'Not viewed yet'"></span>
                                            <span x-show="step.firstViewedByName"> by <span x-text="step.firstViewedByName"></span></span>
                                        </p>
                                        <!-- Orange text — same color the Legend's own "Hold" dot uses
                                             (bg-orange-500 above) — so a declined/on-hold checklist
                                             reads as an alert to the admin/HR at a glance, not just
                                             another neutral-gray line among everything else. -->
                                        <template x-if="step.declinedAt">
                                            <p class="font-medium text-orange-600 dark:text-orange-400">On Hold: <span x-text="step.declinedAt"></span>
                                                <span x-show="step.declinedByName"> by <span x-text="step.declinedByName"></span></span>
                                            </p>
                                        </template>
                                        <template x-if="step.declineReason">
                                            <p class="font-medium text-orange-600 dark:text-orange-400">Reason: <span x-text="step.declineReason"></span></p>
                                        </template>
                                        <!-- "On Hold Removed" — permanent audit-trail entry (see
                                             `ApprovalController::removeHold()`), shown alongside the
                                             decline info above regardless of whether the checklist is
                                             currently on hold or was later resumed/approved. -->
                                        <template x-if="step.onHoldRemovedAt">
                                            <p>On Hold Removed: <span x-text="step.onHoldRemovedAt"></span>
                                                <span x-show="step.onHoldRemovedByName"> by <span x-text="step.onHoldRemovedByName"></span></span>
                                            </p>
                                        </template>
                                        <template x-if="step.onHoldRemovalReason">
                                            <p>Hold Removal Reason: <span x-text="step.onHoldRemovalReason"></span></p>
                                        </template>
                                        <!-- Lets HR/Admin know this checklist only needs the Clearance
                                             Signatory's own Submit click — every item is already done on
                                             their end. Mutually exclusive with the Cleared line right
                                             below (isReadyForApproval requires status still pending/
                                             viewed, Cleared requires status === 'approved'), so only one
                                             of the two ever renders for a given checklist. -->
                                        <template x-if="step.isReadyForApproval">
                                            <p class="font-medium text-[#145a3a] dark:text-[#3aa876]">Ready For Approval</p>
                                        </template>
                                        <!-- Cleared is deliberately the LAST line in this list — it's
                                             always the final movement a Clearance Signatory makes (a
                                             decline/On Hold cycle, if any, only ever happens BEFORE the
                                             eventual approval, never after), so it must read as the last
                                             entry here regardless of how many On Hold cycles preceded it. -->
                                        <template x-if="step.status === 'approved'">
                                            <p :class="step.wasCompletedLate ? 'font-medium text-error-600 dark:text-error-400' : ''">
                                                Cleared: <span x-text="step.approvedAt"></span>
                                                <span x-show="step.wasCompletedLate"> — Completed Beyond Deadline</span>
                                            </p>
                                        </template>
                                        <!-- <template x-if="step.status === 'approved' && step.approverSignatureUrl">
                                            <div class="mt-1.5 flex items-center gap-2">
                                                <span class="text-gray-400">E-Signature:</span>
                                                <img :src="step.approverSignatureUrl" :alt="step.approverName + '\'s signature'"
                                                    class="h-8 max-w-[120px] rounded border border-gray-200 bg-white object-contain dark:border-gray-700" />
                                            </div>
                                        </template> -->
                                        <template x-if="step.approvalMethod">
                                            <p>Approval Method: <span class="font-medium text-gray-700 dark:text-gray-300" x-text="step.approvalMethod"></span></p>
                                        </template>
                                        <template x-if="step.remarks">
                                            <p>Remarks: <span x-text="step.remarks"></span></p>
                                        </template>
                                        <!-- The whole-checklist "reason for the delay" remark, distinct from
                                             each item's own remark shown below by checklist-item-status-list. -->
                                        <template x-if="step.approvalRemarks">
                                            <p class="rounded-lg border border-error-200 bg-error-50 px-2.5 py-1.5 text-error-700 dark:border-error-500/30 dark:bg-error-500/10 dark:text-error-400">
                                                Remarks: <span x-text="step.approvalRemarks"></span>
                                            </p>
                                        </template>
                                    </div>

                                    <!-- Due Date Extension History — permanent, non-editable audit trail
                                         (see ChecklistDueDateExtension) of every "Extend Due" action taken
                                         on this checklist, oldest first. Absent entirely (not just empty)
                                         when the checklist has never been extended, so a never-extended
                                         checklist's card looks exactly as it always has. -->
                                    <template x-if="step.dueDateExtensions && step.dueDateExtensions.length">
                                        <div class="mt-2 rounded-lg border border-[#145a3a]/30 bg-[#145a3a]/5 px-3 py-2 text-xs dark:border-[#3aa876]/30 dark:bg-[#3aa876]/10">
                                            <p class="font-medium text-[#145a3a] dark:text-[#3aa876]">
                                                Last Working Day Extended
                                                <template x-if="step.originalDueDate">
                                                    <span class="font-normal text-gray-500 dark:text-gray-400">
                                                        (Originally due: <span x-text="step.originalDueDate"></span>)
                                                    </span>
                                                </template>
                                            </p>
                                            <ul class="mt-1.5 space-y-1 text-gray-600 dark:text-gray-300">
                                                <template x-for="(extension, extensionIndex) in step.dueDateExtensions" :key="extensionIndex">
                                                    <li>
                                                        <span x-text="extension.previousDueDate"></span>
                                                        &rarr;
                                                        <span class="font-medium text-gray-800 dark:text-white/90" x-text="extension.newDueDate"></span>
                                                        <span class="text-gray-400">(+<span x-text="extension.additionalDays"></span> day<span x-show="extension.additionalDays !== 1">s</span>)</span>
                                                        by <span class="font-medium text-gray-700 dark:text-gray-300" x-text="extension.extendedBy"></span>
                                                        <span class="text-gray-400" x-text="'on ' + extension.extendedAt"></span>
                                                        <template x-if="extension.reason">
                                                            <div class="mt-0.5 text-gray-500 dark:text-gray-400">Reason: <span x-text="extension.reason"></span></div>
                                                        </template>
                                                    </li>
                                                </template>
                                            </ul>
                                        </div>
                                    </template>

                                    @include('components.offboarding.partials.checklist-item-status-list')

                                    <!-- <template x-if="step.delegatedTo">
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
                                    </template> -->

                                    <div class="mt-2 space-y-2">
                                        <template x-if="step.reminderSentAt">
                                            <p class="text-xs text-gray-400">Last reminder sent: <span x-text="step.reminderSentAt"></span></p>
                                        </template>
                                        <!-- `items-start`, not `items-center` — the "Notify Approver"
                                             block's own height grows when its "Select Email Template"
                                             picker panel expands below its button row (see
                                             `showEmailPicker` further down); `items-center` would then
                                             re-center every flex item (including "Extend Due" beside it)
                                             against that new, taller height, visibly floating "Extend Due"
                                             away from the button row it's meant to stay beside.
                                             `items-start` keeps every item pinned to the top of this row
                                             regardless of how tall either sibling becomes. -->
                                        <div class="flex flex-wrap items-start gap-2">
                                        <template x-if="isAdmin && step.canRemind && selected.status !== 'cancelled'">
                                            <div x-data="{ confirmed: false, showEmailPicker: false, selectedTemplateId: defaultTemplateIdFor(step) }">
                                                <div class="flex items-center gap-2">
                                                    <form method="POST" :action="step.remindUrl"
                                                        @submit="if (!confirmed) {
                                                            $event.preventDefault();
                                                            Swal.fire({
                                                                title: 'Send reminder to ' + step.approverName + '?',
                                                                html: 'Using template: <b>' + (templateNameFor(selectedTemplateId) || 'none') + '</b>',
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
                                                        <input type="hidden" name="email_template_id" :value="selectedTemplateId" />
                                                        <button type="submit" :disabled="confirmed || !emailTemplates.length" data-turbo-submits-with="Sending..."
                                                            class="mb-4 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                                            Notify Approver
                                                        </button>
                                                    </form>
                                                    <button type="button" title="Choose Email Template" @click="showEmailPicker = !showEmailPicker"
                                                        class="mb-4 rounded-lg border border-gray-300 p-1.5 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                                        <svg width="14" height="14" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                            <path d="M2.25 5.25C2.25 4.42157 2.92157 3.75 3.75 3.75H14.25C15.0784 3.75 15.75 4.42157 15.75 5.25V12.75C15.75 13.5784 15.0784 14.25 14.25 14.25H3.75C2.92157 14.25 2.25 13.5784 2.25 12.75V5.25Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                                                            <path d="M2.75 5L9 9.75L15.25 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                                        </svg>
                                                    </button>
                                                </div>
                                                <div x-show="showEmailPicker" x-cloak class="mb-4 rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900"
                                                    x-transition:enter="transition ease-out duration-200"
                                                    x-transition:enter-start="opacity-0 -translate-y-1"
                                                    x-transition:enter-end="opacity-100 translate-y-0"
                                                    x-transition:leave="transition ease-in duration-150"
                                                    x-transition:leave-start="opacity-100 translate-y-0"
                                                    x-transition:leave-end="opacity-0 -translate-y-1">
                                                    <label class="mb-1.5 block text-xs font-medium text-gray-500 dark:text-gray-400">Select Email Template</label>
                                                    <template x-if="emailTemplates.length">
                                                        <select x-model="selectedTemplateId"
                                                            class="dark:bg-dark-900 h-9 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-3 text-xs text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                                                            <template x-for="template in emailTemplates" :key="template.id">
                                                                <option :value="template.id" x-text="template.template_name"></option>
                                                            </template>
                                                        </select>
                                                    </template>
                                                    <p x-show="!emailTemplates.length" class="text-xs text-error-500">
                                                        No email templates are available — create one before notifying this approver.
                                                    </p>
                                                    <p x-show="emailTemplates.length && selectedTemplateId" class="mt-1.5 text-xs text-gray-400">
                                                        Will notify using: <span class="font-medium text-gray-700 dark:text-gray-300" x-text="templateNameFor(selectedTemplateId)"></span>
                                                    </p>
                                                </div>
                                            </div>
                                        </template>

                                        </div>
                                    </div>
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
                                        ? (step.status === 'declined' ? 'bg-error-500' : step.status === 'on_hold' ? 'bg-amber-500' : step.status === 'approved' ? (step.wasCompletedLate ? 'bg-error-500' : 'bg-[#145a3a]') : (step.status === 'viewed' || (step.status === 'pending' && step.hasRecordedProgress)) ? 'bg-blue-500' : 'bg-gray-200 dark:bg-gray-700')
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
                                                <p class="text-xs text-gray-500 dark:text-gray-400">Clearance Signatory: <span x-text="step.approverName"></span></p>
                                            </div>
                                            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium"
                                                :class="{
                                                    'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300': step.status === 'pending' && !step.hasRecordedProgress && !step.isOverdue,
                                                    'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400': (step.status === 'viewed' || (step.status === 'pending' && step.hasRecordedProgress)) && !step.isOverdue,
                                                    'bg-[#145a3a]/10 text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]': step.status === 'approved' && !step.wasCompletedLate,
                                                    'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400': (step.status === 'approved' && step.wasCompletedLate) || step.status === 'declined',
                                                    'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400': step.status === 'on_hold' && !step.isOverdue,
                                                    'bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400': step.isOverdue
                                                }"
                                                x-text="step.isOverdue ? 'Overdue' : (step.status === 'approved' ? (step.wasCompletedLate ? 'Completed' : 'Cleared') : ((step.status === 'viewed' || (step.status === 'pending' && step.hasRecordedProgress)) ? 'In Progress' : (step.status === 'on_hold' ? 'On Hold' : (step.status.charAt(0).toUpperCase() + step.status.slice(1)))))"></span>
                                        </div>

                                        <div class="mt-2 space-y-1 text-xs text-gray-500 dark:text-gray-400">
                                            <p>Date Assigned: <span x-text="step.assignedAt || '—'"></span></p>
                                            <template x-if="step.dueAt">
                                                <p :class="step.isOverdue ? 'font-medium text-error-600 dark:text-error-400' : ''">
                                                    Until End of the Day: <span x-text="step.dueAt"></span>
                                                    <span x-show="step.isOverdue"> — Overdue</span>
                                                </p>
                                            </template>
                                            <template x-if="step.clearanceSigningDueAt">
                                                <p :class="step.isClearanceSigningOverdue ? 'font-medium text-error-600 dark:text-error-400' : ''">
                                                    Clearance Signing Due Date: <span x-text="step.clearanceSigningDueAt"></span>
                                                    <span x-show="step.isClearanceSigningOverdue"> — Overdue</span>
                                                </p>
                                            </template>
                                            <p>First Viewed: <span x-text="step.firstViewedAt || 'Not viewed yet'"></span>
                                                <span x-show="step.firstViewedByName"> by <span x-text="step.firstViewedByName"></span></span>
                                            </p>
                                            <!-- Orange text — same color the Legend's own "Hold" dot uses
                                                 — so a declined/on-hold checklist reads as an alert at a
                                                 glance, matching the Status tab's identical treatment. -->
                                            <template x-if="step.declinedAt">
                                                <p class="font-medium text-orange-600 dark:text-orange-400">On Hold: <span x-text="step.declinedAt"></span>
                                                    <span x-show="step.declinedByName"> by <span x-text="step.declinedByName"></span></span>
                                                </p>
                                            </template>
                                            <template x-if="step.declineReason">
                                                <p class="font-medium text-orange-600 dark:text-orange-400">Reason: <span x-text="step.declineReason"></span></p>
                                            </template>
                                            <template x-if="step.onHoldRemovedAt">
                                                <p>On Hold Removed: <span x-text="step.onHoldRemovedAt"></span>
                                                    <span x-show="step.onHoldRemovedByName"> by <span x-text="step.onHoldRemovedByName"></span></span>
                                                </p>
                                            </template>
                                            <template x-if="step.onHoldRemovalReason">
                                                <p>Hold Removal Reason: <span x-text="step.onHoldRemovalReason"></span></p>
                                            </template>
                                            <!-- Lets HR/Admin know this checklist only needs the
                                                 Clearance Signatory's own Submit click, matching the
                                                 Status tab's identical treatment. -->
                                            <template x-if="step.isReadyForApproval">
                                                <p class="font-medium text-[#145a3a] dark:text-[#3aa876]">Ready For Approval</p>
                                            </template>
                                            <!-- Cleared is deliberately the LAST line here — always the
                                                 final movement a Clearance Signatory makes, matching the
                                                 Status tab's identical treatment. -->
                                            <template x-if="step.status === 'approved'">
                                                <p :class="step.wasCompletedLate ? 'font-medium text-error-600 dark:text-error-400' : ''">
                                                    Cleared: <span x-text="step.approvedAt"></span>
                                                    <span x-show="step.wasCompletedLate"> — Completed Beyond Deadline</span>
                                                </p>
                                            </template>
                                            <!-- <template x-if="step.status === 'approved' && step.approverSignatureUrl">
                                                <div class="mt-1.5 flex items-center gap-2">
                                                    <span class="text-gray-400">E-Signature:</span>
                                                    <img :src="step.approverSignatureUrl" :alt="step.approverName + '\'s signature'"
                                                        class="h-8 max-w-[120px] rounded border border-gray-200 bg-white object-contain dark:border-gray-700" />
                                                </div>
                                            </template> -->
                                            <template x-if="step.approvalMethod">
                                                <p>Approval Method: <span class="font-medium text-gray-700 dark:text-gray-300" x-text="step.approvalMethod"></span></p>
                                            </template>
                                            <template x-if="step.remarks">
                                                <p>Remarks: <span x-text="step.remarks"></span></p>
                                            </template>
                                            <template x-if="step.approvalRemarks">
                                                <p class="rounded-lg border border-error-200 bg-error-50 px-2.5 py-1.5 text-error-700 dark:border-error-500/30 dark:bg-error-500/10 dark:text-error-400">
                                                    Remarks: <span x-text="step.approvalRemarks"></span>
                                                </p>
                                            </template>
                                        </div>

                                        <!-- Due Date Extension History — same block the Offboarding
                                             Status tab shows, mirrored here so admins/HR can see the
                                             extension reason from the Timeline tab too, not just Status. -->
                                        <template x-if="step.dueDateExtensions && step.dueDateExtensions.length">
                                            <div class="mt-2 rounded-lg border border-[#145a3a]/30 bg-[#145a3a]/5 px-3 py-2 text-xs dark:border-[#3aa876]/30 dark:bg-[#3aa876]/10">
                                                <p class="font-medium text-[#145a3a] dark:text-[#3aa876]">
                                                    Last Working Day Extended
                                                    <template x-if="step.originalDueDate">
                                                        <span class="font-normal text-gray-500 dark:text-gray-400">
                                                            (Originally due: <span x-text="step.originalDueDate"></span>)
                                                        </span>
                                                    </template>
                                                </p>
                                                <ul class="mt-1.5 space-y-1 text-gray-600 dark:text-gray-300">
                                                    <template x-for="(extension, extensionIndex) in step.dueDateExtensions" :key="extensionIndex">
                                                        <li>
                                                            <span x-text="extension.previousDueDate"></span>
                                                            &rarr;
                                                            <span class="font-medium text-gray-800 dark:text-white/90" x-text="extension.newDueDate"></span>
                                                            <span class="text-gray-400">(+<span x-text="extension.additionalDays"></span> day<span x-show="extension.additionalDays !== 1">s</span>)</span>
                                                            by <span class="font-medium text-gray-700 dark:text-gray-300" x-text="extension.extendedBy"></span>
                                                            <span class="text-gray-400" x-text="'on ' + extension.extendedAt"></span>
                                                            <template x-if="extension.reason">
                                                                <div class="mt-0.5 text-gray-500 dark:text-gray-400">Reason: <span x-text="extension.reason"></span></div>
                                                            </template>
                                                        </li>
                                                    </template>
                                                </ul>
                                            </div>
                                        </template>

                                        @include('components.offboarding.partials.checklist-item-status-list')

                                        <!-- <template x-if="step.delegatedTo">
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
                                        </template> -->

                                        <div class="mt-2 space-y-2">
                                            <template x-if="step.reminderSentAt">
                                                <p class="text-xs text-gray-400">Last reminder sent: <span x-text="step.reminderSentAt"></span></p>
                                            </template>
                                            <!-- See the Status tab's identical comment above this same
                                                 pattern — `items-start` keeps "Extend Due" pinned beside
                                                 the button row even once the "Select Email Template"
                                                 picker panel expands the sibling block's height. -->
                                            <div class="flex flex-wrap items-start gap-2">
                                            <template x-if="isAdmin && step.canRemind && selected.status !== 'cancelled'">
                                                <div x-data="{ confirmed: false, showEmailPicker: false, selectedTemplateId: defaultTemplateIdFor(step) }">
                                                    <div class="flex items-center gap-2">
                                                        <form method="POST" :action="step.remindUrl"
                                                            @submit="if (!confirmed) {
                                                                $event.preventDefault();
                                                                Swal.fire({
                                                                    title: 'Send reminder to ' + step.approverName + '?',
                                                                    html: 'Using template: <b>' + (templateNameFor(selectedTemplateId) || 'none') + '</b>',
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
                                                            <input type="hidden" name="email_template_id" :value="selectedTemplateId" />
                                                            <button type="submit" :disabled="confirmed || !emailTemplates.length" data-turbo-submits-with="Sending..."
                                                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                                                Notify Approver
                                                            </button>
                                                        </form>
                                                        <button type="button" title="Choose Email Template" @click="showEmailPicker = !showEmailPicker"
                                                            class="rounded-lg border border-gray-300 p-1.5 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                                            <svg width="14" height="14" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                                <path d="M2.25 5.25C2.25 4.42157 2.92157 3.75 3.75 3.75H14.25C15.0784 3.75 15.75 4.42157 15.75 5.25V12.75C15.75 13.5784 15.0784 14.25 14.25 14.25H3.75C2.92157 14.25 2.25 13.5784 2.25 12.75V5.25Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                                                                <path d="M2.75 5L9 9.75L15.25 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                                            </svg>
                                                        </button>
                                                    </div>
                                                    <div x-show="showEmailPicker" x-cloak class="mt-2 rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900"
                                                        x-transition:enter="transition ease-out duration-200"
                                                        x-transition:enter-start="opacity-0 -translate-y-1"
                                                        x-transition:enter-end="opacity-100 translate-y-0"
                                                        x-transition:leave="transition ease-in duration-150"
                                                        x-transition:leave-start="opacity-100 translate-y-0"
                                                        x-transition:leave-end="opacity-0 -translate-y-1">
                                                        <label class="mb-1.5 block text-xs font-medium text-gray-500 dark:text-gray-400">Select Email Template</label>
                                                        <template x-if="emailTemplates.length">
                                                            <select x-model="selectedTemplateId"
                                                                class="dark:bg-dark-900 h-9 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-3 text-xs text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                                                                <template x-for="template in emailTemplates" :key="template.id">
                                                                    <option :value="template.id" x-text="template.template_name"></option>
                                                                </template>
                                                            </select>
                                                        </template>
                                                        <p x-show="!emailTemplates.length" class="text-xs text-error-500">
                                                            No email templates are available — create one before notifying this approver.
                                                        </p>
                                                        <p x-show="emailTemplates.length && selectedTemplateId" class="mt-1.5 text-xs text-gray-400">
                                                            Will notify using: <span class="font-medium text-gray-700 dark:text-gray-300" x-text="templateNameFor(selectedTemplateId)"></span>
                                                        </p>
                                                    </div>
                                                </div>
                                            </template>
                                            </div>
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
                                @click="briefLoadingState('generatingClearanceForm')"
                                :class="generatingClearanceForm ? 'pointer-events-none opacity-50' : 'hover:bg-gray-50 dark:hover:bg-white/5'"
                                class="flex items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 dark:border-gray-700 dark:text-gray-300">
                                <svg x-show="!generatingClearanceForm" width="16" height="16" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M4.5 12V15.75C4.5 16.1642 4.83579 16.5 5.25 16.5H12.75C13.1642 16.5 13.5 16.1642 13.5 15.75V12M9 1.5V11.25M9 11.25L5.625 7.875M9 11.25L12.375 7.875" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <span x-show="generatingClearanceForm" x-cloak class="h-4 w-4 animate-spin rounded-full border-2 border-solid border-gray-400 border-t-transparent"></span>
                                <span x-text="generatingClearanceForm ? 'Generating...' : 'Generate Clearance Form'"></span>
                            </a>
                        </template>
                        <template x-if="(isAdmin || selected.status === 'completed') && selected.printClearanceFormUrl">
                            <a :href="selected.printClearanceFormUrl" target="_blank" rel="noopener"
                                @click="briefLoadingState('printingClearanceForm')"
                                :class="printingClearanceForm ? 'pointer-events-none opacity-50' : 'hover:bg-[#0f4630]'"
                                class="flex items-center justify-center gap-1.5 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white">
                                <svg x-show="!printingClearanceForm" width="16" height="16" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M4.5 6.75V2.25H13.5V6.75M4.5 14.25H3C2.17157 14.25 1.5 13.5784 1.5 12.75V8.25C1.5 7.42157 2.17157 6.75 3 6.75H15C15.8284 6.75 16.5 7.42157 16.5 8.25V12.75C16.5 13.5784 15.8284 14.25 15 14.25H13.5M4.5 14.25V16.5H13.5V14.25M4.5 14.25H13.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <span x-show="printingClearanceForm" x-cloak class="h-4 w-4 animate-spin rounded-full border-2 border-solid border-white border-t-transparent"></span>
                                <span x-text="printingClearanceForm ? 'Preparing...' : 'Print Clearance Form'"></span>
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
