<div x-data="{
        selected: null,
        processing: false,
        setSelected(detail) {
            this.processing = false;
            this.selected = detail;
        },
    }" @open-general-signatory-modal.window="setSelected($event.detail)">
    <x-ui.modal x-data="{ open: false }" @open-general-signatory-modal.window="open = true" :isOpen="false" class="w-full sm:max-w-[480px]">
        <div class="no-scrollbar relative w-full overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="selected" x-cloak>
            <template x-if="selected">
                <div>
                    <h4 class="text-xl font-semibold text-gray-800 dark:text-white/90" x-text="selected.name"></h4>
                    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400">
                        You are assigned as a General Signatory (Clearance Signatory) on this offboarding request.
                    </p>

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
                            <span class="text-gray-400">Last Working Day</span>
                            <span class="font-medium text-gray-700 dark:text-gray-300" x-text="selected.lastWorkingDay"></span>
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
                        <div class="mt-2 flex items-center justify-end gap-3">
                            <button @click="open = false" type="button"
                                class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                                Cancel
                            </button>
                            <button type="submit" :disabled="processing"
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
