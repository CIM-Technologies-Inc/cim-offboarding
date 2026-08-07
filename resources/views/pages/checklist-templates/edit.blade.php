@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="Edit Checklist Template" />

    @if ($errors->any())
        <div class="mb-6 rounded-lg border border-error-500 bg-error-50 px-4 py-3 text-sm text-error-600 dark:border-error-500/30 dark:bg-error-500/10 dark:text-error-400">
            <ul class="list-inside list-disc space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div x-data="checklistBuilder(@js(old('title', $template->title)), @js(old('department', $template->department)), @js(old('items', $template->items->map(fn ($item) => ['title' => $item->title, 'signatory_id' => (string) $item->signatory_id])->values())), @js($employees->map(fn ($employee) => ['id' => (string) $employee->id, 'name' => $employee->name, 'department' => $employee->department])))"
        class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:p-6">
        <form method="POST" action="{{ route('checklist-templates.update', $template) }}" class="flex flex-col">
            @csrf
            @method('PUT')

            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    List Title
                </label>
                <input type="text" name="title" x-model="title" placeholder="e.g. IT Clearance Checklist"
                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
            </div>

            <div class="mt-5">
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    For Department
                </label>
                <select name="department" x-model="department"
                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800">
                    <option value="" disabled>Select department</option>
                    <option value="HR">HR</option>
                    <option value="IT">IT</option>
                    <option value="Accounting">Accounting</option>
                    <option value="Sales">Sales</option>
                </select>
            </div>

            <div class="mt-7">
                <div class="mb-4 flex items-center justify-between">
                    <h5 class="text-lg font-medium text-gray-800 dark:text-white/90">
                        Clearance Items
                    </h5>
                    <button type="button" @click="addItem()"
                        class="shadow-theme-xs flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                        <svg class="fill-current" width="16" height="16" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M9 3.75V14.25M3.75 9H14.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        Add Item
                    </button>
                </div>

                <div class="space-y-4">
                    <template x-for="(item, index) in items" :key="index">
                        <div class="flex flex-col gap-3 rounded-xl border border-gray-200 p-4 dark:border-gray-800 sm:flex-row sm:items-start">
                            <div class="flex-1">
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Items for turn over
                                </label>
                                <input type="text" x-model="item.title" :name="`items[${index}][title]`" placeholder="e.g. Return company laptop"
                                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
                            </div>
                            <div class="relative sm:w-72" @click.away="item.signatory_open = false">
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Signatory
                                </label>
                                <input type="hidden" :name="`items[${index}][signatory_id]`" :value="item.signatory_id" />
                                <div class="relative">
                                    <input type="text" x-model="item.signatory_query" autocomplete="off"
                                        @focus="item.signatory_open = true"
                                        @input="item.signatory_id = ''; item.signatory_open = true"
                                        placeholder="Search employee..."
                                        class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 pr-9 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
                                    <button type="button" x-show="item.signatory_id" @click="clearSignatory(item)"
                                        class="absolute top-1/2 right-3 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                                        <svg width="16" height="16" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M13.5 4.5L4.5 13.5M4.5 4.5L13.5 13.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </button>
                                </div>

                                <div x-show="item.signatory_open"
                                    class="shadow-theme-lg absolute z-50 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                                    <template x-for="employee in filteredEmployees(item.signatory_query)" :key="employee.id">
                                        <div @click="selectSignatory(item, employee)"
                                            class="cursor-pointer border-b border-gray-100 px-4 py-2.5 text-sm last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.03]">
                                            <span class="text-gray-800 dark:text-white/90" x-text="employee.name"></span>
                                            <span class="block text-xs text-gray-400" x-text="employee.department"></span>
                                        </div>
                                    </template>
                                    <div x-show="filteredEmployees(item.signatory_query).length === 0"
                                        class="px-4 py-2.5 text-sm text-gray-400">
                                        No employees found
                                    </div>
                                </div>
                            </div>
                            <div class="flex sm:pt-8">
                                <button type="button" @click="removeItem(index)" x-show="items.length > 1"
                                    class="flex h-11 w-11 items-center justify-center rounded-lg border border-gray-300 text-error-500 hover:bg-error-50 dark:border-gray-700 dark:hover:bg-error-500/10">
                                    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M13.5 4.5L4.5 13.5M4.5 4.5L13.5 13.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <div class="mt-7 flex items-center gap-3 lg:justify-end">
                <a href="{{ route('checklist-templates.index') }}"
                    class="flex w-full justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03] sm:w-auto">
                    Cancel
                </a>
                <button type="submit"
                    class="flex w-full justify-center rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630] sm:w-auto">
                    Update Template
                </button>
            </div>
        </form>
    </div>

    <script>
        function checklistBuilder(initialTitle, initialDepartment, initialItems, employees) {
            return {
                title: initialTitle,
                department: initialDepartment,
                employees: employees,
                items: [],
                init() {
                    const source = initialItems.length ? initialItems : [{ title: '', signatory_id: '' }];
                    this.items = source.map((item) => ({
                        title: item.title || '',
                        signatory_id: item.signatory_id || '',
                        signatory_query: this.labelFor(item.signatory_id),
                        signatory_open: false,
                    }));
                },
                labelFor(id) {
                    const employee = this.employees.find((e) => e.id === String(id));
                    return employee ? employee.name : '';
                },
                filteredEmployees(query) {
                    if (!query) {
                        return this.employees;
                    }
                    const needle = query.toLowerCase();
                    return this.employees.filter((e) =>
                        e.name.toLowerCase().includes(needle) || e.department.toLowerCase().includes(needle)
                    );
                },
                selectSignatory(item, employee) {
                    item.signatory_id = employee.id;
                    item.signatory_query = employee.name;
                    item.signatory_open = false;
                },
                clearSignatory(item) {
                    item.signatory_id = '';
                    item.signatory_query = '';
                },
                addItem() {
                    this.items.push({ title: '', signatory_id: '', signatory_query: '', signatory_open: false });
                },
                removeItem(index) {
                    this.items.splice(index, 1);
                },
            };
        }
    </script>
@endsection
