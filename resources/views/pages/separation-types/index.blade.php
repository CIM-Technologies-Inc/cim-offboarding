@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="Separation Types" />

    <div x-data="{
            editingType: null,
            viewingType: null,
            openEditModal(type) {
                this.editingType = type;
                this.$dispatch('open-separation-type-modal');
            },
            openViewModal(type) {
                this.viewingType = type;
                this.$dispatch('open-view-separation-type-modal');
            },
        }">
        <div x-data="flashToast(@js(session('success')), @js($errors->any() ? $errors->first() : null))" class="mb-6">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Manage the Separation Type options offered on the New Offboarding Request form. Each type's
                Description/Definition and Default Notice Period are copied onto a request the moment it's created —
                editing or deleting a type here never changes an offboarding request that already exists.
            </p>
        </div>

        <div class="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:p-6">
            <h5 class="mb-4 text-lg font-medium text-gray-800 dark:text-white/90">Add Separation Type</h5>
            <form method="POST" action="{{ route('separation-types.store') }}" class="grid grid-cols-1 gap-4 lg:grid-cols-6">
                @csrf
                <div class="lg:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        Separation Type Title <span class="text-error-500">*</span>
                    </label>
                    <input type="text" name="title" required value="{{ old('title') }}" placeholder="e.g. Resignation"
                        class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
                    @error('title')
                        <p class="mt-1.5 text-xs text-error-500">{{ $message }}</p>
                    @enderror
                </div>
                <div class="lg:col-span-3">
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        Description / Definition <span class="text-error-500">*</span>
                    </label>
                    <textarea name="description" required rows="1" placeholder="Definition per policy..."
                        class="dark:bg-dark-900 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800">{{ old('description') }}</textarea>
                    @error('description')
                        <p class="mt-1.5 text-xs text-error-500">{{ $message }}</p>
                    @enderror
                </div>
                <div class="lg:col-span-1">
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        Default Notice Period (Days) <span class="text-error-500">*</span>
                    </label>
                    <input type="number" name="default_notice_period_days" min="0" step="1" required
                        value="{{ old('default_notice_period_days', 30) }}"
                        class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
                    @error('default_notice_period_days')
                        <p class="mt-1.5 text-xs text-error-500">{{ $message }}</p>
                    @enderror
                </div>
                <div class="flex items-end lg:col-span-6">
                    <button type="submit" data-turbo-submits-with="Adding..."
                        class="shadow-theme-xs flex h-11 items-center justify-center gap-2 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630]">
                        Add Separation Type
                    </button>
                </div>
            </form>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="custom-scrollbar max-w-full overflow-x-auto">
                <table class="w-full min-w-[720px]">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <th class="px-5 py-3 text-left sm:px-6">
                                <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Separation Type</p>
                            </th>
                            <th class="px-5 py-3 text-left sm:px-6">
                                <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Description / Definition</p>
                            </th>
                            <th class="px-5 py-3 text-left sm:px-6">
                                <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Default Notice Period</p>
                            </th>
                            <th class="px-5 py-3 text-right sm:px-6">
                                <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Actions</p>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($separationTypes as $separationType)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-5 py-4 align-top sm:px-6">
                                    <span class="block font-medium text-gray-800 text-theme-sm dark:text-white/90">
                                        {{ $separationType->title }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 align-top sm:px-6">
                                    <p class="max-w-md truncate text-gray-500 text-theme-sm dark:text-gray-400" title="{{ $separationType->description }}">
                                        {{ $separationType->description }}
                                    </p>
                                </td>
                                <td class="px-5 py-4 align-top sm:px-6">
                                    <p class="text-gray-500 text-theme-sm dark:text-gray-400">{{ $separationType->default_notice_period_days }} days</p>
                                </td>
                                <td class="px-5 py-4 text-right align-top sm:px-6">
                                    <div class="flex items-center justify-end gap-2">
                                        <button type="button"
                                            @click="openViewModal(@js([
                                                'id' => $separationType->id,
                                                'title' => $separationType->title,
                                                'description' => $separationType->description,
                                                'default_notice_period_days' => $separationType->default_notice_period_days,
                                            ]))"
                                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                            View
                                        </button>
                                        <button type="button"
                                            @click="openEditModal(@js([
                                                'id' => $separationType->id,
                                                'title' => $separationType->title,
                                                'description' => $separationType->description,
                                                'default_notice_period_days' => $separationType->default_notice_period_days,
                                            ]))"
                                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                            Edit
                                        </button>
                                        <form method="POST" action="{{ route('separation-types.destroy', $separationType) }}" x-data="{ confirmed: false }"
                                            @submit="if (!confirmed) {
                                                $event.preventDefault();
                                                Swal.fire({
                                                    title: 'Delete this separation type?',
                                                    html: 'You are about to delete <b>{{ e($separationType->title) }}</b>. This will not affect any existing offboarding request that already used it.',
                                                    icon: 'warning',
                                                    showCancelButton: true,
                                                    confirmButtonText: 'Delete',
                                                    cancelButtonText: 'Cancel',
                                                    confirmButtonColor: '#dc2626',
                                                    cancelButtonColor: '#145a3a',
                                                    reverseButtons: true
                                                }).then((result) => { if (result.isConfirmed) { confirmed = true; $el.requestSubmit(); } });
                                            }" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" data-turbo-submits-with="Deleting..."
                                                class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 hover:bg-error-50 hover:text-error-500 dark:text-gray-400 dark:hover:bg-error-500/10 dark:hover:text-error-400">
                                                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <path fill-rule="evenodd" clip-rule="evenodd" d="M8.60834 4.16667H11.3917C11.4144 4.06414 11.4271 3.95762 11.4271 3.84812C11.4271 3.30589 11.0212 2.86118 10.5 2.79018V2.5C10.5 2.22386 10.2761 2 10 2C9.72386 2 9.5 2.22386 9.5 2.5V2.79018C8.97878 2.86118 8.57292 3.30589 8.57292 3.84812C8.57292 3.95762 8.58562 4.06414 8.60834 4.16667ZM6.5 5.5C6.22386 5.5 6 5.72386 6 6C6 6.27614 6.22386 6.5 6.5 6.5H6.9743L7.51823 15.6152C7.57216 16.5197 8.32082 17.2249 9.22699 17.2249H10.773C11.6792 17.2249 12.4278 16.5197 12.4818 15.6152L13.0257 6.5H13.5C13.7761 6.5 14 6.27614 14 6C14 5.72386 13.7761 5.5 13.5 5.5H6.5ZM11.5245 6.5H8.47552L9.01462 15.5556C9.03271 15.8571 9.28229 16.0922 9.58436 16.0922H10.4156C10.7177 16.0922 10.9673 15.8571 10.9854 15.5556L11.5245 6.5Z" fill="currentColor" />
                                                </svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    No separation types configured yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Edit modal — pre-filled purely from the row data already on the
             page (`editingType`, set by `openEditModal()` above), no server
             round-trip, same convention as `employee-groups/index.blade.php`'s
             Create/Edit Group modal. --}}
        <x-ui.modal x-data="{ open: false }" @open-separation-type-modal.window="open = true" :isOpen="false" class="w-full sm:w-[50vw] sm:max-w-[50vw]">
            <div class="relative w-full rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-cloak>
                <h4 class="mb-5 text-xl font-semibold text-gray-800 dark:text-white/90">Edit Separation Type</h4>

                <template x-if="editingType">
                    <form method="POST" :action="'/separation-types/' + editingType.id">
                        @csrf
                        @method('PUT')

                        <div class="mb-4">
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                Separation Type Title <span class="text-error-500">*</span>
                            </label>
                            <input type="text" name="title" required x-model="editingType.title"
                                class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800" />
                        </div>

                        <div class="mb-4">
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                Description / Definition <span class="text-error-500">*</span>
                            </label>
                            <textarea name="description" required rows="3" x-model="editingType.description"
                                class="dark:bg-dark-900 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800"></textarea>
                        </div>

                        <div class="mb-6">
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                Default Notice Period (Days) <span class="text-error-500">*</span>
                            </label>
                            <input type="number" name="default_notice_period_days" min="0" step="1" required
                                x-model="editingType.default_notice_period_days"
                                class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800" />
                        </div>

                        <div class="flex items-center justify-end gap-3">
                            <button type="button" @click="open = false"
                                class="flex w-full justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03] sm:w-auto">
                                Cancel
                            </button>
                            <button type="submit" data-turbo-submits-with="Saving..."
                                class="flex w-full justify-center rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630] sm:w-auto">
                                Save Changes
                            </button>
                        </div>
                    </form>
                </template>
            </div>
        </x-ui.modal>

        {{-- View modal — read-only, same "already-loaded row data" convention
             as the Edit modal above. --}}
        <x-ui.modal x-data="{ open: false }" @open-view-separation-type-modal.window="open = true" :isOpen="false" class="w-full sm:w-[40vw] sm:max-w-[40vw]">
            <div class="relative w-full rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-cloak>
                <template x-if="viewingType">
                    <div>
                        <h4 class="mb-5 text-xl font-semibold text-gray-800 dark:text-white/90" x-text="viewingType.title"></h4>

                        <div class="mb-4">
                            <p class="mb-1 text-xs font-medium text-gray-400">Description / Definition</p>
                            <p class="text-sm text-gray-700 dark:text-gray-300" x-text="viewingType.description"></p>
                        </div>

                        <div class="mb-6">
                            <p class="mb-1 text-xs font-medium text-gray-400">Default Notice Period</p>
                            <p class="text-sm text-gray-700 dark:text-gray-300" x-text="viewingType.default_notice_period_days + ' days'"></p>
                        </div>

                        <div class="flex justify-end">
                            <button type="button" @click="open = false"
                                class="flex w-full justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03] sm:w-auto">
                                Close
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </x-ui.modal>
    </div>
@endsection
