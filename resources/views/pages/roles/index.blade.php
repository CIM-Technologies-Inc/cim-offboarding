@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="Roles & Permissions" />

    <div x-data="flashToast(@js(session('success')), @js(session('error')))" class="mb-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Manage roles and the permissions assigned to each. The three system roles
            (<span class="font-medium">admin</span>, <span class="font-medium">approver</span>,
            <span class="font-medium">employee</span>) can't be renamed or deleted — the app's access control is
            hardcoded to those names — but their permissions can still be adjusted freely.
        </p>
    </div>

    <div class="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] lg:p-6">
        <h5 class="mb-4 text-lg font-medium text-gray-800 dark:text-white/90">Create Role</h5>
        <form method="POST" action="{{ route('roles.store') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            @csrf
            <div class="flex-1">
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Role Name</label>
                <input type="text" name="name" required placeholder="e.g. HR Coordinator"
                    class="dark:bg-dark-900 h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
            </div>
            <button type="submit"
                class="shadow-theme-xs flex h-11 items-center justify-center gap-2 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630] sm:w-auto">
                Create Role
            </button>
        </form>
        <p class="mt-2 text-xs text-gray-400">
            A newly created role has no permissions until you assign them below, and won't grant access to any
            existing page — only the 3 system roles are wired into the app's routes today.
        </p>
    </div>

    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="max-w-full overflow-x-auto custom-scrollbar">
            <table class="w-full min-w-[640px]">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <th class="px-5 py-3 text-left sm:px-6">
                            <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Role</p>
                        </th>
                        <th class="px-5 py-3 text-left sm:px-6">
                            <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Permissions</p>
                        </th>
                        <th class="px-5 py-3 text-left sm:px-6">
                            <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Users</p>
                        </th>
                        <th class="px-5 py-3 text-right sm:px-6">
                            <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Actions</p>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($roles as $role)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="px-5 py-4 sm:px-6">
                                @if ($role['isSystemRole'])
                                    <span class="block font-medium text-gray-800 text-theme-sm dark:text-white/90">
                                        {{ $role['name'] }}
                                        <span class="ml-1 rounded-full bg-gray-100 px-2 py-0.5 text-theme-xs font-normal text-gray-500 dark:bg-gray-800 dark:text-gray-400">System</span>
                                    </span>
                                @else
                                    <form method="POST" action="{{ route('roles.update', $role['id']) }}" class="flex items-center gap-2">
                                        @csrf
                                        @method('PUT')
                                        <input type="text" name="name" value="{{ $role['name'] }}" required
                                            class="dark:bg-dark-900 h-9 w-40 appearance-none rounded-lg border border-gray-300 bg-transparent bg-none px-3 py-1.5 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:focus:border-brand-800" />
                                        <button type="submit" class="text-theme-xs font-medium text-[#145a3a] hover:underline dark:text-[#3aa876]">Save</button>
                                    </form>
                                @endif
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-gray-500 text-theme-sm dark:text-gray-400">{{ $role['permissionCount'] }} permission(s)</p>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-gray-500 text-theme-sm dark:text-gray-400">{{ $role['userCount'] }} user(s)</p>
                            </td>
                            <td class="px-5 py-4 text-right sm:px-6">
                                <div class="flex items-center justify-end gap-2">
                                    <button type="button" @click="$dispatch('open-permissions-modal', @js($role))"
                                        class="rounded-lg border border-gray-300 px-3 py-1.5 text-theme-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                        Manage Permissions
                                    </button>

                                    @unless ($role['isSystemRole'])
                                        <form method="POST" action="{{ route('roles.destroy', $role['id']) }}" x-data="{ confirmed: false }"
                                            @submit="if (!confirmed) {
                                                $event.preventDefault();
                                                Swal.fire({
                                                    title: 'Delete this role?',
                                                    html: 'You are about to delete <b>' + @js($role['name']) + '</b>. This cannot be undone.',
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
                                            <button type="submit"
                                                class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 hover:bg-error-50 hover:text-error-500 dark:text-gray-400 dark:hover:bg-error-500/10 dark:hover:text-error-400">
                                                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <path fill-rule="evenodd" clip-rule="evenodd" d="M8.60834 4.16667H11.3917C11.4144 4.06414 11.4271 3.95762 11.4271 3.84812C11.4271 3.30589 11.0212 2.86118 10.5 2.79018V2.5C10.5 2.22386 10.2761 2 10 2C9.72386 2 9.5 2.22386 9.5 2.5V2.79018C8.97878 2.86118 8.57292 3.30589 8.57292 3.84812C8.57292 3.95762 8.58562 4.06414 8.60834 4.16667ZM6.5 5.5C6.22386 5.5 6 5.72386 6 6C6 6.27614 6.22386 6.5 6.5 6.5H6.9743L7.51823 15.6152C7.57216 16.5197 8.32082 17.2249 9.22699 17.2249H10.773C11.6792 17.2249 12.4278 16.5197 12.4818 15.6152L13.0257 6.5H13.5C13.7761 6.5 14 6.27614 14 6C14 5.72386 13.7761 5.5 13.5 5.5H6.5ZM11.5245 6.5H8.47552L9.01462 15.5556C9.03271 15.8571 9.28229 16.0922 9.58436 16.0922H10.4156C10.7177 16.0922 10.9673 15.8571 10.9854 15.5556L11.5245 6.5Z" fill="currentColor" />
                                                </svg>
                                            </button>
                                        </form>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                No roles found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-roles.permissions-modal :permission-groups="$permissionGroups" />
@endsection
