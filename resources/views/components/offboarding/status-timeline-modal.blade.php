<div x-data="{ selected: null }" @open-offboardee-modal.window="selected = $event.detail">
    <x-ui.modal x-data="{ open: false }" @open-offboardee-modal.window="open = true" :isOpen="false" class="max-w-[600px]">
        <div class="no-scrollbar relative w-full max-w-[600px] overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="selected" x-cloak>
            <template x-if="selected">
                <div>
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

                    <h5 class="mb-4 mt-7 text-sm font-semibold text-gray-800 dark:text-white/90">
                        Offboarding Timeline
                    </h5>

                    <div class="relative">
                        <template x-for="(step, index) in selected.timeline" :key="index">
                            <div class="relative flex gap-4 pb-7 last:pb-0">
                                <div class="absolute top-3 left-[11px] h-full w-px bg-gray-200 dark:bg-gray-700"
                                    x-show="index < selected.timeline.length - 1"></div>
                                <div class="relative z-10 flex h-6 w-6 shrink-0 items-center justify-center rounded-full"
                                    :class="step.cancelled ? 'bg-error-500' : (step.done ? 'bg-[#145a3a]' : 'bg-gray-200 dark:bg-gray-700')">
                                    <svg x-show="step.done" width="14" height="14" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path fill-rule="evenodd" clip-rule="evenodd" d="M13.4767 4.10714C13.7788 4.38292 13.8008 4.85162 13.5257 5.15436L6.83817 12.5211C6.69758 12.6759 6.49882 12.7644 6.29008 12.7644C6.08134 12.7644 5.88258 12.6759 5.74199 12.5211L2.47426 8.9211C2.19916 8.61836 2.22119 8.14966 2.52326 7.87388C2.82533 7.5981 3.29283 7.62018 3.56793 7.92292L6.29008 10.9184L12.4321 4.15582C12.7072 3.85308 13.1746 3.83137 13.4767 4.10714Z" fill="white" />
                                    </svg>
                                </div>
                                <div class="pt-0.5">
                                    <p class="text-sm font-medium"
                                        :class="step.done ? 'text-gray-800 dark:text-white/90' : 'text-gray-400 dark:text-gray-500'"
                                        x-text="step.label"></p>
                                    <p class="text-xs text-gray-400" x-text="step.date || 'Not yet reached'"></p>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="mt-6 flex justify-end">
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
