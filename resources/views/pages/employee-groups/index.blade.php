@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="Employee Master" />

    <div x-data="employeeGroupsWorkspace(@js($groups), @js($employees), @js(session('success')), @js($errors->any() ? $errors->first() : null))">
        <div class="mb-6 flex items-center justify-between">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Organize employees into groups and assign the Group Head/Department Head responsible for each one.
            </p>
            <button type="button" @click="openCreateModal()"
                class="shadow-theme-xs flex items-center justify-center gap-2 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630]">
                <svg class="fill-current" width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M9 3.75V14.25M3.75 9H14.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                Manage Employee
            </button>
        </div>

        @if ($groups->isEmpty())
            <div class="rounded-2xl border border-gray-200 bg-white p-10 text-center dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No groups yet. Click "Create Group" to get started.
                </p>
            </div>
        @else
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3">
                <template x-for="group in groups" :key="group.id">
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 transition-all duration-200 hover:-translate-y-1 hover:border-[#145a3a]/40 hover:shadow-lg dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-[#3aa876]/40">
                        <div class="flex items-start justify-between gap-2">
                            <h4 class="text-base font-semibold text-gray-800 dark:text-white/90" x-text="group.name"></h4>
                            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium"
                                :class="group.is_active ? 'bg-[#145a3a]/10 text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'"
                                x-text="group.is_active ? 'Active' : 'Inactive'"></span>
                        </div>

                        <div class="mt-3 space-y-1.5 text-sm">
                            <p class="text-gray-500 dark:text-gray-400">
                                Group Head:
                                <span class="font-medium text-gray-700 dark:text-gray-300" x-text="group.group_head ? (group.group_head.employee_code + ' – ' + group.group_head.name) : 'Unassigned'"></span>
                            </p>
                            <p class="text-gray-500 dark:text-gray-400">
                                Employees: <span class="font-medium text-gray-700 dark:text-gray-300" x-text="group.employees_count"></span>
                            </p>
                        </div>

                        <div class="mt-4 flex items-center gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                            <button type="button" @click="openViewModal(group)"
                                class="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-[#145a3a] px-3 py-2 text-sm font-medium text-white hover:bg-[#0f4630]">
                                View Employees
                            </button>
                            <button type="button" @click="openEditModal(group)"
                                class="flex items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                Edit
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        @endif

        <!-- EMPLOYEE DIRECTORY -->
        <div class="mt-10 border-t border-gray-200 pt-8 dark:border-gray-800">
            <div class="mb-5">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Employee Directory</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Browse every employee on record and manage their group assignment.
                </p>
            </div>

            <div class="mb-4 flex flex-wrap items-center gap-3">
                <div class="relative min-w-[220px] max-w-sm flex-1">
                    <input type="text" x-model="directorySearch" @input="directoryPage = 1"
                        placeholder="Search employees..."
                        class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none py-2.5 pr-4 pl-11 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
                    <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2">
                        <svg class="fill-gray-500 dark:fill-gray-400" width="18" height="18" viewBox="0 0 20 20" fill="none">
                            <path fill-rule="evenodd" clip-rule="evenodd"
                                d="M3.04175 9.37363C3.04175 5.87693 5.87711 3.04199 9.37508 3.04199C12.8731 3.04199 15.7084 5.87693 15.7084 9.37363C15.7084 12.8703 12.8731 15.7053 9.37508 15.7053C5.87711 15.7053 3.04175 12.8703 3.04175 9.37363ZM9.37508 1.54199C5.04902 1.54199 1.54175 5.04817 1.54175 9.37363C1.54175 13.6991 5.04902 17.2053 9.37508 17.2053C11.2674 17.2053 13.003 16.5344 14.357 15.4176L17.177 18.238C17.4699 18.5309 17.9448 18.5309 18.2377 18.238C18.5306 17.9451 18.5306 17.4703 18.2377 17.1774L15.418 14.3573C16.5365 13.0033 17.2084 11.2669 17.2084 9.37363C17.2084 5.04817 13.7011 1.54199 9.37508 1.54199Z"
                                fill="" />
                        </svg>
                    </span>
                </div>

                <select x-model="directoryFilters.department" @change="directoryPage = 1"
                    class="dark:bg-dark-900 h-11 rounded-lg border border-gray-300 bg-transparent px-3 pr-8 text-sm text-gray-700 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    <option value="">All Departments</option>
                    <template x-for="dept in directoryDepartments()" :key="dept">
                        <option :value="dept" x-text="dept"></option>
                    </template>
                </select>

                <select x-model="directoryFilters.group" @change="directoryPage = 1"
                    class="dark:bg-dark-900 h-11 rounded-lg border border-gray-300 bg-transparent px-3 pr-8 text-sm text-gray-700 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    <option value="">All Groups</option>
                    <template x-for="group in groups" :key="group.id">
                        <option :value="String(group.id)" x-text="group.name"></option>
                    </template>
                </select>

                <select x-model="directoryFilters.groupHead" @change="directoryPage = 1"
                    class="dark:bg-dark-900 h-11 rounded-lg border border-gray-300 bg-transparent px-3 pr-8 text-sm text-gray-700 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    <option value="">All Group Heads</option>
                    <template x-for="head in directoryGroupHeads()" :key="head.id">
                        <option :value="String(head.id)" x-text="head.name"></option>
                    </template>
                </select>

                <select x-model="directoryFilters.status" @change="directoryPage = 1"
                    class="dark:bg-dark-900 h-11 rounded-lg border border-gray-300 bg-transparent px-3 pr-8 text-sm text-gray-700 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="offboarding">Offboarding</option>
                    <option value="offboarded">Offboarded</option>
                </select>

                <select x-model="directoryFilters.groupAssignment" @change="directoryPage = 1"
                    class="dark:bg-dark-900 h-11 rounded-lg border border-gray-300 bg-transparent px-3 pr-8 text-sm text-gray-700 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
                    <option value="">Assigned &amp; Unassigned</option>
                    <option value="assigned">Assigned to a Group</option>
                    <option value="unassigned">Unassigned</option>
                </select>

                <button type="button" x-show="hasDirectoryFilters()" @click="resetDirectoryFilters()"
                    class="text-xs font-medium text-gray-400 underline hover:text-error-500">
                    Clear filters
                </button>
            </div>

            <div class="overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-800">
                <div class="custom-scrollbar overflow-x-auto">
                    <table class="w-full min-w-[900px]">
                        <thead>
                            <tr class="border-b border-gray-100 bg-gray-50 dark:border-gray-800 dark:bg-white/[0.03]">
                                <th class="cursor-pointer px-4 py-3 text-left select-none" @click="toggleDirectorySort('employee_code')">
                                    <span class="inline-flex items-center gap-1 font-medium text-gray-500 text-theme-xs dark:text-gray-400">
                                        Employee No.
                                        <span x-text="directorySortIndicator('employee_code')" class="text-[9px]"></span>
                                    </span>
                                </th>
                                <th class="cursor-pointer px-4 py-3 text-left select-none" @click="toggleDirectorySort('name')">
                                    <span class="inline-flex items-center gap-1 font-medium text-gray-500 text-theme-xs dark:text-gray-400">
                                        Employee Name
                                        <span x-text="directorySortIndicator('name')" class="text-[9px]"></span>
                                    </span>
                                </th>
                                <th class="cursor-pointer px-4 py-3 text-left select-none" @click="toggleDirectorySort('designation')">
                                    <span class="inline-flex items-center gap-1 font-medium text-gray-500 text-theme-xs dark:text-gray-400">
                                        Position
                                        <span x-text="directorySortIndicator('designation')" class="text-[9px]"></span>
                                    </span>
                                </th>
                                <th class="cursor-pointer px-4 py-3 text-left select-none" @click="toggleDirectorySort('department')">
                                    <span class="inline-flex items-center gap-1 font-medium text-gray-500 text-theme-xs dark:text-gray-400">
                                        Department
                                        <span x-text="directorySortIndicator('department')" class="text-[9px]"></span>
                                    </span>
                                </th>
                                <th class="cursor-pointer px-4 py-3 text-left select-none" @click="toggleDirectorySort('group')">
                                    <span class="inline-flex items-center gap-1 font-medium text-gray-500 text-theme-xs dark:text-gray-400">
                                        Group
                                        <span x-text="directorySortIndicator('group')" class="text-[9px]"></span>
                                    </span>
                                </th>
                                <th class="cursor-pointer px-4 py-3 text-left select-none" @click="toggleDirectorySort('group_head')">
                                    <span class="inline-flex items-center gap-1 font-medium text-gray-500 text-theme-xs dark:text-gray-400">
                                        Group Head
                                        <span x-text="directorySortIndicator('group_head')" class="text-[9px]"></span>
                                    </span>
                                </th>
                                <th class="cursor-pointer px-4 py-3 text-left select-none" @click="toggleDirectorySort('status')">
                                    <span class="inline-flex items-center gap-1 font-medium text-gray-500 text-theme-xs dark:text-gray-400">
                                        Status
                                        <span x-text="directorySortIndicator('status')" class="text-[9px]"></span>
                                    </span>
                                </th>
                                <th class="px-4 py-3 text-right"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Action</p></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="employee in paginatedDirectoryEmployees()" :key="employee.id">
                                <tr class="border-b border-gray-100 last:border-b-0 dark:border-gray-800">
                                    <td class="px-4 py-3 text-sm text-gray-800 dark:text-white/90" x-text="employee.employee_code"></td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300"
                                                x-text="initials(employee.name)"></div>
                                            <span class="text-sm font-medium text-gray-800 dark:text-white/90" x-text="employee.name"></span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400" x-text="employee.designation || '—'"></td>
                                    <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400" x-text="employee.department || '—'"></td>
                                    <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400" x-text="employee.employee_group ? employee.employee_group.name : 'Unassigned'"></td>
                                    <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400" x-text="employee.employee_group && employee.employee_group.group_head ? employee.employee_group.group_head.name : '—'"></td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusBadgeClass(employee.status)" x-text="statusLabel(employee.status)"></span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <button type="button" @click="openViewEmployeeModal(employee)"
                                                class="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                                View
                                            </button>
                                            <button type="button" @click="openAssignGroupModal(employee)"
                                                class="rounded-md bg-[#145a3a]/10 px-2.5 py-1 text-xs font-medium text-[#145a3a] hover:bg-[#145a3a]/20 dark:text-[#3aa876]">
                                                Assign to Group
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="paginatedDirectoryEmployees().length === 0">
                                <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-400">No employees match your search/filters.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Showing <span x-text="directoryTotal() === 0 ? 0 : (directoryPage - 1) * directoryPerPage + 1"></span>–<span x-text="Math.min(directoryPage * directoryPerPage, directoryTotal())"></span> of <span x-text="directoryTotal()"></span> employees
                </p>
                <div class="flex items-center gap-1.5" x-show="directoryTotalPages() > 1">
                    <button type="button" @click="goToDirectoryPage(directoryPage - 1)" :disabled="directoryPage === 1"
                        class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                        Previous
                    </button>
                    <template x-for="(page, idx) in directoryPageNumbers()" :key="idx">
                        <button type="button" @click="goToDirectoryPage(page)" x-text="page" :disabled="page === '...'"
                            :class="page === directoryPage ? 'bg-[#145a3a] text-white' : 'border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5'"
                            class="min-w-[32px] rounded-md px-2.5 py-1.5 text-xs font-medium disabled:cursor-default">
                        </button>
                    </template>
                    <button type="button" @click="goToDirectoryPage(directoryPage + 1)" :disabled="directoryPage === directoryTotalPages()"
                        class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                        Next
                    </button>
                </div>
            </div>
        </div>

        <!-- CREATE / EDIT GROUP MODAL -->
        <x-ui.modal x-data="{ open: false }" @open-group-modal.window="open = true" :isOpen="false" class="max-w-[480px]">
            <div class="no-scrollbar relative w-full max-w-[480px] overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-cloak>
                <h4 class="mb-5 text-xl font-semibold text-gray-800 dark:text-white/90" x-text="editingGroup ? 'Edit Group' : 'Create Group'"></h4>

                <form method="POST" :action="editingGroup ? `/employee-groups/${editingGroup.id}` : '/employee-groups'" x-data="{ processing: false }" @submit="processing = true">
                    @csrf
                    <template x-if="editingGroup">
                        <input type="hidden" name="_method" value="PUT" />
                    </template>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Group Name <span class="text-error-500">*</span>
                        </label>
                        <input type="text" name="name" x-model="groupForm.name" required placeholder="e.g. IT Department"
                            class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
                    </div>

                    <div class="relative mt-5" @click.away="groupHeadDropdownOpen = false">
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Group Head / Department Head
                        </label>
                        <input type="hidden" name="group_head_employee_id" :value="groupForm.group_head_employee_id" />
                        <div class="relative">
                            <input type="text" x-model="groupHeadQuery" autocomplete="off"
                                @focus="groupHeadDropdownOpen = true"
                                @input="groupForm.group_head_employee_id = ''; groupHeadDropdownOpen = true"
                                placeholder="Search employee..."
                                class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 pr-9 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
                            <button type="button" x-show="groupForm.group_head_employee_id" @click="groupForm.group_head_employee_id = ''; groupHeadQuery = ''"
                                class="absolute top-1/2 right-3 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                                <svg width="16" height="16" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M13.5 4.5L4.5 13.5M4.5 4.5L13.5 13.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                        </div>
                        <div x-show="groupHeadDropdownOpen"
                            class="shadow-theme-lg absolute z-50 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                            <template x-for="employee in filteredEmployeesForHead()" :key="employee.id">
                                <div @click="selectGroupHead(employee)"
                                    class="cursor-pointer border-b border-gray-100 px-4 py-2.5 text-sm last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                                    <span class="text-gray-800 dark:text-white/90" x-text="employee.name + ' (' + employee.employee_code + ')'"></span>
                                    <span class="block text-xs text-gray-400" x-text="employee.department"></span>
                                </div>
                            </template>
                            <div x-show="filteredEmployeesForHead().length === 0" class="px-4 py-2.5 text-sm text-gray-400">
                                No employees found
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 flex items-center gap-2">
                        <input type="checkbox" id="groupIsActive" name="is_active" value="1" x-model="groupForm.is_active"
                            class="h-4 w-4 rounded border-gray-300 text-[#145a3a] accent-[#145a3a] focus:ring-[#145a3a]/40 dark:border-gray-700" />
                        <label for="groupIsActive" class="text-sm font-medium text-gray-700 dark:text-gray-400">
                            Active
                        </label>
                    </div>

                    <div class="mt-7 flex items-center gap-3 lg:justify-end">
                        <button @click="open = false" type="button"
                            class="flex w-full justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03] sm:w-auto">
                            Cancel
                        </button>
                        <button type="submit" :disabled="processing"
                            :class="processing ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#0f4630]'"
                            class="flex w-full justify-center rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white sm:w-auto">
                            <span x-text="editingGroup ? 'Update Group' : 'Save Group'"></span>
                        </button>
                    </div>
                </form>
            </div>
        </x-ui.modal>

        <!-- VIEW EMPLOYEES MODAL -->
        <x-ui.modal x-data="{ open: false }" @open-view-group-modal.window="open = true" :isOpen="false" class="max-w-[75vw]">
            <div class="no-scrollbar relative max-h-[85vh] w-full max-w-[75vw] overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="viewingGroup" x-cloak>
                <template x-if="viewingGroup">
                    <div>
                        <div class="flex items-start justify-between gap-2 pr-12 sm:pr-16">
                            <div>
                                <h4 class="text-xl font-semibold text-gray-800 dark:text-white/90" x-text="viewingGroup.name"></h4>
                                <p class="mt-1 text-sm text-[#145a3a] dark:text-[#3aa876]">
                                    Group Head: <span x-text="viewingGroup.group_head ? (viewingGroup.group_head.employee_code + ' – ' + viewingGroup.group_head.name) : 'Unassigned'"></span>
                                </p>
                            </div>
                            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium"
                                :class="viewingGroup.is_active ? 'bg-[#145a3a]/10 text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'"
                                x-text="viewingGroup.is_active ? 'Active' : 'Inactive'"></span>
                        </div>

                        <h5 class="mb-2 mt-6 text-sm font-semibold text-gray-800 dark:text-white/90">
                            Members (<span x-text="currentGroupEmployees().length"></span>)
                        </h5>
                        <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-800">
                            <div class="max-h-56 overflow-y-auto custom-scrollbar">
                                <table class="w-full min-w-[500px]">
                                    <thead>
                                        <tr class="border-b border-gray-100 bg-gray-50 dark:border-gray-800 dark:bg-white/[0.03]">
                                            <th class="px-4 py-2.5 text-left"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Name</p></th>
                                            <th class="px-4 py-2.5 text-left"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Position</p></th>
                                            <th class="px-4 py-2.5 text-left"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Department</p></th>
                                            <th class="px-4 py-2.5 text-right"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Action</p></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="employee in currentGroupEmployees()" :key="employee.id">
                                            <tr class="border-b border-gray-100 last:border-b-0 dark:border-gray-800">
                                                <td class="px-4 py-2.5 text-sm text-gray-800 dark:text-white/90">
                                                    <span x-text="employee.employee_code"></span> – <span x-text="employee.name"></span>
                                                </td>
                                                <td class="px-4 py-2.5 text-sm text-gray-500 dark:text-gray-400" x-text="employee.designation || '—'"></td>
                                                <td class="px-4 py-2.5 text-sm text-gray-500 dark:text-gray-400" x-text="employee.department || '—'"></td>
                                                <td class="px-4 py-2.5 text-right">
                                                    <button type="button" @click="removeEmployeeFromGroup(employee)"
                                                        class="rounded-md border border-error-300 px-2.5 py-1 text-xs font-medium text-error-500 hover:bg-error-50 dark:border-error-500/30 dark:hover:bg-error-500/10">
                                                        Remove
                                                    </button>
                                                </td>
                                            </tr>
                                        </template>
                                        <tr x-show="currentGroupEmployees().length === 0">
                                            <td colspan="4" class="px-4 py-6 text-center text-sm text-gray-400">No employees in this group yet.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <h5 class="mb-2 mt-6 text-sm font-semibold text-gray-800 dark:text-white/90">Add Employee</h5>
                        <input type="text" x-model="employeeSearchQuery" placeholder="Search by name, employee number, or department..."
                            class="dark:bg-dark-900 mb-3 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />

                        <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-800">
                            <div class="max-h-64 overflow-y-auto custom-scrollbar">
                                <table class="w-full min-w-[640px]">
                                    <thead>
                                        <tr class="border-b border-gray-100 bg-gray-50 dark:border-gray-800 dark:bg-white/[0.03]">
                                            <th class="px-4 py-2.5 text-left"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Employee</p></th>
                                            <th class="px-4 py-2.5 text-left"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Position</p></th>
                                            <th class="px-4 py-2.5 text-left"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Department</p></th>
                                            <th class="px-4 py-2.5 text-left"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Assigned Group</p></th>
                                            <th class="px-4 py-2.5 text-left"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Group Head</p></th>
                                            <th class="px-4 py-2.5 text-right"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Action</p></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="employee in filteredEmployeesForAdd()" :key="employee.id">
                                            <tr class="border-b border-gray-100 last:border-b-0 dark:border-gray-800">
                                                <td class="px-4 py-2.5 text-sm text-gray-800 dark:text-white/90">
                                                    <span x-text="employee.employee_code"></span> – <span x-text="employee.name"></span>
                                                </td>
                                                <td class="px-4 py-2.5 text-sm text-gray-500 dark:text-gray-400" x-text="employee.designation || '—'"></td>
                                                <td class="px-4 py-2.5 text-sm text-gray-500 dark:text-gray-400" x-text="employee.department || '—'"></td>
                                                <td class="px-4 py-2.5 text-sm text-gray-500 dark:text-gray-400" x-text="employee.employee_group ? employee.employee_group.name : '—'"></td>
                                                <td class="px-4 py-2.5 text-sm text-gray-500 dark:text-gray-400" x-text="employee.employee_group && employee.employee_group.group_head ? employee.employee_group.group_head.name : '—'"></td>
                                                <td class="px-4 py-2.5 text-right">
                                                    <template x-if="employee.employee_group_id === viewingGroup.id">
                                                        <span class="text-xs font-medium text-gray-400">Already added</span>
                                                    </template>
                                                    <template x-if="employee.employee_group_id !== viewingGroup.id">
                                                        <button type="button" @click="addEmployeeToGroup(employee)"
                                                            :disabled="addingEmployeeId === employee.id"
                                                            :class="addingEmployeeId === employee.id ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#145a3a]/20'"
                                                            class="rounded-md bg-[#145a3a]/10 px-2.5 py-1 text-xs font-medium text-[#145a3a] dark:text-[#3aa876]">
                                                            Add
                                                        </button>
                                                    </template>
                                                </td>
                                            </tr>
                                        </template>
                                        <tr x-show="filteredEmployeesForAdd().length === 0">
                                            <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-400">No employees found.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
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

        <!-- VIEW EMPLOYEE (DIRECTORY) MODAL -->
        <x-ui.modal x-data="{ open: false }" @open-view-employee-modal.window="open = true" :isOpen="false" class="max-w-[50vw]">
            <div class="no-scrollbar relative w-full max-w-[50vw] overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="viewingEmployee" x-cloak>
                <template x-if="viewingEmployee">
                    <div>
                        <div class="flex items-center gap-3">
                            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-gray-100 text-base font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300"
                                x-text="initials(viewingEmployee.name)"></div>
                            <div>
                                <h4 class="text-lg font-semibold text-gray-800 dark:text-white/90" x-text="viewingEmployee.name"></h4>
                                <p class="text-sm text-gray-500 dark:text-gray-400" x-text="viewingEmployee.employee_code"></p>
                            </div>
                        </div>

                        <div class="mt-5 space-y-3 text-sm">
                            <div class="flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-800">
                                <span class="text-gray-400">Email</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300" x-text="viewingEmployee.email || '—'"></span>
                            </div>
                            <div class="flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-800">
                                <span class="text-gray-400">Position</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300" x-text="viewingEmployee.designation || '—'"></span>
                            </div>
                            <div class="flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-800">
                                <span class="text-gray-400">Department</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300" x-text="viewingEmployee.department || '—'"></span>
                            </div>
                            <div class="flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-800">
                                <span class="text-gray-400">Group</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300" x-text="viewingEmployee.employee_group ? viewingEmployee.employee_group.name : 'Unassigned'"></span>
                            </div>
                            <div class="flex items-center justify-between border-b border-gray-100 pb-2 dark:border-gray-800">
                                <span class="text-gray-400">Group Head</span>
                                <span class="font-medium text-gray-700 dark:text-gray-300" x-text="viewingEmployee.employee_group && viewingEmployee.employee_group.group_head ? viewingEmployee.employee_group.group_head.name : '—'"></span>
                            </div>
                            <div class="flex items-center justify-between pb-2">
                                <span class="text-gray-400">Status</span>
                                <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusBadgeClass(viewingEmployee.status)" x-text="statusLabel(viewingEmployee.status)"></span>
                            </div>
                        </div>

                        <div class="mt-6 flex items-center justify-end gap-3">
                            <button @click="open = false" type="button"
                                class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                                Close
                            </button>
                            <button type="button" @click="open = false; openAssignGroupModal(viewingEmployee)"
                                class="flex justify-center rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630]">
                                Assign to Group
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </x-ui.modal>

        <!-- ASSIGN TO GROUP (DIRECTORY) MODAL -->
        <x-ui.modal x-data="{ open: false }" @open-assign-group-modal.window="open = true" @close-assign-group-modal.window="open = false" :isOpen="false" class="max-w-[50vw]">
            <div class="no-scrollbar relative w-full max-w-[50vw] overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="assigningEmployee" x-cloak>
                <template x-if="assigningEmployee">
                    <div>
                        <h4 class="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">Assign to Group</h4>
                        <p class="mb-5 text-sm text-gray-500 dark:text-gray-400">
                            <span x-text="assigningEmployee.employee_code"></span> &ndash; <span x-text="assigningEmployee.name"></span>
                        </p>

                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Group
                        </label>
                        <select x-model="assignGroupId"
                            class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800">
                            <option value="">Unassigned (no group)</option>
                            <template x-for="group in groups" :key="group.id">
                                <option :value="String(group.id)" x-text="group.name"></option>
                            </template>
                        </select>

                        <div class="mt-7 flex items-center gap-3 lg:justify-end">
                            <button @click="open = false" type="button"
                                class="flex w-full justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03] sm:w-auto">
                                Cancel
                            </button>
                            <button type="button" @click="saveAssignGroup()" :disabled="assigningInFlight"
                                :class="assigningInFlight ? 'opacity-50 cursor-not-allowed' : 'hover:bg-[#0f4630]'"
                                class="flex w-full justify-center rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white sm:w-auto">
                                <span x-text="assigningInFlight ? 'Saving...' : 'Save'"></span>
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </x-ui.modal>
    </div>

    <script>
        function employeeGroupsWorkspace(initialGroups, initialEmployees, flashSuccess, flashError) {
            return {
                groups: initialGroups,
                employees: initialEmployees,

                editingGroup: null,
                groupForm: { name: '', group_head_employee_id: '', is_active: true },
                groupHeadQuery: '',
                groupHeadDropdownOpen: false,

                viewingGroup: null,
                employeeSearchQuery: '',
                addingEmployeeId: null,

                // Employee Directory (search/sort/filter/paginate over the
                // same shared `employees` array the group cards above use —
                // so a group assignment change from either place stays in
                // sync everywhere with no extra wiring).
                directorySearch: '',
                directoryFilters: { department: '', group: '', groupHead: '', status: '', groupAssignment: '' },
                directorySort: { column: 'employee_code', direction: 'asc' },
                directoryPage: 1,
                directoryPerPage: 10,
                viewingEmployee: null,
                assigningEmployee: null,
                assignGroupId: '',
                assigningInFlight: false,

                init() {
                    if (flashSuccess) {
                        this.notify('success', flashSuccess);
                    } else if (flashError) {
                        this.notify('error', flashError);
                    }
                },
                notify(icon, title) {
                    window.Swal?.fire({
                        toast: true,
                        position: 'bottom-end',
                        icon,
                        title,
                        showConfirmButton: false,
                        timer: icon === 'success' ? 2000 : 2500,
                        timerProgressBar: icon === 'success',
                        customClass: { container: 'app-toast' },
                    });
                },
                openCreateModal() {
                    this.editingGroup = null;
                    this.groupForm = { name: '', group_head_employee_id: '', is_active: true };
                    this.groupHeadQuery = '';
                    this.$dispatch('open-group-modal');
                },
                openEditModal(group) {
                    this.editingGroup = group;
                    this.groupForm = {
                        name: group.name,
                        group_head_employee_id: group.group_head_employee_id ? String(group.group_head_employee_id) : '',
                        is_active: group.is_active,
                    };
                    this.groupHeadQuery = group.group_head ? `${group.group_head.name} (${group.group_head.employee_code})` : '';
                    this.$dispatch('open-group-modal');
                },
                selectGroupHead(employee) {
                    this.groupForm.group_head_employee_id = String(employee.id);
                    this.groupHeadQuery = `${employee.name} (${employee.employee_code})`;
                    this.groupHeadDropdownOpen = false;
                },
                filteredEmployeesForHead() {
                    const needle = this.groupHeadQuery.toLowerCase();
                    if (!needle) return this.employees;
                    return this.employees.filter((e) =>
                        e.name.toLowerCase().includes(needle) || e.employee_code.toLowerCase().includes(needle)
                    );
                },
                openViewModal(group) {
                    this.viewingGroup = group;
                    this.employeeSearchQuery = '';
                    this.$dispatch('open-view-group-modal');
                },
                currentGroupEmployees() {
                    if (!this.viewingGroup) return [];
                    // Read live from the master `employees` list (keyed by
                    // group id) so a just-added/removed member reflects
                    // immediately without needing a full page reload.
                    return this.employees.filter((e) => e.employee_group_id === this.viewingGroup.id);
                },
                filteredEmployeesForAdd() {
                    const needle = this.employeeSearchQuery.toLowerCase();
                    let list = this.employees;
                    if (needle) {
                        list = list.filter((e) =>
                            e.name.toLowerCase().includes(needle)
                            || e.employee_code.toLowerCase().includes(needle)
                            || (e.department || '').toLowerCase().includes(needle)
                        );
                    }
                    return list.slice(0, 25);
                },
                csrfToken() {
                    const meta = document.querySelector('meta[name="csrf-token"]');
                    return meta ? meta.content : '';
                },
                // Adds a member without a full-page reload: POSTs with
                // `Accept: application/json` so the controller (already
                // JSON-aware, same convention as the template-status toggle
                // elsewhere in this app) returns the updated employee record
                // instead of redirecting. The Members table, the "Add
                // Employee" table, and the group card's employee count all
                // update immediately via `applyEmployeeUpdate()` below, since
                // they're all derived reactively from the same `employees`/
                // `groups` arrays.
                addEmployeeToGroup(employee) {
                    if (this.addingEmployeeId === employee.id) return;
                    this.addingEmployeeId = employee.id;

                    fetch(`/employee-groups/${this.viewingGroup.id}/employees`, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': this.csrfToken(),
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                        },
                        body: JSON.stringify({ employee_id: employee.id }),
                    })
                        .then(async (response) => {
                            const data = await response.json().catch(() => ({}));
                            if (!response.ok) {
                                throw new Error(data.message || 'Could not add employee to the group.');
                            }
                            return data;
                        })
                        .then((data) => {
                            this.applyEmployeeUpdate(data.employee);
                            this.employeeSearchQuery = '';
                            this.notify('success', 'Employee successfully added to the group.');
                        })
                        .catch((error) => {
                            // Covers both the duplicate-assignment case (422,
                            // "X is already in this group.") and any other
                            // failure — surfaced as a validation-style toast
                            // rather than silently doing nothing.
                            this.notify('error', error.message);
                        })
                        .finally(() => {
                            this.addingEmployeeId = null;
                        });
                },
                // Same reactive pattern as addEmployeeToGroup(), for Remove —
                // keeps the existing Swal confirmation, just no longer
                // submits a real form/reloads the page.
                removeEmployeeFromGroup(employee) {
                    Swal.fire({
                        title: 'Remove this employee from the group?',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Remove',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#dc2626',
                        cancelButtonColor: '#145a3a',
                        reverseButtons: true,
                    }).then((result) => {
                        if (!result.isConfirmed) return;

                        fetch(`/employee-groups/${this.viewingGroup.id}/employees/${employee.id}`, {
                            method: 'DELETE',
                            headers: {
                                'X-CSRF-TOKEN': this.csrfToken(),
                                Accept: 'application/json',
                            },
                        })
                            .then(async (response) => {
                                const data = await response.json().catch(() => ({}));
                                if (!response.ok) {
                                    throw new Error(data.message || 'Could not remove employee from the group.');
                                }
                                return data;
                            })
                            .then((data) => {
                                this.applyEmployeeUpdate(data.employee);
                                this.notify('success', 'Employee removed from the group.');
                            })
                            .catch((error) => {
                                this.notify('error', error.message);
                            });
                    });
                },
                // Merges the server's fresh employee record back into the
                // shared `employees` list (the single source of truth every
                // table/card on this page reads from) and keeps each
                // affected group's `employees_count` badge in sync — this is
                // what makes every part of the page update immediately
                // without a reload.
                applyEmployeeUpdate(updatedEmployee) {
                    const index = this.employees.findIndex((e) => e.id === updatedEmployee.id);
                    const previousGroupId = index !== -1 ? this.employees[index].employee_group_id : null;

                    if (index !== -1) {
                        this.employees[index] = updatedEmployee;
                    } else {
                        this.employees.push(updatedEmployee);
                    }

                    if (previousGroupId !== updatedEmployee.employee_group_id) {
                        const previousGroup = this.groups.find((g) => g.id === previousGroupId);
                        if (previousGroup) previousGroup.employees_count--;

                        const newGroup = this.groups.find((g) => g.id === updatedEmployee.employee_group_id);
                        if (newGroup) newGroup.employees_count++;
                    }
                },

                // ---- Employee Directory ----

                initials(name) {
                    return (name || '')
                        .split(' ')
                        .filter(Boolean)
                        .slice(0, 2)
                        .map((part) => part[0].toUpperCase())
                        .join('');
                },
                statusLabel(status) {
                    return { active: 'Active', offboarding: 'Offboarding', offboarded: 'Offboarded' }[status] || (status || '—');
                },
                statusBadgeClass(status) {
                    return {
                        active: 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400',
                        offboarding: 'bg-yellow-50 text-yellow-700 dark:bg-yellow-500/15 dark:text-yellow-400',
                        offboarded: 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400',
                    }[status] || 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400';
                },
                directoryDepartments() {
                    return [...new Set(this.employees.map((e) => e.department).filter(Boolean))].sort();
                },
                directoryGroupHeads() {
                    const seen = new Map();
                    this.groups.forEach((g) => {
                        if (g.group_head && !seen.has(g.group_head.id)) {
                            seen.set(g.group_head.id, g.group_head);
                        }
                    });
                    return Array.from(seen.values()).sort((a, b) => a.name.localeCompare(b.name));
                },
                hasDirectoryFilters() {
                    return Boolean(
                        this.directorySearch
                        || this.directoryFilters.department
                        || this.directoryFilters.group
                        || this.directoryFilters.groupHead
                        || this.directoryFilters.status
                        || this.directoryFilters.groupAssignment
                    );
                },
                resetDirectoryFilters() {
                    this.directorySearch = '';
                    this.directoryFilters = { department: '', group: '', groupHead: '', status: '', groupAssignment: '' };
                    this.directoryPage = 1;
                },
                directorySortValue(employee, column) {
                    switch (column) {
                        case 'employee_code': return employee.employee_code || '';
                        case 'name': return employee.name || '';
                        case 'designation': return employee.designation || '';
                        case 'department': return employee.department || '';
                        case 'group': return employee.employee_group ? employee.employee_group.name : '';
                        case 'group_head': return (employee.employee_group && employee.employee_group.group_head) ? employee.employee_group.group_head.name : '';
                        case 'status': return employee.status || '';
                        default: return '';
                    }
                },
                toggleDirectorySort(column) {
                    if (this.directorySort.column === column) {
                        this.directorySort.direction = this.directorySort.direction === 'asc' ? 'desc' : 'asc';
                    } else {
                        this.directorySort.column = column;
                        this.directorySort.direction = 'asc';
                    }
                    this.directoryPage = 1;
                },
                directorySortIndicator(column) {
                    if (this.directorySort.column !== column) return '';
                    return this.directorySort.direction === 'asc' ? '▲' : '▼';
                },
                filteredDirectoryEmployees() {
                    let list = this.employees;
                    const needle = this.directorySearch.trim().toLowerCase();

                    if (needle) {
                        list = list.filter((e) =>
                            e.name.toLowerCase().includes(needle)
                            || e.employee_code.toLowerCase().includes(needle)
                            || (e.department || '').toLowerCase().includes(needle)
                            || (e.designation || '').toLowerCase().includes(needle)
                        );
                    }

                    const filters = this.directoryFilters;
                    if (filters.department) list = list.filter((e) => e.department === filters.department);
                    if (filters.group) list = list.filter((e) => String(e.employee_group_id) === filters.group);
                    if (filters.groupHead) {
                        list = list.filter((e) => e.employee_group && e.employee_group.group_head && String(e.employee_group.group_head.id) === filters.groupHead);
                    }
                    if (filters.status) list = list.filter((e) => e.status === filters.status);
                    if (filters.groupAssignment === 'assigned') list = list.filter((e) => e.employee_group_id !== null);
                    if (filters.groupAssignment === 'unassigned') list = list.filter((e) => e.employee_group_id === null);

                    const { column, direction } = this.directorySort;
                    list = [...list].sort((a, b) => {
                        const av = this.directorySortValue(a, column);
                        const bv = this.directorySortValue(b, column);
                        if (av < bv) return direction === 'asc' ? -1 : 1;
                        if (av > bv) return direction === 'asc' ? 1 : -1;
                        return 0;
                    });

                    return list;
                },
                directoryTotal() {
                    return this.filteredDirectoryEmployees().length;
                },
                directoryTotalPages() {
                    return Math.max(1, Math.ceil(this.directoryTotal() / this.directoryPerPage));
                },
                paginatedDirectoryEmployees() {
                    const filtered = this.filteredDirectoryEmployees();
                    const start = (this.directoryPage - 1) * this.directoryPerPage;
                    return filtered.slice(start, start + this.directoryPerPage);
                },
                directoryPageNumbers() {
                    const total = this.directoryTotalPages();
                    const current = Math.min(this.directoryPage, total);
                    const range = [];

                    for (let i = Math.max(1, current - 1); i <= Math.min(total, current + 1); i++) {
                        range.push(i);
                    }
                    if (range[0] > 1) {
                        if (range[0] > 2) range.unshift('...');
                        range.unshift(1);
                    }
                    if (range[range.length - 1] < total) {
                        if (range[range.length - 1] < total - 1) range.push('...');
                        range.push(total);
                    }

                    return range;
                },
                goToDirectoryPage(page) {
                    if (page === '...') return;
                    this.directoryPage = Math.min(Math.max(1, page), this.directoryTotalPages());
                },
                openViewEmployeeModal(employee) {
                    this.viewingEmployee = employee;
                    this.$dispatch('open-view-employee-modal');
                },
                openAssignGroupModal(employee) {
                    this.assigningEmployee = employee;
                    this.assignGroupId = employee.employee_group_id ? String(employee.employee_group_id) : '';
                    this.assigningInFlight = false;
                    this.$dispatch('open-assign-group-modal');
                },
                // Reuses the exact same group-membership endpoints the group
                // cards' "Add"/"Remove" actions already call — this modal is
                // just a different entry point into the same underlying
                // "move this employee's `employee_group_id`" operation, so
                // no new backend route was needed.
                saveAssignGroup() {
                    if (!this.assigningEmployee || this.assigningInFlight) return;

                    const employee = this.assigningEmployee;
                    const currentGroupId = employee.employee_group_id;
                    const newGroupId = this.assignGroupId ? parseInt(this.assignGroupId, 10) : null;

                    if (currentGroupId === newGroupId) {
                        this.$dispatch('close-assign-group-modal');
                        return;
                    }

                    this.assigningInFlight = true;

                    const request = newGroupId
                        ? fetch(`/employee-groups/${newGroupId}/employees`, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': this.csrfToken(),
                                'Content-Type': 'application/json',
                                Accept: 'application/json',
                            },
                            body: JSON.stringify({ employee_id: employee.id }),
                        })
                        : fetch(`/employee-groups/${currentGroupId}/employees/${employee.id}`, {
                            method: 'DELETE',
                            headers: {
                                'X-CSRF-TOKEN': this.csrfToken(),
                                Accept: 'application/json',
                            },
                        });

                    request
                        .then(async (response) => {
                            const data = await response.json().catch(() => ({}));
                            if (!response.ok) {
                                throw new Error(data.message || 'Could not update this employee\'s group assignment.');
                            }
                            return data;
                        })
                        .then((data) => {
                            this.applyEmployeeUpdate(data.employee);
                            this.notify('success', newGroupId ? 'Employee assigned to group.' : 'Employee unassigned from group.');
                            this.$dispatch('close-assign-group-modal');
                        })
                        .catch((error) => {
                            this.notify('error', error.message);
                        })
                        .finally(() => {
                            this.assigningInFlight = false;
                        });
                },
            };
        }
    </script>
@endsection
