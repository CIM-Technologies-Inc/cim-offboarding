@extends('layouts.app')

@php
    $input = 'h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $statusBadge = [
        'sent' => 'bg-[#145a3a]/10 text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]',
        'failed' => 'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400',
        'sending' => 'bg-orange-50 text-orange-600 dark:bg-orange-500/15 dark:text-orange-400',
    ];
    $typeBadge = [
        'system' => 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-300',
        'scheduled' => 'email-type-scheduled',
    ];
@endphp

@section('content')
    <style>
        .email-type-scheduled { background-color: #eff8ff; color: #175cd3; }
        .dark .email-type-scheduled { background-color: rgba(46, 144, 250, .15); color: #53b1fd; }
    </style>
    <x-common.page-breadcrumb pageTitle="Logs" />

    @include('pages.logs.partials.tabs')

    <div class="mb-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Every email notification the system attempted to send — system-triggered and scheduled — with its
            recipients, sending status and, if it failed, the reason. Email contents are never stored. Read-only.
        </p>
    </div>

    <div x-data="{
        detail: null,
        loading: false,
        view(id) {
            this.loading = true;
            fetch(`{{ url('logs/email-notifications') }}/${id}`, { headers: { Accept: 'application/json' } })
                .then((res) => res.json())
                .then((data) => { this.detail = data; })
                .catch(() => {})
                .finally(() => { this.loading = false; });
        },
    }">
        <form method="GET" action="{{ route('logs.email-notifications') }}" class="mb-4 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-500 dark:text-gray-400">Search</label>
                    <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Subject, template, offboardee, trigger..." class="{{ $input }}" />
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-500 dark:text-gray-400">Recipient</label>
                    <input type="text" name="recipient" value="{{ $filters['recipient'] ?? '' }}" placeholder="name@company.com" class="{{ $input }}" />
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-500 dark:text-gray-400">Notification Type</label>
                    <select name="type" class="{{ $input }}">
                        <option value="">All Types</option>
                        @foreach (\App\Models\EmailLog::TYPE_LABELS as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['type'] ?? null) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-500 dark:text-gray-400">Sending Status</label>
                    <select name="status" class="{{ $input }}">
                        <option value="">All Statuses</option>
                        @foreach (\App\Models\EmailLog::STATUS_LABELS as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? null) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-500 dark:text-gray-400">From</label>
                    <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="{{ $input }}" />
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-500 dark:text-gray-400">To</label>
                    <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="{{ $input }}" />
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-medium text-gray-500 dark:text-gray-400">Sort</label>
                    <select name="sort" class="{{ $input }}">
                        <option value="newest" @selected(($filters['sort'] ?? 'newest') === 'newest')>Newest First</option>
                        <option value="oldest" @selected(($filters['sort'] ?? null) === 'oldest')>Oldest First</option>
                    </select>
                </div>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <button type="submit" class="rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630]">Filter</button>
                <a href="{{ route('logs.email-notifications') }}" class="text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">Clear Filters</a>
                <div class="ml-auto flex flex-wrap items-center gap-2 text-xs">
                    <span class="rounded-full px-2.5 py-1 font-medium {{ $statusBadge['sent'] }}">Sent: {{ $statusCounts['sent'] ?? 0 }}</span>
                    <span class="rounded-full px-2.5 py-1 font-medium {{ $statusBadge['failed'] }}">Failed: {{ $statusCounts['failed'] ?? 0 }}</span>
                    @if (($statusCounts['sending'] ?? 0) > 0)
                        <span class="rounded-full px-2.5 py-1 font-medium {{ $statusBadge['sending'] }}">Unconfirmed: {{ $statusCounts['sending'] }}</span>
                    @endif
                    <span class="text-gray-400">{{ $emails->total() }} total</span>
                </div>
            </div>
        </form>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="max-w-full overflow-x-auto custom-scrollbar">
                <table class="w-full min-w-[960px]">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <th class="px-5 py-3 text-left sm:px-6 whitespace-nowrap"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Date &amp; Time</p></th>
                            <th class="px-5 py-3 text-left sm:px-6"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Subject / Template</p></th>
                            <th class="px-5 py-3 text-left sm:px-6"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Recipient</p></th>
                            <th class="px-5 py-3 text-left sm:px-6"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Offboardee</p></th>
                            <th class="px-5 py-3 text-left sm:px-6"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Type</p></th>
                            <th class="px-5 py-3 text-left sm:px-6"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Status</p></th>
                            <th class="px-5 py-3 text-right sm:px-6"><p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">&nbsp;</p></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($emails as $email)
                            @php
                                $to = $email->to_recipients ?? [];
                                $extra = count($to) - 1 + count($email->cc_recipients ?? []) + count($email->bcc_recipients ?? []);
                            @endphp
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-5 py-4 whitespace-nowrap sm:px-6"><p class="text-gray-500 text-theme-sm dark:text-gray-400">{{ $email->created_at->format('M d, Y g:i A') }}</p></td>
                                <td class="px-5 py-4 sm:px-6">
                                    <p class="max-w-[280px] truncate text-theme-sm font-medium text-gray-800 dark:text-white/90" title="{{ $email->subject }}">{{ $email->subject ?: '(no subject)' }}</p>
                                    <p class="max-w-[280px] truncate text-theme-xs text-gray-500 dark:text-gray-400">{{ $email->template_name ?? ($email->mailable ? \Illuminate\Support\Str::headline(str_replace('Mail', '', $email->mailable)) : '—') }}</p>
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <p class="max-w-[280px] truncate text-gray-600 text-theme-sm dark:text-gray-300" title="{{ $email->recipients_search }}">
                                        {{ $to[0] ?? '—' }}@if ($extra > 0)<span class="text-gray-400"> +{{ $extra }}</span>@endif
                                    </p>
                                </td>
                                <td class="px-5 py-4 sm:px-6"><p class="text-gray-500 text-theme-sm dark:text-gray-400">{{ $email->offboardee_name ?? '—' }}</p></td>
                                <td class="px-5 py-4 sm:px-6">
                                    <span class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium {{ $typeBadge[$email->notification_type] ?? $typeBadge['system'] }}">{{ $email->typeLabel() }}</span>
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <span class="whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium {{ $statusBadge[$email->status] ?? $statusBadge['sending'] }}">{{ $email->statusLabel() }}</span>
                                    @if ($email->attempt > 1)
                                        <span class="ml-1 text-xs text-gray-400" title="Retry of an earlier attempt">Attempt {{ $email->attempt }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-right sm:px-6">
                                    <button type="button" @click="view({{ $email->id }})"
                                        class="rounded-md border border-gray-200 px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                        View
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                    No email notifications match the current filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-4">
            {{ $emails->links() }}
        </div>

        {{-- Detail modal — read-only. --}}
        <x-ui.modal x-data="{ open: false }" x-init="$watch('detail', (value) => { open = value !== null; }); $watch('open', (value) => { if (!value) detail = null; })" :isOpen="false" class="w-full sm:max-w-[560px]">
            <div class="no-scrollbar relative max-h-[85vh] w-full overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="detail" x-cloak>
                <template x-if="detail">
                    <div>
                        <h4 style="padding-right: 2.5rem;" class="mb-1 text-xl font-semibold text-gray-800 dark:text-white/90">Email Notification Detail</h4>
                        <p class="mb-5 text-sm text-gray-600 dark:text-gray-300" style="overflow-wrap: anywhere;" x-text="detail.subject || '(no subject)'"></p>

                        <template x-if="detail.status === 'failed'">
                            <div class="mb-4 rounded-lg border border-error-200 bg-error-50 px-4 py-3 text-xs text-error-700 dark:border-error-500/30 dark:bg-error-500/10 dark:text-error-400">
                                <p class="font-semibold">Sending failed</p>
                                <p class="mt-1" style="overflow-wrap: anywhere;" x-text="detail.error || 'No error message was returned.'"></p>
                            </div>
                        </template>
                        <template x-if="detail.status === 'sending'">
                            <div class="mb-4 rounded-lg border border-gray-200 bg-orange-50 px-4 py-3 text-xs text-orange-600 dark:border-gray-800 dark:bg-orange-500/15 dark:text-orange-400">
                                The mail server never confirmed this attempt (the process may have stopped mid-send).
                            </div>
                        </template>

                        <div class="space-y-1.5 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-xs dark:border-gray-800 dark:bg-white/[0.03]">
                            @php
                                $row = fn (string $label, string $expr) => '<div class="flex items-start justify-between gap-4"><span class="shrink-0 text-gray-400">'.$label.'</span><span class="text-right font-medium text-gray-700 dark:text-gray-300" style="overflow-wrap: anywhere;" x-text="'.$expr.'"></span></div>';
                            @endphp
                            {!! $row('Log ID', 'detail.id') !!}
                            {!! $row('Status', "detail.statusLabel + (detail.attempt > 1 ? ' (attempt ' + detail.attempt + ')' : '')") !!}
                            {!! $row('Notification Type', 'detail.typeLabel') !!}
                            {!! $row('Template', "detail.templateName || '—'") !!}
                            {!! $row('To', "detail.to.join(', ') || '—'") !!}
                            {!! $row('CC', "detail.cc.join(', ') || '—'") !!}
                            {!! $row('BCC', "detail.bcc.join(', ') || '—'") !!}
                            <template x-if="detail.redirectedTo">
                                {!! $row('Redirected To', 'detail.redirectedTo + \' (QA redirect)\'') !!}
                            </template>
                            {!! $row('Offboardee', "detail.offboardeeName || '—'") !!}
                            {!! $row('Offboarding Request', "detail.offboardingRequestId ? '#' + detail.offboardingRequestId : '—'") !!}
                            {!! $row('Triggered By', "detail.trigger || '—'") !!}
                            {!! $row('Initiated By', "detail.triggeredBy || 'System'") !!}
                            <template x-if="detail.scheduleName">
                                {!! $row('Schedule', 'detail.scheduleName') !!}
                            </template>
                            <template x-if="detail.scheduledFor">
                                {!! $row('Intended Send Date', 'detail.scheduledFor') !!}
                            </template>
                            {!! $row('Delivery', "detail.delivery === 'queued' ? 'Background queue' : 'Immediate'") !!}
                            {!! $row('Attempted At', 'detail.createdAt') !!}
                            <template x-if="detail.sentAt">
                                {!! $row('Sent At', 'detail.sentAt') !!}
                            </template>
                            <template x-if="detail.failedAt">
                                {!! $row('Failed At', 'detail.failedAt') !!}
                            </template>
                            <template x-if="detail.smtpMessageId">
                                {!! $row('Message ID', 'detail.smtpMessageId') !!}
                            </template>
                        </div>

                        <template x-if="detail.attempts.length > 1">
                            <div class="mt-4">
                                <p class="mb-1.5 text-xs font-medium text-gray-500 dark:text-gray-400">Sending Attempts</p>
                                <ul class="space-y-1 text-xs">
                                    <template x-for="attempt in detail.attempts" :key="attempt.id">
                                        <li class="flex items-start justify-between gap-3 rounded-md px-2 py-1.5" :class="attempt.current ? 'bg-gray-100 dark:bg-white/5' : ''">
                                            <span class="text-gray-600 dark:text-gray-300">
                                                <span class="font-medium" x-text="'Attempt ' + attempt.attempt"></span>
                                                <span class="text-gray-400" x-text="' · ' + attempt.at"></span>
                                                <template x-if="attempt.error"><span class="block text-error-600 dark:text-error-400" style="overflow-wrap: anywhere;" x-text="attempt.error"></span></template>
                                            </span>
                                            <button type="button" x-show="!attempt.current" @click="view(attempt.id)" class="shrink-0 font-medium text-[#145a3a] hover:underline dark:text-[#3aa876]" x-text="attempt.statusLabel"></button>
                                            <span x-show="attempt.current" class="shrink-0 font-medium text-gray-500" x-text="attempt.statusLabel + ' (this)'"></span>
                                        </li>
                                    </template>
                                </ul>
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
