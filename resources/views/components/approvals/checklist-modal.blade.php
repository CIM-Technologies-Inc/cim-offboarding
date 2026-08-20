<div x-data="{
        selected: null,
        checked: {},
        remarks: {},
        holdProcessing: {},
        takeOverProcessing: {},
        setSelected(detail) {
            const checked = {};
            const remarks = {};
            const takeOverProcessing = {};
            (detail.checklistItems || []).forEach((item) => {
                checked[item.id] = !!item.checked;
                remarks[item.id] = item.remark || '';
                takeOverProcessing[item.id] = false;
            });
            // Populate reactive state BEFORE assigning `selected` — that
            // assignment is what triggers the x-if/x-for to render, so
            // every item's `checked`/`remarks`/`takeOverProcessing` entry
            // must already exist by then for bindings (like the Hold and
            // Check This List buttons' :disabled) that key off them to
            // track correctly from their very first evaluation.
            this.checked = checked;
            this.remarks = remarks;
            this.holdProcessing = {};
            this.takeOverProcessing = takeOverProcessing;
            this.selected = detail;
        },
        // True when the current viewer is literally the named signatory for
        // this item on a per-item-approver checklist — they get a Done
        // button alongside Hold, gated on the checkbox above being checked.
        // Legacy (non-per-item-approver) checklists never use this path, so
        // the Department Head's existing checkbox-only experience on those
        // is completely unchanged.
        isDoneFlowItem(item) {
            return !!this.selected?.usesPerItemApprovers && !!item.isOwnItem;
        },
        canHold(item) {
            return !!(this.remarks[item.id] || '').trim() && !this.holdProcessing[item.id];
        },
        holdItem(item) {
            const remark = (this.remarks[item.id] || '').trim();
            if (!remark || this.holdProcessing[item.id]) {
                return;
            }
            Swal.fire({
                title: 'Put Checklist on Hold?',
                html: 'This checklist item will be placed on Hold. The Department Head will be notified that this checklist item is currently on Hold.<br><br>Hold this checklist item?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Put on Hold',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#d97706',
                cancelButtonColor: '#6b7280',
                reverseButtons: true,
            }).then((result) => {
                if (!result.isConfirmed) {
                    return;
                }
                this.holdProcessing[item.id] = true;
                const formData = new FormData();
                formData.append('remark', remark);
                fetch(item.holdUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: formData,
                }).then((res) => {
                    if (res.ok || res.redirected) {
                        window.location.reload();
                    } else {
                        this.holdProcessing[item.id] = false;
                        Swal.fire({ icon: 'error', title: 'Failed to place item on Hold', confirmButtonColor: '#145a3a' });
                    }
                }).catch(() => {
                    this.holdProcessing[item.id] = false;
                    Swal.fire({ icon: 'error', title: 'Failed to place item on Hold', confirmButtonColor: '#145a3a' });
                });
            });
        },
        // 'Check This List': only accepts/assigns this item to the current
        // approver — it must never check/complete it or submit the form, and
        // it must never reload the page (that would close this very modal).
        // The server responds with the item's new editable/canTakeOver state
        // plus the assignment's usesPerItemApprovers/allItemsCompleted flags;
        // patching those into `selected` in place is what makes Hold/Done
        // unlock immediately — Alpine's reactivity re-renders every binding
        // that reads them without any navigation at all. The item itself
        // stays unchecked; only Hold/Done (clicked separately, afterward)
        // ever changes that.
        takeOverItem(item) {
            if (this.takeOverProcessing[item.id]) {
                return;
            }
            this.takeOverProcessing[item.id] = true;
            fetch(item.takeOverUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
            }).then(async (res) => {
                if (!res.ok) {
                    throw new Error('request failed');
                }
                const data = await res.json();
                Object.assign(item, data.item);
                this.selected.usesPerItemApprovers = data.usesPerItemApprovers;
                this.selected.allItemsCompleted = data.allItemsCompleted;
                this.takeOverProcessing[item.id] = false;
            }).catch(() => {
                this.takeOverProcessing[item.id] = false;
                Swal.fire({ icon: 'error', title: 'Failed to accept this checklist item', confirmButtonColor: '#145a3a' });
            });
        },
        allChecked() {
            if (!this.selected || !this.selected.checklistItems || this.selected.checklistItems.length === 0) {
                return true;
            }
            return this.selected.checklistItems.every((item) => this.checked[item.id]);
        },
        toggleAll(value) {
            (this.selected.checklistItems || []).forEach((item) => {
                if (item.editable) this.checked[item.id] = value;
            });
        },
        canEditAll() {
            return !!this.selected && (this.selected.checklistItems || []).every((item) => item.editable);
        },
        canApprove() {
            // The Department Head (primary approver) may submit once ready.
            // Legacy (non-per-item-approver) checklists: regardless of
            // individual item checkmarks — submitting is itself their
            // confirmation that clearance is complete, same as always.
            // Per-item-approver checklists: only once every item is checked
            // — by its own signatory, or by the Department Head directly
            // taking over someone else's item. Immediate Head checklists:
            // same full-completion gate as per-item-approver checklists,
            // even though the Immediate Head is a single approver with no
            // distinct item-level signatories — `isImmediateHeadChecklist`
            // is fixed for the assignment's lifetime (unlike
            // `usesPerItemApprovers`, which can flip live via 'Check This
            // List'), so it's safe to read directly off `selected` here.
            // Computed from the live `checked` state (not a stale server
            // flag) so the Submit button re-enables the instant the last
            // box is ticked, without needing a page reload.
            if (!this.selected) return false;
            if (this.selected.usesPerItemApprovers || this.selected.isImmediateHeadChecklist) {
                return (this.selected.checklistItems || []).every((item) => !!this.checked[item.id]);
            }
            return true;
        },
    }" @open-checklist-modal.window="setSelected($event.detail)">
    <x-ui.modal x-data="{ open: false }" @open-checklist-modal.window="open = true" :isOpen="false" class="w-full sm:max-w-[50vw]">
        <div class="no-scrollbar relative w-full overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="selected" x-cloak>
            <template x-if="selected">
                <div>
                    <h4 class="text-xl font-semibold text-gray-800 dark:text-white/90" x-text="selected.name"></h4>

                    <template x-if="selected.isGeneralSignatory">
                        <span class="mb-2 inline-flex items-center rounded-full bg-[#145a3a]/10 px-2.5 py-1 text-xs font-medium text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]">
                            General Signatory
                        </span>
                    </template>

                    <template x-if="!selected.isPrimaryApprover">
                        <p class="mb-1 text-sm text-[#145a3a] dark:text-[#3aa876]">
                            Assigned Department Head: <span x-text="selected.assignedByName"></span>
                        </p>
                    </template>

                    <template x-if="selected.dueAt">
                        <p class="mb-1 text-xs mb-2" :class="selected.isOverdue ? 'font-medium text-error-600 dark:text-error-400' : 'text-[#145a3a] dark:text-[#3aa876]'">
                            Due: <span x-text="selected.dueAt"></span>
                            <span x-show="selected.isOverdue"> — Overdue</span>
                        </p>
                    </template>

                    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400" x-show="selected.isDelegate">
                        Complete each clearance item and add remarks, then click Save Progress. The Department Head will review your work before giving final approval.
                    </p>
                    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400" x-show="!selected.isPrimaryApprover && !selected.isDelegate">
                        Check the box for each item assigned to you, add a remark if needed, then click Done to confirm it. Use Hold instead if you're blocked and need to explain why.
                    </p>
                    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400" x-show="selected.isPrimaryApprover && !selected.usesPerItemApprovers">
                        Check items and add remarks as needed, then click Submit to approve this offboarding request.
                    </p>
                    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400" x-show="selected.isPrimaryApprover && selected.usesPerItemApprovers && !selected.allItemsCompleted">
                        Each item is normally completed by its own assigned signatory, but as Department Head you can check any item directly yourself.
                    </p>
                    <p class="mb-5 text-sm font-medium text-[#145a3a] dark:text-[#3aa876]" x-show="selected.isPrimaryApprover && selected.usesPerItemApprovers && selected.allItemsCompleted">
                        All checklist items have been checked and this checklist is ready for your final approval. Review the details below, then click Submit.
                    </p>

                    <template x-if="selected.isPrimaryApprover && selected.delegation">
                        <div class="mb-5 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-xs dark:border-gray-800 dark:bg-white/[0.03]">
                            <div class="flex items-center justify-between">
                                <span class="text-gray-400">Assigned To</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300">
                                    <span x-text="selected.delegation.delegatedEmployeeName"></span>
                                    (<span x-text="selected.delegation.delegatedEmployeeCode"></span>)
                                </span>
                            </div>
                            <div class="mt-1.5 flex items-center justify-between">
                                <span class="text-gray-400">Delegated Approver Status</span>
                                <span class="font-medium capitalize text-gray-700 dark:text-gray-300" x-text="selected.delegation.delegationStatus === 'done' ? '✓ Done' : selected.delegation.delegationStatus"></span>
                            </div>
                            <template x-if="selected.delegation.delegateCompletedAt">
                                <div class="mt-1.5 flex items-center justify-between">
                                    <span class="text-gray-400">Completed</span>
                                    <span class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.delegation.delegateCompletedAt"></span>
                                </div>
                            </template>
                        </div>
                    </template>

                    <template x-if="selected.checklistItems && selected.checklistItems.length && canEditAll()">
                        <label class="mb-2 flex cursor-pointer items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 dark:border-gray-800 dark:bg-white/[0.03]">
                            <input type="checkbox" :checked="allChecked()" @change="toggleAll($event.target.checked)"
                                class="h-4 w-4 rounded border-gray-300 text-[#145a3a] focus:ring-[#145a3a] dark:border-gray-700 dark:bg-gray-900" />
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Select All</span>
                        </label>
                    </template>

                    <form method="POST" :action="selected.saveProgressUrl"
                        id="checklistProgressForm" x-data="{ processing: false }" @submit="processing = true">
                        @csrf
                        <div class="max-h-80 space-y-3 overflow-y-auto pr-1">
                            <template x-if="selected.isGeneralSignatory && (!selected.checklistItems || selected.checklistItems.length === 0)">
                                <p class="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-500 dark:bg-white/[0.03] dark:text-gray-400">
                                    This is a General Signatory checklist — no checklist items are required. Click Submit to approve and add this signatory to the Clearance Form.
                                </p>
                            </template>
                            <template x-if="!selected.isGeneralSignatory && (!selected.checklistItems || selected.checklistItems.length === 0)">
                                <p class="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-500 dark:bg-white/[0.03] dark:text-gray-400">
                                    No checklist items are assigned to this request.
                                </p>
                            </template>
                            <template x-for="item in selected.checklistItems" :key="item.id">
                                <div class="rounded-lg border border-gray-200 px-4 py-3 dark:border-gray-800">
                                    <input type="hidden" :name="`items[${item.id}][checklist_item_id]`" :value="item.id" />

                                    <label class="flex items-start gap-3" :class="item.editable ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'">
                                        <input type="checkbox" :name="`items[${item.id}][is_checked]`" value="1" x-model="checked[item.id]" :disabled="!item.editable"
                                            class="mt-0.5 h-4 w-4 rounded border-gray-300 text-[#145a3a] focus:ring-[#145a3a] dark:border-gray-700 dark:bg-gray-900" />
                                        <span>
                                            <span class="block text-sm font-medium text-gray-800 dark:text-white/90" x-text="item.title"></span>
                                            <span class="block text-xs text-gray-400" x-text="item.templateTitle"></span>
                                            <span class="block text-sm text-[#145a3a] dark:text-[#3aa876]" x-show="item.approverName"
                                                x-text="'Assigned To: ' + (item.approverCode ? item.approverCode + ' – ' : '') + item.approverName"></span>
                                            <span class="block text-xs text-gray-400" x-show="item.isReassigned"
                                                x-text="'Previously: ' + (item.originalApproverCode ? item.originalApproverCode + ' – ' : '') + (item.originalApproverName || 'Unassigned')"></span>
                                            <span class="block text-sm font-semibold text-success-600 dark:text-success-400" x-show="item.checked">Status: Checked</span>
                                            <span class="block text-sm text-gray-500 dark:text-gray-400" x-show="item.checked && item.clearedByName"
                                                x-text="'Checked By: ' + (item.clearedByCode ? item.clearedByCode + ' – ' : '') + item.clearedByName"></span>
                                            <span class="block text-sm text-gray-500 dark:text-gray-400" x-show="item.checked && item.clearedAt"
                                                x-text="'Checked Date: ' + item.clearedAt"></span>
                                            <span class="block text-sm font-semibold text-warning-600 dark:text-orange-400" x-show="item.onHold">Status: Hold</span>
                                        </span>
                                    </label>

                                    <textarea :name="`items[${item.id}][remark]`" x-model="remarks[item.id]" rows="2" placeholder="Remark (optional)" :disabled="!item.editable"
                                        :class="!item.editable ? 'cursor-not-allowed bg-gray-100 dark:bg-gray-800' : ''"
                                        class="dark:bg-dark-900 mt-2 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30"></textarea>

                                    <!-- Hold (always, whenever editable and not yet checked) + Done (only for the item's own signatory
                                         on a per-item-approver checklist) — Done stays disabled until the checkbox above is checked, and
                                         becomes disabled again if the signatory unchecks it. -->
                                    <template x-if="item.editable && !item.checked">
                                        <div class="mt-2 flex gap-2">
                                            <button type="button" @click="holdItem(item)"
                                                :disabled="!canHold(item)"
                                                :class="!canHold(item) ? 'opacity-50 cursor-not-allowed' : 'hover:bg-amber-50 dark:hover:bg-amber-500/10'"
                                                class="rounded-lg border border-amber-500 px-3 py-1.5 text-xs font-medium text-amber-600 dark:border-amber-400 dark:text-amber-400">
                                                Hold
                                            </button>
                                            <template x-if="isDoneFlowItem(item)">
                                                <button type="button" @click="processing = true; $nextTick(() => $el.closest('form').requestSubmit());"
                                                    :disabled="!checked[item.id] || processing"
                                                    :class="(!checked[item.id] || processing) ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#0f4630]'"
                                                    class="rounded-lg bg-[#145a3a] px-3 py-1.5 text-xs font-medium text-white">
                                                    Done
                                                </button>
                                            </template>
                                            <!-- Assign To: Department Head only — lets them hand this specific item off to a
                                                 different employee. Never shown to a delegate or to the item's own signatory. -->
                                            <template x-if="selected.isPrimaryApprover">
                                                <button type="button"
                                                    @click="open = false; $dispatch('open-item-assign-modal', {
                                                        offboardeeName: selected.name,
                                                        checklistTitle: item.templateTitle,
                                                        itemTitle: item.title,
                                                        currentApproverName: item.approverName,
                                                        currentApproverCode: item.approverCode,
                                                        assignItemUrl: item.assignItemUrl,
                                                    })"
                                                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                                    Assign To
                                                </button>
                                            </template>
                                        </div>
                                    </template>

                                    <template x-if="item.canTakeOver">
                                        <div class="mt-2">
                                            <button type="button" :disabled="takeOverProcessing[item.id]"
                                                @click="Swal.fire({
                                                    title: 'Confirm Responsibility',
                                                    html: 'This checklist item is assigned to another approver. By proceeding, you are confirming that you will take responsibility for this checklist item — you can then add remarks, place it on Hold, or check it off yourself.<br><br>Are you sure you want to continue?',
                                                    icon: 'warning',
                                                    showCancelButton: true,
                                                    confirmButtonText: 'Yes, Check This List',
                                                    cancelButtonText: 'Cancel',
                                                    confirmButtonColor: '#145a3a',
                                                    cancelButtonColor: '#6b7280',
                                                    reverseButtons: true
                                                }).then((result) => {
                                                    if (result.isConfirmed) {
                                                        takeOverItem(item);
                                                    }
                                                })"
                                                :class="takeOverProcessing[item.id] ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#145a3a]/5 dark:hover:bg-[#3aa876]/10'"
                                                class="rounded-lg border border-[#145a3a] px-3 py-1.5 text-xs font-medium text-[#145a3a] dark:border-[#3aa876] dark:text-[#3aa876]">
                                                Check This List
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>

                        <div class="mt-6 flex items-center justify-end gap-3">
                            <button @click="open = false" type="button"
                                class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                                Close
                            </button>

                            <!-- Save Progress: delegates (no Submit authority of their own — this is their only way to persist work for
                                 the Department Head to review), and the Department Head themselves on a per-item-approver checklist (lets
                                 them check an item assigned to someone else — e.g. an unavailable signatory — without forcing every other
                                 item to already be done first). Legacy checklists keep the Department Head's original Submit-only experience,
                                 since Submit there already saves and approves in one action. A checklist item signatory only ever sees Done. -->
                            <template x-if="(selected.isDelegate || (selected.isPrimaryApprover && selected.usesPerItemApprovers)) && selected.checklistItems && selected.checklistItems.length">
                                <button type="submit" :formaction="selected.saveProgressUrl" :disabled="processing"
                                    :class="processing ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-50 dark:hover:bg-white/5'"
                                    class="flex items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 dark:border-gray-700 dark:text-gray-300">
                                    Save Progress
                                </button>
                            </template>

                            <!-- Department Head / primary approver: final Submit — only they can approve the whole checklist. Legacy
                                 checklists: enabled regardless of item checks (same as always). Per-item-approver checklists: enabled
                                 only once every item has actually been checked. -->
                            <template x-if="selected.isPrimaryApprover">
                                <button type="submit" :formaction="selected.approveUrl" :disabled="processing || !canApprove()"
                                    :class="(processing || !canApprove()) ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#0f4630]'"
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
