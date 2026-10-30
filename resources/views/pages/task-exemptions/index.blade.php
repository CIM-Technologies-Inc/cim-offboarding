@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="Task Exemption" />

    @php
        $inputClass = 'dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800';
        $selectClass = 'dark:bg-dark-900 h-11 rounded-lg border border-gray-300 bg-transparent px-3 pr-8 text-sm text-gray-700 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    @endphp

    <div x-data="taskExemptionWorkspace(@js($exemptions), @js($checklists), @js($departmentOptions), @js($positionOptions), @js($employeeOptions), @js(session('success')), @js(session('error')))">
        <div class="mb-6">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Leave specific checklist tasks out of an offboarding request when the offboardee matches a department,
                position, or individual employee. Rules are applied when a new offboarding request is created and frozen
                onto that request — editing, deactivating, or deleting a rule here never changes a request that already exists.
            </p>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:p-6">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h5 class="text-lg font-medium text-gray-800 dark:text-white/90">
                    Task Exemptions
                    <span class="text-sm font-normal text-gray-400" x-text="'(' + exemptions.length + ')'"></span>
                </h5>
                @can('task-exemptions.create')
                    <button type="button" @click="openCreateModal()"
                        class="shadow-theme-xs flex shrink-0 items-center justify-center gap-2 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630]">
                        <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M9 3.75V14.25M3.75 9H14.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        Create Task Exemption
                    </button>
                @endcan
            </div>

            <div class="mb-4 flex flex-wrap items-center gap-3">
                <div class="relative min-w-[220px] max-w-sm flex-1">
                    <input type="text" x-model="search" @input="page = 1" placeholder="Search target, checklist, or task..."
                        class="{{ $inputClass }} pl-11" />
                    <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2">
                        <svg class="fill-gray-500 dark:fill-gray-400" width="18" height="18" viewBox="0 0 20 20" fill="none">
                            <path fill-rule="evenodd" clip-rule="evenodd"
                                d="M3.04175 9.37363C3.04175 5.87693 5.87711 3.04199 9.37508 3.04199C12.8731 3.04199 15.7084 5.87693 15.7084 9.37363C15.7084 12.8703 12.8731 15.7053 9.37508 15.7053C5.87711 15.7053 3.04175 12.8703 3.04175 9.37363ZM9.37508 1.54199C5.04902 1.54199 1.54175 5.04817 1.54175 9.37363C1.54175 13.6991 5.04902 17.2053 9.37508 17.2053C11.2674 17.2053 13.003 16.5344 14.357 15.4176L17.177 18.238C17.4699 18.5309 17.9448 18.5309 18.2377 18.238C18.5306 17.9451 18.5306 17.4703 18.2377 17.1774L15.418 14.3573C16.5365 13.0033 17.2084 11.2669 17.2084 9.37363C17.2084 5.04817 13.7011 1.54199 9.37508 1.54199Z" fill="" />
                        </svg>
                    </span>
                </div>
                <select x-model="filters.status" @change="page = 1" class="{{ $selectClass }}">
                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                <select x-model="filters.targetType" @change="page = 1" class="{{ $selectClass }}">
                    <option value="">All Target Types</option>
                    <option value="department">Department</option>
                    <option value="position">Position</option>
                    <option value="employee">Employee</option>
                </select>
                <select x-model="filters.checklist" @change="page = 1" class="{{ $selectClass }} max-w-[250px]">
                    <option value="">All Checklists</option>
                    <template x-for="checklist in checklists" :key="checklist.id">
                        <option :value="String(checklist.id)" x-text="checklist.title"></option>
                    </template>
                </select>
                <button type="button" x-show="search || filters.status || filters.targetType || filters.checklist" @click="clearFilters()"
                    class="text-sm font-medium text-[#145a3a] hover:underline dark:text-[#3aa876]">Clear filters</button>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-800">
                <div class="max-w-full overflow-x-auto">
                    <table class="w-full" style="min-width: 720px">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <th class="px-4 py-3 text-left"><span class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Targets</span></th>
                                <th class="px-4 py-3 text-left"><span class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Exempted Tasks</span></th>
                                <th class="px-4 py-3 text-left"><span class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Status</span></th>
                                <th class="px-4 py-3 text-left"><span class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Last Updated</span></th>
                                <th class="px-4 py-3 text-right"><span class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="exemption in paginated()" :key="exemption.id">
                                <tr class="border-b border-gray-100 align-top last:border-b-0 dark:border-gray-800">
                                    <td class="px-4 py-3">
                                        <div class="flex max-w-[280px] flex-wrap gap-1">
                                            <template x-for="dept in exemption.departments" :key="'d' + dept">
                                                <span class="rounded-full bg-[#145a3a]/10 px-2 py-0.5 text-[11px] font-medium text-[#145a3a] dark:text-[#3aa876]" x-text="'Dept: ' + dept"></span>
                                            </template>
                                            <template x-for="pos in exemption.positions" :key="'p' + pos">
                                                <span class="rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700 dark:bg-blue-500/15 dark:text-blue-400" x-text="'Position: ' + pos"></span>
                                            </template>
                                            <template x-for="emp in exemption.employees" :key="'e' + emp.id">
                                                <span class="rounded-full bg-orange-50 px-2 py-0.5 text-[11px] font-medium text-orange-700 dark:bg-amber-500/10 dark:text-amber-400" x-text="emp.name"></span>
                                            </template>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                                        <span x-text="exemption.item_ids.length + ' task' + (exemption.item_ids.length === 1 ? '' : 's')"></span>
                                        <span class="text-gray-400" x-text="' in ' + exemption.checklists.length + ' checklist' + (exemption.checklists.length === 1 ? '' : 's')"></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        {{-- Same switch as the Offboarding Checklist page; saves
                                             immediately, disabled while the request is in flight. --}}
                                        @can('task-exemptions.edit')
                                            <label class="flex cursor-pointer items-center gap-2" :class="togglingId === exemption.id ? 'cursor-not-allowed opacity-50' : ''">
                                                <span class="relative inline-flex items-center">
                                                    <input type="checkbox" class="peer sr-only" role="switch"
                                                        :checked="exemption.is_active" :disabled="togglingId === exemption.id"
                                                        :aria-label="exemption.is_active ? 'Deactivate task exemption' : 'Activate task exemption'"
                                                        @change="toggleStatus(exemption, $event.target)" />
                                                    <span class="peer h-6 w-11 rounded-full bg-gray-200 transition-colors duration-200 peer-checked:bg-[#145a3a] peer-focus:outline-hidden after:absolute after:top-0.5 after:left-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:transition-all after:duration-200 after:content-[''] peer-checked:after:translate-x-5 dark:bg-gray-700"></span>
                                                </span>
                                                <span class="text-xs font-medium" :class="exemption.is_active ? 'text-[#145a3a] dark:text-[#3aa876]' : 'text-gray-400'"
                                                    x-text="exemption.is_active ? 'Active' : 'Inactive'"></span>
                                            </label>
                                        @else
                                            <span class="rounded-full px-2.5 py-1 text-xs font-medium"
                                                :class="exemption.is_active ? 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'"
                                                x-text="exemption.is_active ? 'Active' : 'Inactive'"></span>
                                        @endcan
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                                        <span class="block" x-text="exemption.updated_at || '—'"></span>
                                        <span class="block text-gray-400" x-text="exemption.updated_by ? 'by ' + exemption.updated_by : ''"></span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <button type="button" @click="openViewModal(exemption)"
                                                class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">View</button>
                                            @can('task-exemptions.edit')
                                                <button type="button" @click="openEditModal(exemption)"
                                                    class="rounded-md bg-[#145a3a]/10 px-2.5 py-1 text-xs font-medium text-[#145a3a] hover:bg-[#145a3a]/20 dark:text-[#3aa876]">Edit</button>
                                            @endcan
                                            @can('task-exemptions.delete')
                                                <button type="button" @click="confirmDelete(exemption)" :disabled="deletingId === exemption.id"
                                                    class="rounded-md bg-error-50 px-2.5 py-1 text-xs font-medium text-error-600 hover:bg-error-100 disabled:opacity-50 dark:bg-error-500/10 dark:text-error-400">
                                                    <span x-text="deletingId === exemption.id ? 'Deleting...' : 'Delete'"></span>
                                                </button>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="paginated().length === 0">
                                <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-400"
                                    x-text="exemptions.length ? 'No task exemptions match your search/filters.' : 'No task exemptions have been created yet.'"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3" x-show="filtered().length > perPage">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Showing <span x-text="(page - 1) * perPage + 1"></span>–<span x-text="Math.min(page * perPage, filtered().length)"></span> of <span x-text="filtered().length"></span>
                </p>
                <div class="flex items-center gap-1.5">
                    <button type="button" @click="page = Math.max(1, page - 1)" :disabled="page === 1"
                        class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300">Previous</button>
                    <span class="px-2 text-xs text-gray-500" x-text="page + ' / ' + totalPages()"></span>
                    <button type="button" @click="page = Math.min(totalPages(), page + 1)" :disabled="page === totalPages()"
                        class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300">Next</button>
                </div>
            </div>
        </div>

        {{-- Create / Edit modal. Closes only on a successful save; 422 errors
             stay inline under each section. --}}
        <x-ui.modal x-data="{ open: false }" @open-exemption-modal.window="open = true" @close-exemption-modal.window="open = false" :isOpen="false" class="task-exemption-modal">
            <div class="no-scrollbar relative max-h-[85vh] w-full overflow-y-auto rounded-3xl bg-white p-5 dark:bg-gray-900 sm:p-6" x-cloak>
                <h4 class="mb-1 pr-12 text-lg font-semibold text-gray-800 dark:text-white/90" x-text="editingId ? 'Edit Task Exemption' : 'Create Task Exemption'"></h4>
                <p class="mb-5 text-xs text-gray-400">Applies to an offboardee who matches <span class="font-medium">any</span> selected department, position, or employee. Only affects offboarding requests created after saving.</p>

                <form @submit.prevent="submit()">
                    {{-- A. Exemption Targets --}}
                    <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                        <h5 class="mb-1 text-sm font-semibold text-gray-800 dark:text-white/90">A. Exemption Targets <span class="text-error-500">*</span></h5>
                        <p class="mb-3 text-xs text-gray-400">Select at least one. An employee target affects only that employee — not others in the same department or position.</p>
                        <p x-show="errors.targets" x-text="errors.targets?.[0]" class="mb-3 text-xs text-error-500"></p>

                        <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
                            <template x-for="type in ['department', 'position', 'employee']" :key="type">
                                <div class="relative"
                                    @click.window.capture="pickers[type].open && ! $el.contains($event.target) && (pickers[type].open = false)">
                                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400" x-text="{ department: 'Department', position: 'Position', employee: 'Employee' }[type]"></label>
                                    <input type="text" x-model="pickers[type].query" autocomplete="off"
                                        @focus="pickers[type].open = true" @input="pickers[type].open = true"
                                        @keydown.escape="pickers[type].open = false"
                                        :placeholder="type === 'employee' ? 'Search name or employee no...' : 'Search ' + type + '...'"
                                        class="{{ $inputClass }}" />
                                    <div x-show="pickers[type].open"
                                        class="shadow-theme-lg absolute z-50 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                                        <template x-for="option in pickerOptions(type)" :key="option.key">
                                            <div @click="addTarget(type, option)"
                                                class="cursor-pointer border-b border-gray-100 px-4 py-2 text-sm last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                                                <span class="text-gray-800 dark:text-white/90" x-text="option.label"></span>
                                                <span class="block text-xs text-gray-400" x-show="option.sub" x-text="option.sub"></span>
                                            </div>
                                        </template>
                                        <div x-show="pickerOptions(type).length === 0" class="px-4 py-2.5 text-sm text-gray-400">No matches (or all already selected)</div>
                                    </div>
                                    <div class="mt-2 flex flex-wrap gap-1">
                                        <template x-for="chip in selectedChips(type)" :key="chip.key">
                                            <span class="inline-flex items-center gap-1 rounded-full bg-[#145a3a]/10 py-0.5 pr-1 pl-2 text-[11px] font-medium text-[#145a3a] dark:text-[#3aa876]">
                                                <span x-text="chip.label"></span>
                                                <button type="button" @click="removeTarget(type, chip.value)" class="rounded-full px-1 hover:bg-[#145a3a]/20" :aria-label="'Remove ' + chip.label">&times;</button>
                                            </span>
                                        </template>
                                    </div>
                                    <p x-show="fieldError(type)" x-text="fieldError(type)" class="mt-1 text-xs text-error-500"></p>
                                </div>
                            </template>
                        </div>
                    </section>

                    {{-- B. Checklist and Task Selection --}}
                    <section class="mt-3 rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                        <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <h5 class="text-sm font-semibold text-gray-800 dark:text-white/90">B. Checklists and Tasks to Exempt <span class="text-error-500">*</span></h5>
                                <p class="text-xs text-gray-400">Checked tasks are exempted for the targets above. Unchecked tasks are unaffected.</p>
                            </div>
                            <span class="rounded-full bg-[#145a3a] px-2.5 py-1 text-xs font-medium text-white" x-text="form.item_ids.length + ' selected'"></span>
                        </div>
                        <p x-show="errors.item_ids" x-text="errors.item_ids?.[0]" class="mb-3 text-xs text-error-500"></p>

                        <div class="mb-3 flex flex-wrap items-center gap-2">
                            <input type="text" x-model="taskSearch" placeholder="Filter checklists or tasks..." class="{{ $inputClass }} h-9 max-w-xs flex-1 py-1.5" />
                            <button type="button" @click="expandAll()" class="rounded-md border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">Expand all</button>
                            <button type="button" @click="expanded = []" class="rounded-md border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">Collapse all</button>
                        </div>

                        <template x-if="!checklists.length">
                            <p class="rounded-lg border border-dashed border-gray-300 p-4 text-center text-sm text-gray-400 dark:border-gray-700">There are no active checklists.</p>
                        </template>

                        <div class="max-h-64 space-y-2 overflow-y-auto pr-1">
                            <template x-for="checklist in visibleChecklists()" :key="checklist.id">
                                <div class="rounded-lg border border-gray-200 dark:border-gray-800">
                                    <div class="flex items-center justify-between gap-2 px-3 py-2.5">
                                        <input type="checkbox" x-show="checklist.tasks.length"
                                            :checked="allSelected(checklist)"
                                            x-effect="$el.indeterminate = someSelected(checklist)"
                                            @change="setChecklistSelection(checklist, $event.target.checked)"
                                            :aria-label="'Select all tasks in ' + checklist.title"
                                            :title="allSelected(checklist) ? 'Deselect all tasks in this checklist' : 'Select all tasks in this checklist'"
                                            class="h-4 w-4 shrink-0 cursor-pointer rounded border-gray-300 accent-[#145a3a] dark:border-gray-700" />
                                        <button type="button" @click="toggleExpanded(checklist.id)" class="flex min-w-0 flex-1 items-center gap-2 text-left">
                                            <svg width="14" height="14" viewBox="0 0 20 20" fill="none" class="shrink-0 transition-transform" :class="isExpanded(checklist.id) ? 'rotate-180' : ''">
                                                <path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                            <span class="truncate text-sm font-medium text-gray-800 dark:text-white/90" x-text="checklist.title"></span>
                                            <span x-show="checklist.is_final_pay_checklist" class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] text-gray-600 dark:bg-white/5 dark:text-gray-300">Final Pay</span>
                                        </button>
                                        <span class="shrink-0 text-xs"
                                            :class="selectedCount(checklist) ? 'font-medium text-[#145a3a] dark:text-[#3aa876]' : 'text-gray-400'"
                                            x-text="selectedCount(checklist) + ' / ' + checklist.tasks.length"></span>
                                    </div>
                                    <div x-show="isExpanded(checklist.id)" x-cloak class="border-t border-gray-100 px-3 py-2.5 dark:border-gray-800">
                                        <p x-show="!checklist.tasks.length" class="text-xs text-gray-400">This checklist has no tasks.</p>
                                        <div class="grid grid-cols-1 gap-1 sm:grid-cols-2">
                                            <template x-for="task in visibleTasks(checklist)" :key="task.id">
                                                <label class="flex cursor-pointer items-start gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                                                    <input type="checkbox" :value="task.id" :checked="form.item_ids.includes(task.id)" @change="toggleTask(task.id, $event.target.checked)"
                                                        class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 accent-[#145a3a] dark:border-gray-700" />
                                                    <span class="text-gray-700 dark:text-gray-300" :class="form.item_ids.includes(task.id) ? 'line-through' : ''" x-text="task.title"></span>
                                                </label>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </template>
                            <p x-show="checklists.length && !visibleChecklists().length" class="py-3 text-center text-sm text-gray-400">No checklists or tasks match your filter.</p>
                        </div>
                        <p x-show="orphanedItemCount()" class="mt-2 text-xs text-amber-600 dark:text-amber-400"
                            x-text="orphanedItemCount() + ' previously selected task(s) belong to an inactive checklist — kept, but ignored until that checklist is active again.'"></p>
                    </section>

                    <div class="mt-5 flex flex-wrap items-center justify-end gap-3">
                        <button @click="open = false" type="button"
                            class="flex w-full justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03] sm:w-auto">
                            Cancel
                        </button>
                        <button type="submit" :disabled="saving"
                            :class="saving ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#0f4630]'"
                            class="flex w-full items-center justify-center gap-2 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white sm:w-auto">
                            <svg x-show="saving" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25" /><path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" /></svg>
                            <span x-text="saving ? 'Saving...' : (editingId ? 'Update Exemption' : 'Save Exemption')"></span>
                        </button>
                    </div>
                </form>
            </div>
        </x-ui.modal>

        {{-- View modal --}}
        <x-ui.modal x-data="{ open: false }" @open-view-exemption-modal.window="open = true" :isOpen="false" class="max-w-[640px]">
            <div class="no-scrollbar relative max-h-[85vh] w-full max-w-[640px] overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-cloak>
                <template x-if="viewing">
                    <div>
                        <div class="mb-4 flex flex-wrap items-center gap-2">
                            <h4 class="text-xl font-semibold text-gray-800 dark:text-white/90">Task Exemption</h4>
                            <span class="rounded-full px-2.5 py-1 text-xs font-medium"
                                :class="viewing.is_active ? 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'"
                                x-text="viewing.is_active ? 'Active' : 'Inactive'"></span>
                        </div>
                        <p x-show="viewing.reason" class="mb-4 text-sm text-gray-500 dark:text-gray-400" x-text="viewing.reason"></p>

                        <h5 class="mb-2 text-sm font-semibold text-gray-800 dark:text-white/90">Targets</h5>
                        <dl class="mb-5 space-y-1.5 text-sm">
                            <div class="flex gap-2"><dt class="shrink-0 text-gray-400" style="width: 6rem">Departments</dt><dd class="text-gray-700 dark:text-gray-300" x-text="viewing.departments.join(', ') || '—'"></dd></div>
                            <div class="flex gap-2"><dt class="shrink-0 text-gray-400" style="width: 6rem">Positions</dt><dd class="text-gray-700 dark:text-gray-300" x-text="viewing.positions.join(', ') || '—'"></dd></div>
                            <div class="flex gap-2"><dt class="shrink-0 text-gray-400" style="width: 6rem">Employees</dt><dd class="text-gray-700 dark:text-gray-300" x-text="viewing.employees.map((e) => e.name + ' (' + e.employee_code + ')').join(', ') || '—'"></dd></div>
                        </dl>

                        <h5 class="mb-2 text-sm font-semibold text-gray-800 dark:text-white/90">Exempted Tasks</h5>
                        <div class="space-y-2">
                            <template x-for="checklist in viewing.checklists" :key="checklist.id">
                                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                                    <p class="mb-1 flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-white/90">
                                        <span x-text="checklist.title"></span>
                                        <span x-show="!checklist.is_active" class="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">Inactive checklist — ignored</span>
                                    </p>
                                    <ul class="list-disc space-y-0.5 pl-5 text-xs text-gray-500 dark:text-gray-400">
                                        <template x-for="(task, idx) in checklist.tasks" :key="idx"><li x-text="task"></li></template>
                                    </ul>
                                </div>
                            </template>
                        </div>
                        <p class="mt-4 text-xs text-gray-400" x-text="'Last updated ' + (viewing.updated_at || '—') + (viewing.updated_by ? ' by ' + viewing.updated_by : '')"></p>
                        <div class="mt-5 flex justify-end">
                            <button @click="open = false" type="button" class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400">Close</button>
                        </div>
                    </div>
                </template>
            </div>
        </x-ui.modal>
    </div>

    {{-- Create/Edit modal width: 60% of the screen on desktop, full width
         (minus the modal's own padding) on tablets/phones where 60% would be
         too narrow to use. Plain CSS so it never depends on the compiled
         Tailwind build. --}}
    <style>
        .task-exemption-modal {
            max-width: 60vw;
        }

        @media (max-width: 1023px) {
            .task-exemption-modal {
                max-width: 100%;
            }
        }
    </style>

    <script>
        function taskExemptionWorkspace(initialExemptions, checklists, departmentOptions, positionOptions, employeeOptions, flashSuccess, flashError) {
            const emptyForm = () => ({ departments: [], positions: [], employee_ids: [], item_ids: [] });

            return {
                exemptions: initialExemptions,
                checklists,
                departmentOptions,
                positionOptions,
                employeeOptions,

                search: '',
                filters: { status: '', targetType: '', checklist: '' },
                page: 1,
                perPage: 10,

                editingId: null,
                form: emptyForm(),
                errors: {},
                saving: false,
                deletingId: null,
                togglingId: null,
                viewing: null,
                taskSearch: '',
                expanded: [],
                pickers: {
                    department: { query: '', open: false },
                    position: { query: '', open: false },
                    employee: { query: '', open: false },
                },

                init() {
                    if (flashSuccess) this.notify('success', flashSuccess);
                    else if (flashError) this.notify('error', flashError);
                },
                notify(icon, title) {
                    window.Swal?.fire({
                        toast: true, position: 'bottom-end', icon, title, showConfirmButton: false,
                        timer: icon === 'success' ? 2500 : 3500, timerProgressBar: icon === 'success',
                        customClass: { container: 'app-toast' },
                    });
                },
                csrfToken() {
                    const meta = document.querySelector('meta[name="csrf-token"]');
                    return meta ? meta.content : '';
                },

                // ---- list ----
                filtered() {
                    const needle = this.search.trim().toLowerCase();
                    return this.exemptions.filter((e) => {
                        if (this.filters.status === 'active' && !e.is_active) return false;
                        if (this.filters.status === 'inactive' && e.is_active) return false;
                        if (this.filters.targetType === 'department' && !e.departments.length) return false;
                        if (this.filters.targetType === 'position' && !e.positions.length) return false;
                        if (this.filters.targetType === 'employee' && !e.employees.length) return false;
                        if (this.filters.checklist && !e.checklists.some((c) => String(c.id) === this.filters.checklist)) return false;
                        if (!needle) return true;
                        const haystack = [e.name, e.reason || '', ...e.departments, ...e.positions,
                            ...e.employees.map((emp) => emp.name + ' ' + emp.employee_code),
                            ...e.checklists.flatMap((c) => [c.title, ...c.tasks])].join(' ').toLowerCase();
                        return haystack.includes(needle);
                    });
                },
                totalPages() { return Math.max(1, Math.ceil(this.filtered().length / this.perPage)); },
                paginated() {
                    if (this.page > this.totalPages()) this.page = this.totalPages();
                    return this.filtered().slice((this.page - 1) * this.perPage, this.page * this.perPage);
                },
                clearFilters() {
                    this.search = '';
                    this.filters = { status: '', targetType: '', checklist: '' };
                    this.page = 1;
                },

                // ---- modal open ----
                openCreateModal() {
                    this.editingId = null;
                    this.form = emptyForm();
                    this.resetModalState();
                    this.$dispatch('open-exemption-modal');
                },
                openEditModal(exemption) {
                    this.editingId = exemption.id;
                    this.form = {
                        departments: [...exemption.departments],
                        positions: [...exemption.positions],
                        employee_ids: exemption.employees.map((e) => e.id),
                        item_ids: [...exemption.item_ids],
                    };
                    this.resetModalState();
                    // Open the checklists this rule already touches, so the
                    // current selection is visible straight away.
                    this.expanded = this.checklists.filter((c) => this.selectedCount(c) > 0).map((c) => c.id);
                    this.$dispatch('open-exemption-modal');
                },
                resetModalState() {
                    this.errors = {};
                    this.taskSearch = '';
                    this.expanded = [];
                    Object.values(this.pickers).forEach((p) => { p.query = ''; p.open = false; });
                },
                openViewModal(exemption) {
                    this.viewing = exemption;
                    this.$dispatch('open-view-exemption-modal');
                },

                // ---- targets ----
                targetKey(type) { return { department: 'departments', position: 'positions', employee: 'employee_ids' }[type]; },
                pickerOptions(type) {
                    const needle = this.pickers[type].query.trim().toLowerCase();
                    const selected = this.form[this.targetKey(type)];
                    let options;
                    if (type === 'employee') {
                        options = this.employeeOptions
                            .filter((e) => !selected.includes(e.id))
                            .filter((e) => !needle || e.name.toLowerCase().includes(needle) || (e.employee_code || '').toLowerCase().includes(needle))
                            .map((e) => ({ key: 'e' + e.id, value: e.id, label: e.name + ' (' + e.employee_code + ')', sub: [e.department, e.position].filter(Boolean).join(' · ') }));
                    } else {
                        const source = type === 'department' ? this.departmentOptions : this.positionOptions;
                        options = source
                            .filter((v) => !selected.includes(v))
                            .filter((v) => !needle || v.toLowerCase().includes(needle))
                            .map((v) => ({ key: type + v, value: v, label: v, sub: '' }));
                    }
                    return options.slice(0, 100);
                },
                addTarget(type, option) {
                    const list = this.form[this.targetKey(type)];
                    if (!list.includes(option.value)) list.push(option.value);
                    this.pickers[type].query = '';
                    delete this.errors.targets;
                },
                removeTarget(type, value) {
                    const key = this.targetKey(type);
                    this.form[key] = this.form[key].filter((v) => v !== value);
                },
                selectedChips(type) {
                    if (type === 'employee') {
                        return this.form.employee_ids.map((id) => {
                            const e = this.employeeOptions.find((emp) => emp.id === id);
                            return { key: 'e' + id, value: id, label: e ? e.name : ('Employee #' + id) };
                        });
                    }
                    return this.form[this.targetKey(type)].map((v) => ({ key: type + v, value: v, label: v }));
                },
                fieldError(type) {
                    const key = this.targetKey(type);
                    const match = Object.keys(this.errors).find((k) => k === key || k.startsWith(key + '.'));
                    return match ? this.errors[match][0] : '';
                },

                // ---- checklists/tasks ----
                isExpanded(id) { return this.expanded.includes(id) || this.taskSearch.trim() !== ''; },
                toggleExpanded(id) {
                    this.expanded = this.expanded.includes(id) ? this.expanded.filter((x) => x !== id) : [...this.expanded, id];
                },
                expandAll() { this.expanded = this.checklists.map((c) => c.id); },
                visibleChecklists() {
                    const needle = this.taskSearch.trim().toLowerCase();
                    if (!needle) return this.checklists;
                    return this.checklists.filter((c) => c.title.toLowerCase().includes(needle) || c.tasks.some((t) => t.title.toLowerCase().includes(needle)));
                },
                visibleTasks(checklist) {
                    const needle = this.taskSearch.trim().toLowerCase();
                    if (!needle || checklist.title.toLowerCase().includes(needle)) return checklist.tasks;
                    return checklist.tasks.filter((t) => t.title.toLowerCase().includes(needle));
                },
                selectedCount(checklist) { return checklist.tasks.filter((t) => this.form.item_ids.includes(t.id)).length; },
                toggleTask(id, checked) {
                    this.form.item_ids = checked
                        ? [...new Set([...this.form.item_ids, id])]
                        : this.form.item_ids.filter((x) => x !== id);
                    delete this.errors.item_ids;
                },
                // Per-checklist Select All checkbox: checked when every task is
                // selected, indeterminate when only some are, unchecked when none.
                allSelected(checklist) {
                    return checklist.tasks.length > 0 && this.selectedCount(checklist) === checklist.tasks.length;
                },
                someSelected(checklist) {
                    const count = this.selectedCount(checklist);
                    return count > 0 && count < checklist.tasks.length;
                },
                // Scoped to this one checklist only — never touches another
                // checklist's selection (and always covers all of its tasks,
                // even ones hidden by the task filter).
                setChecklistSelection(checklist, checked) {
                    const ids = checklist.tasks.map((t) => t.id);
                    this.form.item_ids = checked
                        ? [...new Set([...this.form.item_ids, ...ids])]
                        : this.form.item_ids.filter((x) => !ids.includes(x));
                    if (checked) delete this.errors.item_ids;
                },
                orphanedItemCount() {
                    const activeIds = new Set(this.checklists.flatMap((c) => c.tasks.map((t) => t.id)));
                    return this.form.item_ids.filter((id) => !activeIds.has(id)).length;
                },

                // ---- save/delete ----
                submit() {
                    if (this.saving) return;

                    const clientErrors = {};
                    if (!this.form.departments.length && !this.form.positions.length && !this.form.employee_ids.length) {
                        clientErrors.targets = ['Select at least one Department, Position, or Employee for this exemption to apply to.'];
                    }
                    if (!this.form.item_ids.length) clientErrors.item_ids = ['Select at least one checklist task to exempt.'];
                    if (Object.keys(clientErrors).length) {
                        this.errors = clientErrors;
                        return;
                    }

                    this.saving = true;
                    this.errors = {};
                    const url = this.editingId ? `{{ url('task-exemptions') }}/${this.editingId}` : '{{ route('task-exemptions.store') }}';

                    window.fetchWithTimeout(url, {
                        method: this.editingId ? 'PUT' : 'POST',
                        headers: { 'X-CSRF-TOKEN': this.csrfToken(), 'Content-Type': 'application/json', Accept: 'application/json' },
                        body: JSON.stringify(this.form),
                    })
                        .then(async (response) => {
                            const data = await response.json().catch(() => ({}));
                            if (response.status === 422) {
                                this.errors = data.errors || {};
                                throw new Error(data.message || 'Please fix the errors below.');
                            }
                            if (!response.ok) throw new Error(data.message || 'Could not save the task exemption.');
                            return data;
                        })
                        .then((data) => {
                            const index = this.exemptions.findIndex((e) => e.id === data.exemption.id);
                            if (index !== -1) this.exemptions[index] = data.exemption;
                            else this.exemptions.push(data.exemption);
                            this.exemptions.sort((a, b) => a.name.localeCompare(b.name));
                            this.notify('success', data.message);
                            this.$dispatch('close-exemption-modal');
                        })
                        .catch((error) => {
                            if (Object.keys(this.errors).length === 0) {
                                this.notify('error', error?.name === 'AbortError' ? 'This is taking longer than expected. Please check before trying again.' : error.message);
                            }
                        })
                        .finally(() => { this.saving = false; });
                },
                // Sends the state the switch was moved TO; on any failure the
                // switch snaps back to what the server still has.
                toggleStatus(exemption, input) {
                    if (this.togglingId) {
                        input.checked = exemption.is_active;
                        return;
                    }

                    const wanted = input.checked;
                    this.togglingId = exemption.id;

                    window.fetchWithTimeout(`{{ url('task-exemptions') }}/${exemption.id}/toggle-status`, {
                        method: 'PATCH',
                        headers: { 'X-CSRF-TOKEN': this.csrfToken(), 'Content-Type': 'application/json', Accept: 'application/json' },
                        body: JSON.stringify({ is_active: wanted }),
                    })
                        .then(async (response) => {
                            const data = await response.json().catch(() => ({}));
                            if (!response.ok) throw new Error(data.message || 'Could not update the status.');
                            return data;
                        })
                        .then((data) => {
                            const index = this.exemptions.findIndex((e) => e.id === data.exemption.id);
                            if (index !== -1) this.exemptions[index] = data.exemption;
                            if (this.viewing && this.viewing.id === data.exemption.id) this.viewing = data.exemption;
                            this.notify('success', data.message);
                        })
                        .catch((error) => {
                            input.checked = exemption.is_active;
                            this.notify('error', error?.name === 'AbortError' ? 'This is taking longer than expected. Please refresh and check the status.' : error.message);
                        })
                        .finally(() => { this.togglingId = null; });
                },
                confirmDelete(exemption) {
                    window.Swal.fire({
                        title: 'Delete task exemption?',
                        html: `<p>The exemption for ${this.escape(exemption.name)} will no longer apply to new offboarding requests.</p><p style="margin-top:6px;font-size:13px;color:#6b7280">Offboarding requests already submitted keep their exemptions unchanged.</p>`,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Delete',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#dc2626',
                        cancelButtonColor: '#6b7280',
                        reverseButtons: true,
                    }).then((result) => {
                        if (result.isConfirmed) this.deleteExemption(exemption);
                    });
                },
                // Not named `destroy` — Alpine calls a component's `destroy()`
                // automatically when its element is removed (every Turbo
                // navigation away from this page), with no arguments.
                deleteExemption(exemption) {
                    this.deletingId = exemption.id;
                    window.fetchWithTimeout(`{{ url('task-exemptions') }}/${exemption.id}`, {
                        method: 'DELETE',
                        headers: { 'X-CSRF-TOKEN': this.csrfToken(), Accept: 'application/json' },
                    })
                        .then(async (response) => {
                            const data = await response.json().catch(() => ({}));
                            if (!response.ok) throw new Error(data.message || 'Could not delete the task exemption.');
                            return data;
                        })
                        .then((data) => {
                            this.exemptions = this.exemptions.filter((e) => e.id !== exemption.id);
                            this.notify('success', data.message);
                        })
                        .catch((error) => this.notify('error', error.message))
                        .finally(() => { this.deletingId = null; });
                },
                escape(text) {
                    const div = document.createElement('div');
                    div.textContent = text;
                    return div.innerHTML;
                },
            };
        }
    </script>
@endsection
