@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb pageTitle="Logs" />

    <div class="mb-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            A read-only audit trail of authentication and account activity. No entry here can be edited or deleted
            from this page, by any role.
        </p>
    </div>

    <div x-data="{
        detail: null,
        loading: false,
        viewLog(id) {
            this.loading = true;
            fetch(`/logs/${id}`, { headers: { 'Accept': 'application/json' } })
                .then((res) => res.json())
                .then((data) => { this.detail = data; this.loading = false; })
                .catch(() => { this.loading = false; });
        },
    }">
        <form method="GET" action="{{ route('logs.index') }}" class="mb-4 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-500 dark:text-gray-400">Search</label>
                    <input type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                        placeholder="Employee no., name, username, or action..."
                        class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-500 dark:text-gray-400">User</label>
                    <select name="user_id" class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        <option value="">All Users</option>
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}" @selected(($filters['user_id'] ?? null) == $u->id)>{{ $u->name }} ({{ $u->username }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-500 dark:text-gray-400">Module</label>
                    <select name="module" class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        <option value="">All Modules</option>
                        @foreach ($modules as $module)
                            <option value="{{ $module }}" @selected(($filters['module'] ?? null) === $module)>{{ $module }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-500 dark:text-gray-400">Action</label>
                    <select name="action" class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        <option value="">All Actions</option>
                        @foreach ($actions as $action)
                            <option value="{{ $action }}" @selected(($filters['action'] ?? null) === $action)>{{ ucfirst(str_replace('_', ' ', $action)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-500 dark:text-gray-400">Status</label>
                    <select name="status" class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        <option value="">All</option>
                        <option value="success" @selected(($filters['status'] ?? null) === 'success')>Success</option>
                        <option value="failed" @selected(($filters['status'] ?? null) === 'failed')>Failed</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-500 dark:text-gray-400">From</label>
                    <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"
                        class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-500 dark:text-gray-400">To</label>
                    <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"
                        class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-500 dark:text-gray-400">Sort</label>
                    <select name="sort" class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        <option value="newest" @selected(($filters['sort'] ?? 'newest') === 'newest')>Newest First</option>
                        <option value="oldest" @selected(($filters['sort'] ?? null) === 'oldest')>Oldest First</option>
                    </select>
                </div>
            </div>
            <div class="mt-4 flex items-center gap-3">
                <button type="submit" class="rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630]">
                    Filter
                </button>
                <a href="{{ route('logs.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                    Clear Filters
                </a>
                <span class="ml-auto text-xs text-gray-400">{{ $logs->total() }} total entries</span>
            </div>
        </form>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto custom-scrollbar">
                <table class="w-full min-w-[960px]">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <th class="px-5 py-3 text-left sm:px-6"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Date &amp; Time</p></th>
                            <th class="px-5 py-3 text-left sm:px-6"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">User</p></th>
                            <th class="px-5 py-3 text-left sm:px-6"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Employee No.</p></th>
                            <th class="px-5 py-3 text-left sm:px-6"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Action</p></th>
                            <th class="px-5 py-3 text-left sm:px-6"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Module</p></th>
                            <th class="px-5 py-3 text-left sm:px-6"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Description</p></th>
                            <th class="px-5 py-3 text-left sm:px-6"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">IP Address</p></th>
                            <th class="px-5 py-3 text-left sm:px-6"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Browser</p></th>
                            <th class="px-5 py-3 text-left sm:px-6"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Status</p></th>
                            <th class="px-5 py-3 text-right sm:px-6"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">&nbsp;</p></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($logs as $log)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-5 py-4 sm:px-6"><p class="text-gray-500 text-theme-sm dark:text-gray-400">{{ $log->created_at->format('M d, Y g:i A') }}</p></td>
                                <td class="px-5 py-4 sm:px-6">
                                    <span class="block font-medium text-gray-800 text-theme-sm dark:text-white/90">{{ $log->employee_name ?? $log->username ?? '—' }}</span>
                                    <span class="block text-theme-xs text-gray-400">{{ $log->username }}</span>
                                </td>
                                <td class="px-5 py-4 sm:px-6"><p class="text-gray-500 text-theme-sm dark:text-gray-400">{{ $log->employee_number ?? '—' }}</p></td>
                                <td class="px-5 py-4 sm:px-6"><p class="text-gray-500 text-theme-sm dark:text-gray-400">{{ ucfirst(str_replace('_', ' ', $log->action)) }}</p></td>
                                <td class="px-5 py-4 sm:px-6"><p class="text-gray-500 text-theme-sm dark:text-gray-400">{{ $log->module }}</p></td>
                                <td class="px-5 py-4 sm:px-6"><p class="max-w-[280px] truncate text-gray-500 text-theme-sm dark:text-gray-400" title="{{ $log->description }}">{{ $log->description }}</p></td>
                                <td class="px-5 py-4 sm:px-6"><p class="text-gray-500 text-theme-sm dark:text-gray-400">{{ $log->ip_address ?? '—' }}</p></td>
                                <td class="px-5 py-4 sm:px-6"><p class="text-gray-500 text-theme-sm dark:text-gray-400">{{ $log->browser ? "{$log->browser} {$log->browser_version}" : '—' }}</p></td>
                                <td class="px-5 py-4 sm:px-6">
                                    <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $log->status === 'success' ? 'bg-[#145a3a]/10 text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]' : 'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400' }}">
                                        {{ ucfirst($log->status) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-right sm:px-6">
                                    <button type="button" @click="viewLog({{ $log->id }})"
                                        class="rounded-md border border-gray-200 px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                        View
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    No log entries match the current filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4">
            {{ $logs->links() }}
        </div>

        {{-- Detail modal — read-only, no edit/delete action anywhere in it. --}}
        <x-ui.modal x-data="{ open: false }" x-init="$watch('detail', (value) => { open = value !== null; }); $watch('open', (value) => { if (!value) detail = null; })" :isOpen="false" class="w-full sm:max-w-[560px]">
            <div class="no-scrollbar relative max-h-[85vh] w-full overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="detail" x-cloak>
                <template x-if="detail">
                    <div>
                        <h4 class="mb-5 text-xl font-semibold text-gray-800 dark:text-white/90">Log Entry Detail</h4>

                        <div class="space-y-1.5 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-xs dark:border-gray-800 dark:bg-white/[0.03]">
                            <div class="flex items-center justify-between"><span class="text-gray-400">Log ID</span><span class="font-medium text-gray-700 dark:text-gray-300" x-text="detail.log.id"></span></div>
                            <div class="flex items-center justify-between"><span class="text-gray-400">Date &amp; Time</span><span class="font-medium text-gray-700 dark:text-gray-300" x-text="detail.log.created_at"></span></div>
                            <div class="flex items-center justify-between"><span class="text-gray-400">User</span><span class="font-medium text-gray-700 dark:text-gray-300" x-text="detail.log.employee_name || detail.log.username || '—'"></span></div>
                            <div class="flex items-center justify-between"><span class="text-gray-400">Username</span><span class="font-medium text-gray-700 dark:text-gray-300" x-text="detail.log.username || '—'"></span></div>
                            <div class="flex items-center justify-between"><span class="text-gray-400">Employee No.</span><span class="font-medium text-gray-700 dark:text-gray-300" x-text="detail.log.employee_number || '—'"></span></div>
                            <div class="flex items-center justify-between"><span class="text-gray-400">Role(s)</span><span class="font-medium text-gray-700 dark:text-gray-300" x-text="detail.log.roles || '—'"></span></div>
                            <div class="flex items-center justify-between"><span class="text-gray-400">Action</span><span class="font-medium text-gray-700 dark:text-gray-300" x-text="detail.log.action"></span></div>
                            <div class="flex items-center justify-between"><span class="text-gray-400">Module</span><span class="font-medium text-gray-700 dark:text-gray-300" x-text="detail.log.module"></span></div>
                            <div class="flex items-center justify-between"><span class="text-gray-400">Status</span><span class="font-medium text-gray-700 dark:text-gray-300" x-text="detail.log.status"></span></div>
                            <template x-if="detail.log.failure_reason">
                                <div class="flex items-center justify-between"><span class="text-gray-400">Failure Reason</span><span class="font-medium text-error-600 dark:text-error-400" x-text="detail.log.failure_reason"></span></div>
                            </template>
                            <template x-if="detail.log.subject_type">
                                <div class="flex items-center justify-between"><span class="text-gray-400">Related Record</span><span class="font-medium text-gray-700 dark:text-gray-300" x-text="detail.log.subject_type + ' #' + detail.log.subject_id"></span></div>
                            </template>
                            <template x-if="detail.loginTime">
                                <div class="flex items-center justify-between"><span class="text-gray-400">Login Time</span><span class="font-medium text-gray-700 dark:text-gray-300" x-text="detail.loginTime"></span></div>
                            </template>
                            <template x-if="detail.sessionDurationMinutes !== null">
                                <div class="flex items-center justify-between"><span class="text-gray-400">Session Duration</span><span class="font-medium text-gray-700 dark:text-gray-300" x-text="detail.sessionDurationMinutes + ' min'"></span></div>
                            </template>
                            <div class="flex items-center justify-between"><span class="text-gray-400">IP Address</span><span class="font-medium text-gray-700 dark:text-gray-300" x-text="detail.log.ip_address || '—'"></span></div>
                            <div class="flex items-center justify-between"><span class="text-gray-400">Browser</span><span class="font-medium text-gray-700 dark:text-gray-300" x-text="(detail.log.browser || '—') + ' ' + (detail.log.browser_version || '')"></span></div>
                            <div class="flex items-center justify-between"><span class="text-gray-400">Platform</span><span class="font-medium text-gray-700 dark:text-gray-300" x-text="detail.log.platform || '—'"></span></div>
                            <div class="flex items-center justify-between"><span class="text-gray-400">Route</span><span class="font-medium text-gray-700 dark:text-gray-300" x-text="detail.log.route || '—'"></span></div>
                        </div>

                        <p class="mt-4 text-sm text-gray-700 dark:text-gray-300" x-text="detail.log.description"></p>

                        <template x-if="detail.log.old_values">
                            <div class="mt-4">
                                <p class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">Previous Value</p>
                                <pre class="overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-600 dark:bg-white/[0.03] dark:text-gray-400" x-text="JSON.stringify(detail.log.old_values, null, 2)"></pre>
                            </div>
                        </template>
                        <template x-if="detail.log.new_values">
                            <div class="mt-4">
                                <p class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-400">New Value</p>
                                <pre class="overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-600 dark:bg-white/[0.03] dark:text-gray-400" x-text="JSON.stringify(detail.log.new_values, null, 2)"></pre>
                            </div>
                        </template>

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
@endsection
