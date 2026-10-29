@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb :pageTitle="$template->title" />

    <div class="mb-6 flex items-center justify-between">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ $template->department ?? 'No department' }} &middot; Created by {{ $template->creator->name ?? '—' }} on {{ $template->created_at->format('M d, Y') }}
        </p>
        <a href="{{ route('checklist-templates.index') }}"
            class="text-sm font-medium text-brand-500 hover:text-brand-600">
            &larr; Back to Checklists
        </a>
    </div>

    {{-- Approvers — read live from this template's saved configuration
         (`department_head_id` = the selected group's Group Head, and the
         Secondary Approver settings). Existing offboarding requests keep the
         approvers snapshotted when their checklist was assigned. --}}
    @php
        $inactiveSuffix = fn ($employee) => $employee && $employee->status !== 'active' ? ' (inactive)' : '';
        $secondaryOn = $template->secondary_approver_enabled && ! $template->use_task_assignee_as_signatory && $template->secondarySignatory;
        $escalationDays = (int) $template->escalation_days;
    @endphp
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Primary Approver (Clearance Signatory)</p>
            @if ($template->use_task_assignee_as_signatory)
                <p class="mt-1 text-base font-semibold text-gray-800 dark:text-white/90">Task Assignees</p>
                <p class="mt-0.5 text-xs text-gray-400">Each item's assigned Task Assignee signs for their own item(s) — there is no single Clearance Signatory.</p>
            @elseif ($template->is_immediate_head_checklist)
                <p class="mt-1 text-base font-semibold text-gray-800 dark:text-white/90">Offboardee's Immediate Head</p>
                <p class="mt-0.5 text-xs text-gray-400">Assigned per offboarding request, from the Immediate Head selected on that request.</p>
            @elseif ($template->departmentHead)
                <p class="mt-1 text-base font-semibold text-gray-800 dark:text-white/90">
                    {{ $template->departmentHead->name }}{{ $inactiveSuffix($template->departmentHead) }}
                </p>
                <p class="mt-0.5 text-xs text-gray-400">
                    {{ $template->departmentHead->employee_code }}@if ($template->employeeGroup) &middot; Group Head of {{ $template->employeeGroup->name }}@endif
                </p>
            @else
                <p class="mt-1 text-base font-semibold text-gray-500 dark:text-gray-400">No Primary Approver Assigned</p>
                <p class="mt-0.5 text-xs text-gray-400">Select a Clearance Signatory group on the edit form — a checklist with no signatory isn't attached to offboarding requests.</p>
            @endif
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Secondary Approver</p>
            @if ($secondaryOn)
                <p class="mt-1 text-base font-semibold text-gray-800 dark:text-white/90">
                    {{ $template->secondarySignatory->name }}{{ $inactiveSuffix($template->secondarySignatory) }}
                </p>
                <p class="mt-0.5 text-xs text-gray-400">
                    {{ $template->secondarySignatory->employee_code }}
                    @if ($escalationDays)
                        &middot; Takes over if the primary hasn't completed the checklist after {{ $escalationDays }} {{ $template->escalation_day_type === 'working' ? 'working' : 'calendar' }} day{{ $escalationDays === 1 ? '' : 's' }}
                    @endif
                </p>
            @else
                <p class="mt-1 text-base font-semibold text-gray-500 dark:text-gray-400">No Secondary Approver Assigned</p>
                @if ($template->use_task_assignee_as_signatory)
                    <p class="mt-0.5 text-xs text-gray-400">Not applicable — this checklist's Task Assignees are its signatories.</p>
                @else
                    {{-- Opens THIS template's edit form with "Enable Secondary Approver"
                         pre-ticked and the settings section in view — nothing is saved
                         until HR/Admin chooses the approver and clicks Update. Only for
                         users who can save template changes. --}}
                    @can('offboarding-checklists.edit')
                        <a href="{{ route('checklist-templates.edit', ['checklistTemplate' => $template, 'assign_secondary' => 1]) }}"
                            class="shadow-theme-xs mt-3 inline-flex items-center gap-2 rounded-lg bg-[#145a3a] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#0f4630]">
                            <svg width="16" height="16" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 3.75V14.25M3.75 9H14.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            Assign Secondary Approver
                        </a>
                    @endcan
                @endif
            @endif
        </div>
    </div>

    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="max-w-full overflow-x-auto custom-scrollbar">
            <table class="w-full min-w-[500px]">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <th class="px-5 py-3 text-left sm:px-6">
                            <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">#</p>
                        </th>
                        <th class="px-5 py-3 text-left sm:px-6">
                            <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Clearance Item</p>
                        </th>
                        <th class="px-5 py-3 text-left sm:px-6">
                            <p class="font-medium text-gray-500 text-theme-xs dark:text-gray-400">Signatory</p>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($template->items as $index => $item)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-gray-500 text-theme-sm dark:text-gray-400">{{ $index + 1 }}</p>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="font-medium text-gray-800 text-theme-sm dark:text-white/90">{{ $item->title }}</p>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-gray-500 text-theme-sm dark:text-gray-400">{{ $item->signatory->name ?? 'Unassigned' }}</p>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
