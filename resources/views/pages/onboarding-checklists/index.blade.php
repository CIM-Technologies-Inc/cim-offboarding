@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="Onboarding Checklist" />

    <div x-data="flashToast(@js(session('success')))" class="mb-6 flex items-center justify-between">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Build reusable onboarding checklists and assign a signatory to each item.
        </p>
        <a href="{{ route('onboarding-checklists.create') }}"
            class="shadow-theme-xs flex items-center justify-center gap-2 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630]">
            <svg class="fill-current" width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M9 3.75V14.25M3.75 9H14.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            New Checklist
        </a>
    </div>

    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="max-w-full overflow-x-auto custom-scrollbar">
            <table class="w-full min-w-[700px]">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <th class="px-5 py-3 text-left sm:px-6">
                            <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Title</p>
                        </th>
                        <th class="px-5 py-3 text-left sm:px-6">
                            <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Department</p>
                        </th>
                        <th class="px-5 py-3 text-left sm:px-6">
                            <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Items</p>
                        </th>
                        <th class="px-5 py-3 text-left sm:px-6">
                            <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Created By</p>
                        </th>
                        <th class="px-5 py-3 text-left sm:px-6">
                            <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Created</p>
                        </th>
                        <th class="px-5 py-3 text-left sm:px-6">
                            <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Status</p>
                        </th>
                        <th class="px-5 py-3 text-right sm:px-6">
                            <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Actions</p>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($templates as $template)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="px-5 py-4 sm:px-6">
                                <span class="block font-medium text-gray-800 text-theme-sm dark:text-white/90">
                                    {{ $template->title }}
                                </span>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-gray-500 text-theme-sm dark:text-gray-400">{{ $template->department ?? '—' }}</p>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-gray-500 text-theme-sm dark:text-gray-400">{{ $template->items_count }} item(s)</p>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-gray-500 text-theme-sm dark:text-gray-400">{{ $template->creator->name ?? '—' }}</p>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-gray-500 text-theme-sm dark:text-gray-400">{{ $template->created_at->format('M d, Y') }}</p>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <form method="POST" action="{{ route('onboarding-checklists.toggle-status', $template) }}" class="flex items-center gap-2">
                                    @csrf
                                    @method('PATCH')
                                    <label class="relative inline-flex cursor-pointer items-center">
                                        <input type="checkbox" class="peer sr-only" onchange="this.form.requestSubmit()" @checked($template->is_active) />
                                        <div
                                            class="peer h-6 w-11 rounded-full bg-gray-200 transition-colors duration-200 peer-checked:bg-[#145a3a] peer-focus:outline-hidden after:absolute after:top-0.5 after:left-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:transition-all after:duration-200 after:content-[''] peer-checked:after:translate-x-5 dark:bg-gray-700">
                                        </div>
                                    </label>
                                    <span class="text-xs font-medium {{ $template->is_active ? 'text-[#145a3a] dark:text-[#3aa876]' : 'text-gray-400' }}">
                                        {{ $template->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </form>
                            </td>
                            <td class="px-5 py-4 text-right sm:px-6">
                                <div class="flex items-center justify-end gap-2">
                                    <div class="group relative">
                                        <a href="{{ route('onboarding-checklists.show', $template) }}"
                                            class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-brand-500 dark:text-gray-400 dark:hover:bg-white/[0.03] dark:hover:text-brand-400">
                                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <path fill-rule="evenodd" clip-rule="evenodd" d="M10.0002 13.8619C7.23361 13.8619 4.86803 12.1372 3.92328 9.70241C4.86804 7.26761 7.23361 5.54297 10.0002 5.54297C12.7667 5.54297 15.1323 7.26762 16.0771 9.70243C15.1323 12.1372 12.7667 13.8619 10.0002 13.8619ZM10.0002 4.04297C6.48191 4.04297 3.49489 6.30917 2.4155 9.4593C2.3615 9.61687 2.3615 9.78794 2.41549 9.94552C3.49488 13.0957 6.48191 15.3619 10.0002 15.3619C13.5184 15.3619 16.5055 13.0957 17.5849 9.94555C17.6389 9.78797 17.6389 9.6169 17.5849 9.45932C16.5055 6.30919 13.5184 4.04297 10.0002 4.04297ZM9.99151 7.84413C8.96527 7.84413 8.13333 8.67606 8.13333 9.70231C8.13333 10.7286 8.96527 11.5605 9.99151 11.5605H10.0064C11.0326 11.5605 11.8646 10.7286 11.8646 9.70231C11.8646 8.67606 11.0326 7.84413 10.0064 7.84413H9.99151Z" fill="currentColor" />
                                            </svg>
                                        </a>
                                        <span
                                            class="pointer-events-none absolute -top-9 left-1/2 z-10 -translate-x-1/2 whitespace-nowrap rounded-lg bg-[#145a3a] px-2.5 py-1 text-xs font-medium text-white opacity-0 transition-opacity duration-150 group-hover:opacity-100">
                                            View
                                            <span class="absolute left-1/2 top-full -translate-x-1/2 border-4 border-transparent border-t-[#145a3a]"></span>
                                        </span>
                                    </div>
                                    <div class="group relative">
                                        <a href="{{ route('onboarding-checklists.edit', $template) }}"
                                            class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-brand-500 dark:text-gray-400 dark:hover:bg-white/[0.03] dark:hover:text-brand-400">
                                            <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <path fill-rule="evenodd" clip-rule="evenodd"
                                                    d="M15.0911 2.78206C14.2125 1.90338 12.7878 1.90338 11.9092 2.78206L4.57524 10.116C4.26682 10.4244 4.0547 10.8158 3.96468 11.2426L3.31231 14.3352C3.25997 14.5833 3.33653 14.841 3.51583 15.0203C3.69512 15.1996 3.95286 15.2761 4.20096 15.2238L7.29355 14.5714C7.72031 14.4814 8.11172 14.2693 8.42013 13.9609L15.7541 6.62695C16.6327 5.74827 16.6327 4.32365 15.7541 3.44497L15.0911 2.78206ZM12.9698 3.84272C13.2627 3.54982 13.7376 3.54982 14.0305 3.84272L14.6934 4.50563C14.9863 4.79852 14.9863 5.2734 14.6934 5.56629L14.044 6.21573L12.3204 4.49215L12.9698 3.84272ZM11.2597 5.55281L5.6359 11.1766C5.53309 11.2794 5.46238 11.4099 5.43238 11.5522L5.01758 13.5185L6.98394 13.1037C7.1262 13.0737 7.25666 13.003 7.35947 12.9002L12.9833 7.27639L11.2597 5.55281Z"
                                                    fill="currentColor" />
                                            </svg>
                                        </a>
                                        <span
                                            class="pointer-events-none absolute -top-9 left-1/2 z-10 -translate-x-1/2 whitespace-nowrap rounded-lg bg-[#145a3a] px-2.5 py-1 text-xs font-medium text-white opacity-0 transition-opacity duration-150 group-hover:opacity-100">
                                            Edit
                                            <span class="absolute left-1/2 top-full -translate-x-1/2 border-4 border-transparent border-t-[#145a3a]"></span>
                                        </span>
                                    </div>
                                    <form method="POST" action="{{ route('onboarding-checklists.destroy', $template) }}" x-data="{ confirmed: false }"
                                        @submit="if (!confirmed) {
                                            $event.preventDefault();
                                            Swal.fire({
                                                title: 'Delete this checklist template?',
                                                text: 'You are about to delete &quot;{{ $template->title }}&quot;. This cannot be undone.',
                                                icon: 'warning',
                                                showCancelButton: true,
                                                confirmButtonText: 'Delete',
                                                cancelButtonText: 'Cancel',
                                                confirmButtonColor: '#dc2626',
                                                cancelButtonColor: '#145a3a',
                                                reverseButtons: true
                                            }).then((result) => { if (result.isConfirmed) { confirmed = true; $el.requestSubmit(); } });
                                        }">
                                        @csrf
                                        @method('DELETE')
                                        <div class="group relative">
                                            <button type="submit" data-turbo-submits-with="Deleting..."
                                                class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 hover:bg-error-50 hover:text-error-500 dark:text-gray-400 dark:hover:bg-error-500/10 dark:hover:text-error-400">
                                                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <path fill-rule="evenodd" clip-rule="evenodd" d="M8.60834 4.16667H11.3917C11.4144 4.06414 11.4271 3.95762 11.4271 3.84812C11.4271 3.30589 11.0212 2.86118 10.5 2.79018V2.5C10.5 2.22386 10.2761 2 10 2C9.72386 2 9.5 2.22386 9.5 2.5V2.79018C8.97878 2.86118 8.57292 3.30589 8.57292 3.84812C8.57292 3.95762 8.58562 4.06414 8.60834 4.16667ZM6.5 5.5C6.22386 5.5 6 5.72386 6 6C6 6.27614 6.22386 6.5 6.5 6.5H6.9743L7.51823 15.6152C7.57216 16.5197 8.32082 17.2249 9.22699 17.2249H10.773C11.6792 17.2249 12.4278 16.5197 12.4818 15.6152L13.0257 6.5H13.5C13.7761 6.5 14 6.27614 14 6C14 5.72386 13.7761 5.5 13.5 5.5H6.5ZM11.5245 6.5H8.47552L9.01462 15.5556C9.03271 15.8571 9.28229 16.0922 9.58436 16.0922H10.4156C10.7177 16.0922 10.9673 15.8571 10.9854 15.5556L11.5245 6.5Z" fill="currentColor" />
                                                </svg>
                                            </button>
                                            <span
                                                class="pointer-events-none absolute -top-9 left-1/2 z-10 -translate-x-1/2 whitespace-nowrap rounded-lg bg-[#145a3a] px-2.5 py-1 text-xs font-medium text-white opacity-0 transition-opacity duration-150 group-hover:opacity-100">
                                                Delete
                                                <span class="absolute left-1/2 top-full -translate-x-1/2 border-4 border-transparent border-t-[#145a3a]"></span>
                                            </span>
                                        </div>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                No onboarding checklist templates yet. Click "New Checklist" to create one.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
