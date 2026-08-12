<div x-data="{
        selected: null,
        checked: {},
        remarks: {},
        setSelected(detail) {
            this.selected = detail;
            this.checked = {};
            this.remarks = {};
            (detail.checklistItems || []).forEach((item) => {
                this.checked[item.id] = !!item.checked;
                this.remarks[item.id] = item.remark || '';
            });
        },
        allChecked() {
            if (!this.selected || !this.selected.checklistItems || this.selected.checklistItems.length === 0) {
                return true;
            }
            return this.selected.checklistItems.every((item) => this.checked[item.id]);
        },
        toggleAll(value) {
            (this.selected.checklistItems || []).forEach((item) => { this.checked[item.id] = value; });
        },
        canApprove() {
            // The primary approver/Department Head may submit regardless of
            // individual item checkmarks or delegation status — submitting
            // is itself their confirmation that clearance is complete. They
            // decide when a delegated checklist is finished, not the delegate.
            return !!this.selected;
        },
    }" @open-checklist-modal.window="setSelected($event.detail)">
    <x-ui.modal x-data="{ open: false }" @open-checklist-modal.window="open = true" :isOpen="false" class="max-w-[560px]">
        <div class="no-scrollbar relative w-full max-w-[560px] overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="selected" x-cloak>
            <template x-if="selected">
                <div>
                    <h4 class="text-xl font-semibold text-gray-800 dark:text-white/90" x-text="selected.name"></h4>

                    <template x-if="!selected.isPrimaryApprover">
                        <p class="mb-1 text-sm text-gray-500 dark:text-gray-400">
                            Assigned by: <span x-text="selected.assignedByCode"></span>
                        </p>
                    </template>

                    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400" x-show="!selected.isPrimaryApprover">
                        Complete each clearance item and add remarks, then click Save Progress. The Department Head will review your work before giving final approval.
                    </p>
                    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400" x-show="selected.isPrimaryApprover">
                        Check items and add remarks as needed, then click Submit to approve this offboarding request.
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

                    <template x-if="selected.checklistItems && selected.checklistItems.length">
                        <label class="mb-2 flex cursor-pointer items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 dark:border-gray-800 dark:bg-white/[0.03]">
                            <input type="checkbox" :checked="allChecked()" @change="toggleAll($event.target.checked)"
                                class="h-4 w-4 rounded border-gray-300 text-[#145a3a] focus:ring-[#145a3a] dark:border-gray-700 dark:bg-gray-900" />
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Select All</span>
                        </label>
                    </template>

                    <form method="POST" :action="selected.isPrimaryApprover ? selected.approveUrl : selected.saveProgressUrl"
                        id="checklistProgressForm" x-data="{ processing: false }" @submit="processing = true">
                        @csrf
                        <div class="max-h-80 space-y-3 overflow-y-auto pr-1">
                            <template x-if="!selected.checklistItems || selected.checklistItems.length === 0">
                                <p class="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-500 dark:bg-white/[0.03] dark:text-gray-400">
                                    No checklist items are assigned to this request.
                                </p>
                            </template>
                            <template x-for="item in selected.checklistItems" :key="item.id">
                                <div class="rounded-lg border border-gray-200 px-4 py-3 dark:border-gray-800">
                                    <input type="hidden" :name="`items[${item.id}][checklist_item_id]`" :value="item.id" />
                                    <label class="flex cursor-pointer items-start gap-3">
                                        <input type="checkbox" :name="`items[${item.id}][is_checked]`" value="1" x-model="checked[item.id]"
                                            class="mt-0.5 h-4 w-4 rounded border-gray-300 text-[#145a3a] focus:ring-[#145a3a] dark:border-gray-700 dark:bg-gray-900" />
                                        <span>
                                            <span class="block text-sm font-medium text-gray-800 dark:text-white/90" x-text="item.title"></span>
                                            <span class="block text-xs text-gray-400" x-text="item.templateTitle"></span>
                                        </span>
                                    </label>
                                    <textarea :name="`items[${item.id}][remark]`" x-model="remarks[item.id]" rows="2" placeholder="Remark (optional)"
                                        class="dark:bg-dark-900 mt-2 w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30"></textarea>
                                </div>
                            </template>
                        </div>

                        <div class="mt-6 flex items-center justify-end gap-3">
                            <button @click="open = false" type="button"
                                class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                                Close
                            </button>

                            <!-- Delegate: persist checks/remarks without approving anything -->
                            <template x-if="!selected.isPrimaryApprover && selected.checklistItems && selected.checklistItems.length">
                                <button type="submit" :disabled="processing"
                                    :class="processing ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-50 dark:hover:bg-white/5'"
                                    class="flex items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 dark:border-gray-700 dark:text-gray-300">
                                    Save Progress
                                </button>
                            </template>

                            <!-- Primary approver: Submit saves whatever's checked/typed and approves in one action -->
                            <template x-if="selected.isPrimaryApprover">
                                <button type="submit" :disabled="processing || !canApprove()"
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
