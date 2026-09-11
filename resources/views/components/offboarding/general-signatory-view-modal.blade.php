<div x-data="{ viewing: null }" @open-view-general-signatory-modal.window="viewing = $event.detail">
    <x-ui.modal x-data="{ open: false }" @open-view-general-signatory-modal.window="open = true" :isOpen="false" class="max-w-[560px]">
        <div class="no-scrollbar relative max-h-[85vh] w-full max-w-[560px] overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="viewing" x-cloak>
            <template x-if="viewing">
                <div>
                    <div class="flex items-start justify-between pr-8">
                        <div>
                            <h4 class="text-xl font-semibold text-gray-800 dark:text-white/90" x-text="viewing.clearanceSignatoryName"></h4>
                            <p class="text-sm text-gray-500 dark:text-gray-400" x-text="viewing.clearanceSignatoryCode"></p>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1.5">
                            <span class="rounded-full px-2.5 py-1 text-xs font-medium"
                                :class="viewing.isActive ? 'bg-[#145a3a]/10 text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'"
                                x-text="viewing.isActive ? 'Active' : 'Inactive'"></span>
                            <span class="rounded-full px-2.5 py-1 text-xs font-medium"
                                :class="{
                                    'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400': viewing.classification === 'final_pay',
                                    'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400': viewing.classification === 'secondary',
                                    'bg-[#145a3a]/10 text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]': !viewing.classification || viewing.classification === 'primary',
                                }"
                                x-text="viewing.classification === 'final_pay' ? 'Final Pay' : (viewing.classification === 'secondary' ? 'Secondary' : 'Core')"></span>
                        </div>
                    </div>

                    <h5 class="mb-2 mt-6 text-sm font-semibold text-gray-800 dark:text-white/90">
                        Task List (<span x-text="viewing.tasks.length"></span>)
                    </h5>
                    <div class="space-y-2">
                        <template x-for="task in viewing.tasks" :key="task.id">
                            <div class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
                                <span class="text-sm font-medium text-gray-800 dark:text-white/90" x-text="task.title"></span>
                                <span class="text-xs text-gray-500 dark:text-gray-400" x-text="task.signatoryName || 'Unassigned'"></span>
                            </div>
                        </template>
                        <template x-if="viewing.tasks.length === 0">
                            <p class="py-4 text-center text-sm text-gray-400">No tasks yet.</p>
                        </template>
                    </div>

                    <div class="mt-7 flex justify-end">
                        <button @click="open = false" type="button"
                            class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                            Close
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </x-ui.modal>
</div>
