@extends('layouts.app')

@section('content')
    <x-common.page-breadcrumb :pageTitle="$template->title" />

    <div class="mb-6 flex items-center justify-between">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ $template->department ?? 'No department' }} &middot; Created by {{ $template->creator->name ?? '—' }} on {{ $template->created_at->format('M d, Y') }}
            @if ($template->is_general_signatory)
                &middot; <span class="font-medium text-[#145a3a] dark:text-[#3aa876]">General Signatory</span>
            @endif
        </p>
        <a href="{{ route('checklist-templates.index') }}"
            class="text-sm font-medium text-brand-500 hover:text-brand-600">
            &larr; Back to Checklists
        </a>
    </div>

    @if ($template->is_general_signatory)
        <div class="rounded-2xl border border-gray-200 bg-white p-6 text-sm text-gray-500 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-400">
            This is a General Signatory checklist — it has no clearance items. Its Department Head is added as an additional signatory on the Clearance Form and receives it on their Approval page once an offboarding request is created.
        </div>
    @else
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
    @endif
@endsection
