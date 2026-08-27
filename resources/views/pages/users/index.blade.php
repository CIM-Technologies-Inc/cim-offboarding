@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="Users" />

    <div x-data="flashToast(@js(session('success')), @js(session('error')))" class="mb-6">
        <!-- <p class="text-sm text-gray-500 dark:text-gray-400">
            Every account here was auto-created when its Employee was made a checklist approver, an Immediate Head,
            or an offboardee. Change a user's role(s) below — a user can hold any combination of the 3 roles at
            once. The account itself is managed automatically and can't be created or deleted from this page.
        </p> -->
    </div>

    {{-- A brand-new account's one-time credentials are shown here as a real
         (non-auto-dismissing) modal, distinct from the toast above, so the
         admin has time to read/copy the initial password before it's gone —
         this is the ONLY place it's ever shown; it's never retrievable again
         afterward, since only the hash is stored. Renders nothing when the
         role update didn't create a new account (an existing account keeps
         the plain toast above, which already says so).

         A plain <script> tag rather than an inline x-data attribute — the
         HTML this builds needs double-quoted style="..." attributes, which
         would otherwise prematurely close the (also double-quoted) x-data
         attribute value the moment the browser reached the first one. --}}
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const newAccount = @json(session('newAccount'));

            if (!newAccount) {
                return;
            }

            const escapeHtml = (value) => {
                const div = document.createElement('div');
                div.textContent = value ?? '';
                return div.innerHTML;
            };

            window.Swal?.fire({
                icon: 'success',
                title: 'User Account Created',
                html: '<div style="text-align:left;font-size:14px;line-height:1.7;">'
                    + '<p>A new user account was automatically created for this employee.</p>'
                    + '<table style="width:100%;margin-top:12px;border-collapse:collapse;">'
                    + '<tr><td style="color:#6b7280;padding:3px 10px 3px 0;">Employee Name</td><td style="font-weight:600;">' + escapeHtml(newAccount.name) + '</td></tr>'
                    + '<tr><td style="color:#6b7280;padding:3px 10px 3px 0;">Employee No.</td><td style="font-weight:600;">' + escapeHtml(newAccount.employeeCode) + '</td></tr>'
                    + '<tr><td style="color:#6b7280;padding:3px 10px 3px 0;">Username</td><td style="font-weight:600;">' + escapeHtml(newAccount.username) + '</td></tr>'
                    + '<tr><td style="color:#6b7280;padding:3px 10px 3px 0;">Initial Password</td><td style="font-weight:600;">' + escapeHtml(newAccount.password) + '</td></tr>'
                    + '</table>'
                    + '<p style="margin-top:12px;color:#6b7280;font-size:12px;">Please share these credentials with the employee — they will be required to set a new password on first login.</p>'
                    + '</div>',
                confirmButtonText: 'Got it',
                confirmButtonColor: '#145a3a',
            });
        });
    </script>

    @php
        $roleBadgeClass = [
            'admin' => 'bg-[#145a3a]/10 text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]',
            'approver' => 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400',
            'employee' => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
        ];
    @endphp

    <div x-data="{ search: '', rows: @js($users->map(fn ($u) => strtolower(collect([$u['name'], $u['employeeCode'], $u['username'], $u['email']])->filter()->implode(' ')))->values()) }">
        <div class="mb-4">
            <div class="relative max-w-md">
                <svg class="absolute top-1/2 left-4 -translate-y-1/2 text-gray-400" width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M9.16667 3.33333C5.94501 3.33333 3.33333 5.94501 3.33333 9.16667C3.33333 12.3883 5.94501 15 9.16667 15C12.3883 15 15 12.3883 15 9.16667C15 5.94501 12.3883 3.33333 9.16667 3.33333ZM1.66667 9.16667C1.66667 5.02454 5.02454 1.66667 9.16667 1.66667C13.3088 1.66667 16.6667 5.02454 16.6667 9.16667C16.6667 13.3088 13.3088 16.6667 9.16667 16.6667C5.02454 16.6667 1.66667 13.3088 1.66667 9.16667Z" fill="currentColor" />
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M13.3384 13.3384C13.6638 13.013 14.1913 13.013 14.5167 13.3384L18.0774 16.899C18.4028 17.2244 18.4028 17.7519 18.0774 18.0774C17.7519 18.4028 17.2244 18.4028 16.899 18.0774L13.3384 14.5167C13.013 14.1913 13.013 13.6638 13.3384 13.3384Z" fill="currentColor" />
                </svg>
                <input type="text" x-model="search" placeholder="Search by name, employee code, username, or email..."
                    class="h-11 w-full rounded-lg border border-gray-300 bg-transparent py-2.5 pr-4 pl-11 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800" />
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="max-w-full overflow-x-auto custom-scrollbar">
            <table class="w-full min-w-[720px]">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <th class="px-5 py-3 text-left sm:px-6">
                            <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Name</p>
                        </th>
                        <th class="px-5 py-3 text-left sm:px-6">
                            <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Employee Code</p>
                        </th>
                        <th class="px-5 py-3 text-left sm:px-6">
                            <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Username</p>
                        </th>
                        <th class="px-5 py-3 text-left sm:px-6">
                            <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Roles</p>
                        </th>
                        <th class="px-5 py-3 text-right sm:px-6">
                            <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Change Roles</p>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $index => $user)
                        <tr class="border-b border-gray-100 dark:border-gray-800"
                            x-show="!search || rows[{{ $index }}].includes(search.toLowerCase())">
                            <td class="px-5 py-4 sm:px-6">
                                <span class="block font-medium text-gray-800 text-theme-sm dark:text-white/90">{{ $user['name'] }}</span>
                                <span class="block text-theme-xs text-gray-400">{{ $user['email'] ?? '—' }}</span>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-gray-500 text-theme-sm dark:text-gray-400">{{ $user['employeeCode'] }}</p>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                @if ($user['hasAccount'])
                                    <p class="text-gray-500 text-theme-sm dark:text-gray-400">{{ $user['username'] }}</p>
                                @else
                                    <p class="text-theme-xs text-gray-400 italic">Not yet created — will use {{ $user['employeeCode'] }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <div class="flex flex-wrap gap-1.5">
                                    @forelse ($user['roles'] as $roleName)
                                        <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $roleBadgeClass[$roleName] ?? 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' }}">
                                            {{ ucfirst($roleName) }}
                                        </span>
                                    @empty
                                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                                            None
                                        </span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <form method="POST" action="{{ route('users.role.update', $user['id']) }}"
                                    class="flex flex-col items-end gap-2" x-data="{ roles: @js($user['roles']), confirmed: false }"
                                    @submit="if (!confirmed) {
                                        $event.preventDefault();
                                        if (roles.length === 0) {
                                            Swal.fire({ icon: 'warning', title: 'Select at least one role', confirmButtonColor: '#145a3a' });
                                            return;
                                        }
                                        const roleLabel = roles.map((r) => r.charAt(0).toUpperCase() + r.slice(1)).join(' + ');
                                        Swal.fire({
                                            title: 'Change role(s)?',
                                            html: 'Set <b>' + @js($user['name']) + '</b>&rsquo;s role(s) to <b>' + roleLabel + '</b>?',
                                            icon: 'question',
                                            showCancelButton: true,
                                            confirmButtonText: 'Change Role(s)',
                                            cancelButtonText: 'Cancel',
                                            confirmButtonColor: '#145a3a',
                                            cancelButtonColor: '#6b7280',
                                            reverseButtons: true
                                        }).then((result) => { if (result.isConfirmed) { confirmed = true; $el.requestSubmit(); } });
                                    }">
                                    @csrf
                                    @method('PUT')
                                    <div class="flex flex-wrap items-center justify-end gap-3">
                                        @foreach ($roleOptions as $option)
                                            <label class="flex cursor-pointer items-center gap-1.5 text-xs text-gray-700 dark:text-gray-300">
                                                <input type="checkbox" name="roles[]" value="{{ $option }}" x-model="roles"
                                                    class="h-3.5 w-3.5 accent-brand-500" />
                                                {{ ucfirst($option) }}
                                            </label>
                                        @endforeach
                                    </div>
                                    <button type="submit"
                                        class="rounded-lg bg-[#145a3a] px-3 py-1.5 text-theme-xs font-medium text-white hover:bg-[#0f4630]">
                                        Save
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                No users found.
                            </td>
                        </tr>
                    @endforelse
                    <tr x-show="search && rows.filter((r) => r.includes(search.toLowerCase())).length === 0">
                        <td colspan="5" class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            No employees match "<span x-text="search"></span>".
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        </div>
    </div>
@endsection
