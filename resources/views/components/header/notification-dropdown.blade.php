{{-- Notification Dropdown Component --}}
@php
    $notifications = $notifications ?? collect();
    $unreadCount = $unreadCount ?? $notifications->whereNull('read_at')->count();
@endphp
<div class="relative" x-data="{
    dropdownOpen: false,
    // Tracks whether there's anything left to show/clear — seeded from
    // the server-rendered list at page load, then flipped to false the
    // instant Clear All succeeds so the empty state and the Clear All
    // button itself react immediately, with no page reload.
    hasNotifications: @js($notifications->isNotEmpty()),
    unreadCount: @js($unreadCount),
    clearingAll: false,
    toggleDropdown() {
        this.dropdownOpen = !this.dropdownOpen;
    },
    closeDropdown() {
        this.dropdownOpen = false;
    },
    // Permanently deletes every notification for the CURRENT user only
    // (see NotificationController::clearAll() — scoped server-side to
    // `$request->user()->notifications()`, so this can never touch
    // another user's notifications regardless of what's sent from here).
    // A fetch() POST, not a form submit, so the panel/badge update
    // instantly without any page navigation.
    clearAllNotifications() {
        if (this.clearingAll || !this.hasNotifications) {
            return;
        }
        this.clearingAll = true;
        window.fetchWithTimeout(@js(route('notifications.clear-all')), {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json',
            },
        }).then((res) => {
            if (!res.ok) {
                throw new Error('request failed');
            }
            this.clearingAll = false;
            this.hasNotifications = false;
            this.unreadCount = 0;
            window.Swal?.fire({
                icon: 'success',
                title: 'Notifications Cleared',
                text: 'All your notifications have been cleared.',
                confirmButtonColor: '#145a3a',
            });
        }).catch((e) => {
            this.clearingAll = false;
            window.Swal?.fire({
                icon: 'error',
                title: 'Failed to Clear Notifications',
                text: e?.name === 'AbortError'
                    ? 'This is taking longer than expected. Please check before trying again.'
                    : 'Notifications could not be cleared. Please try again.',
                confirmButtonColor: '#145a3a',
            });
        });
    },
}" @click.away="closeDropdown()">
    <!-- Notification Button -->
    <button
        class="relative flex items-center justify-center text-gray-500 transition-colors bg-white border border-gray-200 rounded-full hover:text-dark-900 h-11 w-11 hover:bg-gray-100 hover:text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white"
        @click="toggleDropdown()"
        type="button"
    >
        <!-- Notification Badge -->
        <span
            x-show="unreadCount > 0"
            x-cloak
            class="absolute -right-1 -top-1 z-1 flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-error-500 px-1 text-[10px] font-medium leading-none text-white"
            x-text="unreadCount > 9 ? '9+' : unreadCount"
        ></span>

        <!-- Bell Icon -->
        <svg
            class="fill-current"
            width="20"
            height="20"
            viewBox="0 0 20 20"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
        >
            <path
                fill-rule="evenodd"
                clip-rule="evenodd"
                d="M10.75 2.29248C10.75 1.87827 10.4143 1.54248 10 1.54248C9.58583 1.54248 9.25004 1.87827 9.25004 2.29248V2.83613C6.08266 3.20733 3.62504 5.9004 3.62504 9.16748V14.4591H3.33337C2.91916 14.4591 2.58337 14.7949 2.58337 15.2091C2.58337 15.6234 2.91916 15.9591 3.33337 15.9591H4.37504H15.625H16.6667C17.0809 15.9591 17.4167 15.6234 17.4167 15.2091C17.4167 14.7949 17.0809 14.4591 16.6667 14.4591H16.375V9.16748C16.375 5.9004 13.9174 3.20733 10.75 2.83613V2.29248ZM14.875 14.4591V9.16748C14.875 6.47509 12.6924 4.29248 10 4.29248C7.30765 4.29248 5.12504 6.47509 5.12504 9.16748V14.4591H14.875ZM8.00004 17.7085C8.00004 18.1228 8.33583 18.4585 8.75004 18.4585H11.25C11.6643 18.4585 12 18.1228 12 17.7085C12 17.2943 11.6643 16.9585 11.25 16.9585H8.75004C8.33583 16.9585 8.00004 17.2943 8.00004 17.7085Z"
                fill=""
            />
        </svg>
    </button>

    <!-- Dropdown Start -->
    <div
        x-show="dropdownOpen"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="transform opacity-0 scale-95"
        x-transition:enter-end="transform opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="transform opacity-100 scale-100"
        x-transition:leave-end="transform opacity-0 scale-95"
        class="absolute -right-[240px] mt-[17px] flex h-[480px] w-[350px] flex-col rounded-2xl border border-gray-200 bg-white p-3 shadow-theme-lg dark:border-gray-800 dark:bg-gray-dark sm:w-[361px] lg:right-0"
        style="display: none;"
    >
        <!-- Dropdown Header -->
        <div class="flex items-center justify-between pb-3 mb-3 border-b border-gray-100 dark:border-gray-800">
            <h5 class="text-lg font-semibold text-gray-800 dark:text-white/90">Notification</h5>

            <div class="flex items-center gap-3">
                <!-- Clear All: only shown while there's something to clear
                     (hasNotifications) — deletes every notification for
                     the current user, then reactively empties this panel
                     and zeroes the badge above, no page reload. -->
                <button type="button" @click="clearAllNotifications()" :disabled="clearingAll"
                    x-show="hasNotifications" x-cloak
                    class="text-xs font-medium text-gray-500 hover:text-error-600 disabled:cursor-not-allowed disabled:opacity-60 dark:text-gray-400 dark:hover:text-error-400">
                    <span x-text="clearingAll ? 'Clearing...' : 'Clear All'"></span>
                </button>

                <button @click="closeDropdown()" class="text-gray-500 dark:text-gray-400" type="button">
                    <svg
                        class="fill-current"
                        width="24"
                        height="24"
                        viewBox="0 0 24 24"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                    >
                        <path
                            fill-rule="evenodd"
                            clip-rule="evenodd"
                            d="M6.21967 7.28131C5.92678 6.98841 5.92678 6.51354 6.21967 6.22065C6.51256 5.92775 6.98744 5.92775 7.28033 6.22065L11.999 10.9393L16.7176 6.22078C17.0105 5.92789 17.4854 5.92788 17.7782 6.22078C18.0711 6.51367 18.0711 6.98855 17.7782 7.28144L13.0597 12L17.7782 16.7186C18.0711 17.0115 18.0711 17.4863 17.7782 17.7792C17.4854 18.0721 17.0105 18.0721 16.7176 17.7792L11.999 13.0607L7.28033 17.7794C6.98744 18.0722 6.51256 18.0722 6.21967 17.7794C5.92678 17.4865 5.92678 17.0116 6.21967 16.7187L10.9384 12L6.21967 7.28131Z"
                            fill=""
                        />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Notification List — hidden in favor of the empty-state message
             below once hasNotifications flips to false (either nothing
             was ever here, or Clear All just ran). -->
        <ul class="flex flex-col h-auto overflow-y-auto custom-scrollbar" x-show="hasNotifications">
            @foreach ($notifications as $notification)
                @php
                    $isApproved = $notification->type === 'offboarding_approved';
                    $isOverdue = $notification->type === 'checklist_overdue';
                    $isClearanceSigningDue = $notification->type === 'clearance_signing_due';
                    $isUnread = $notification->read_at === null;
                @endphp
                <li>
                    <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                        @csrf
                        <button type="submit" data-turbo-submits-with="Marking..."
                            class="flex w-full gap-3 rounded-lg border-b border-gray-100 p-3 px-4.5 py-3 text-left hover:bg-gray-100 dark:border-gray-800 dark:hover:bg-white/5">
                            <span class="relative mt-1 block h-2.5 w-2.5 shrink-0 rounded-full {{ $isUnread ? ($isApproved ? 'bg-[#145a3a]' : ($isOverdue ? 'bg-orange-500' : 'bg-error-500')) : 'bg-gray-300 dark:bg-gray-700' }}">
                            </span>

                            <span class="block min-w-0">
                                @if ($isOverdue)
                                    <span class="mb-1 block text-theme-sm font-medium text-orange-600 dark:text-orange-400">
                                        Checklist Overdue
                                    </span>
                                @elseif ($isClearanceSigningDue)
                                    <span class="mb-1 block text-theme-sm font-medium text-error-600 dark:text-error-400">
                                        Clearance Signing Due Date Reached
                                    </span>
                                @endif

                                <span class="mb-1 block text-theme-sm text-gray-700 dark:text-gray-300">
                                    {{ $notification->data['message'] ?? 'Notification' }}
                                </span>

                                @if (! empty($notification->data['comment']))
                                    <span class="mb-1 block text-xs text-gray-500 dark:text-gray-400">
                                        Reason: {{ $notification->data['comment'] }}
                                    </span>
                                @endif

                                @if ($isOverdue)
                                    <span class="mb-1 block text-xs text-gray-500 dark:text-gray-400">
                                        Employee No.: {{ $notification->data['offboardee_employee_code'] ?? '—' }}
                                        &middot; Due: {{ $notification->data['due_at'] ?? '—' }}
                                        &middot; Status: {{ $notification->data['status'] ?? 'Overdue' }}
                                    </span>
                                @elseif ($isClearanceSigningDue)
                                    <span class="mb-1 block text-xs text-gray-500 dark:text-gray-400">
                                        Employee No.: {{ $notification->data['offboardee_employee_code'] ?? '—' }}
                                        &middot; Checklist: {{ $notification->data['checklist_title'] ?? '—' }}
                                        &middot; Due: {{ $notification->data['due_at'] ?? '—' }}
                                    </span>
                                @endif

                                <span class="flex items-center gap-2 text-gray-400 text-theme-xs">
                                    {{ $notification->created_at->diffForHumans() }}
                                </span>
                            </span>
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>

        <!-- Empty state — shown whenever there's nothing left (never had
             any, or Clear All just ran), replacing the old server-only
             empty branch so both cases share one reactive message. -->
        <div x-show="!hasNotifications" x-cloak class="flex flex-1 items-center justify-center py-10 text-sm text-gray-400">
            No notifications yet.
        </div>
    </div>
    <!-- Dropdown End -->
</div>
