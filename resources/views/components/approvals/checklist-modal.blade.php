<div x-data="{
        selected: null,
        checked: {},
        setSelected(detail) {
            this.selected = detail;
            this.checked = {};
            (detail.checklistItems || []).forEach((item) => { this.checked[item.id] = false; });
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
    }" @open-checklist-modal.window="setSelected($event.detail)">
    <x-ui.modal x-data="{ open: false }" @open-checklist-modal.window="open = true" :isOpen="false" class="max-w-[520px]">
        <div class="no-scrollbar relative w-full max-w-[520px] overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="selected" x-cloak>
            <template x-if="selected">
                <div>
                    <h4 class="text-xl font-semibold text-gray-800 dark:text-white/90" x-text="selected.name"></h4>
                    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400">
                        Confirm every clearance item has been completed before approving this offboarding request.
                    </p>

                    <template x-if="selected.checklistItems && selected.checklistItems.length">
                        <label class="mb-2 flex cursor-pointer items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 dark:border-gray-800 dark:bg-white/[0.03]">
                            <input type="checkbox" :checked="allChecked()" @change="toggleAll($event.target.checked)"
                                class="h-4 w-4 rounded border-gray-300 text-[#145a3a] focus:ring-[#145a3a] dark:border-gray-700 dark:bg-gray-900" />
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Select All</span>
                        </label>
                    </template>

                    <div class="max-h-72 space-y-2 overflow-y-auto pr-1">
                        <template x-if="!selected.checklistItems || selected.checklistItems.length === 0">
                            <p class="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-500 dark:bg-white/[0.03] dark:text-gray-400">
                                No checklist items are assigned to this request.
                            </p>
                        </template>
                        <template x-for="item in selected.checklistItems" :key="item.id">
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 px-4 py-3 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                                <input type="checkbox" x-model="checked[item.id]"
                                    class="mt-0.5 h-4 w-4 rounded border-gray-300 text-[#145a3a] focus:ring-[#145a3a] dark:border-gray-700 dark:bg-gray-900" />
                                <span>
                                    <span class="block text-sm font-medium text-gray-800 dark:text-white/90" x-text="item.title"></span>
                                    <span class="block text-xs text-gray-400" x-text="item.templateTitle"></span>
                                </span>
                            </label>
                        </template>
                    </div>

                    <div class="mt-6 flex items-center justify-end gap-3">
                        <button @click="open = false" type="button"
                            class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                            Close
                        </button>
                        <form method="POST" :action="selected.approveUrl" x-data="{ processing: false }" @submit="processing = true">
                            @csrf
                            <button type="submit" :disabled="processing || !allChecked()"
                                :class="(processing || !allChecked()) ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#0f4630]'"
                                class="flex items-center justify-center gap-1.5 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white">
                                Approve Request
                            </button>
                        </form>
                    </div>
                </div>
            </template>
        </div>
    </x-ui.modal>
</div>
