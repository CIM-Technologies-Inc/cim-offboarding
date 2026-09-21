@extends('layouts.app')

@section('content')
    <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Email and Notification</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Create and manage email templates used throughout the offboarding process.
        </p>
    </div>

    <div x-data="emailWorkspace(@js(session('success')), @js($errors->any() ? $errors->first() : null))"
        @open-view-template-modal.window="viewing = $event.detail">
        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,2fr)_minmax(0,1fr)]">
            <!-- LEFT (25%) -->
            <div class="h-fit rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
                <p class="mb-2 px-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Templates</p>

                <div class="max-h-[70vh] space-y-2 overflow-y-auto custom-scrollbar">
                    @forelse ($templates as $item)
                        <div class="rounded-lg border p-3 {{ $template && $template->id === $item->id ? 'border-[#145a3a]/40 bg-[#145a3a]/5' : 'border-gray-200 dark:border-gray-800' }}">
                            <div class="flex items-center gap-1.5">
                                <p class="truncate text-sm font-medium {{ $template && $template->id === $item->id ? 'text-[#145a3a] dark:text-[#3aa876]' : 'text-gray-700 dark:text-gray-300' }}">
                                    {{ $item->template_name }}
                                </p>
                                @if ($item->is_default_announcement)
                                    <span class="shrink-0 rounded-full bg-[#145a3a]/10 px-2 py-0.5 text-[10px] font-medium text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]">
                                        Default
                                    </span>
                                @endif
                            </div>

                            @if ($item->scheduleLabel())
                                <p class="mt-1 flex items-center gap-1 text-[11px] font-medium text-blue-600 dark:text-blue-400">
                                    <svg width="12" height="12" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M10 5V10L13.3333 11.6667" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        <circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.5" />
                                    </svg>
                                    {{ $item->scheduleLabel() }}
                                </p>
                            @endif

                            <div class="mt-2 flex items-center gap-1.5">
                                <button type="button"
                                    @click="$dispatch('open-view-template-modal', { name: @js($item->template_name), subject: @js($item->subject), body: @js($item->html_content) })"
                                    class="rounded-md border border-gray-200 px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                    View
                                </button>
                                <a href="{{ route('email-templates.edit', $item) }}"
                                    class="rounded-md border border-gray-200 px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                    Edit
                                </a>
                                <label class="relative inline-flex cursor-pointer items-center" title="{{ $item->is_active ? 'Active' : 'Inactive' }}">
                                    <input type="checkbox" class="peer sr-only disabled:cursor-not-allowed disabled:opacity-50"
                                        @change="toggleTemplateStatus($event, '{{ route('email-templates.toggle-status', $item) }}')"
                                        @checked($item->is_active) />
                                    <div
                                        class="peer h-5 w-9 rounded-full bg-gray-200 transition-colors duration-200 peer-checked:bg-[#145a3a] peer-focus:outline-hidden after:absolute after:top-0.5 after:left-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:transition-all after:duration-200 after:content-[''] peer-checked:after:translate-x-4 dark:bg-gray-700">
                                    </div>
                                </label>
                                <form method="POST" action="{{ route('email-templates.destroy', $item) }}" class="ml-auto" x-data="{ confirmed: false }"
                                    @submit="if (!confirmed) {
                                        $event.preventDefault();
                                        Swal.fire({
                                            title: 'Delete this template?',
                                            text: 'You are about to delete &quot;{{ $item->template_name }}&quot;. This cannot be undone.',
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
                                    <button type="submit" data-turbo-submits-with="Deleting..."
                                        class="rounded-md border border-error-300 px-2.5 py-1 text-xs font-medium text-error-500 hover:bg-error-50 dark:border-error-500/30 dark:hover:bg-error-500/10">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <p class="px-1 py-6 text-center text-sm text-gray-400">No templates yet.</p>
                    @endforelse
                </div>
            </div>

            <!-- CENTER (50%) -->
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
                <form id="templateForm" method="POST"
                    action="{{ $template ? route('email-templates.update', $template) : route('email-templates.store') }}">
                    @csrf
                    @if ($template)
                        @method('PUT')
                    @endif

                    <div class="grid grid-cols-1 gap-5">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                Template Name <span class="text-error-500">*</span>
                            </label>
                            <input type="text" name="template_name" required
                                value="{{ old('template_name', $template->template_name ?? '') }}"
                                placeholder="e.g. Exit Interview Invitation"
                                class="h-11 w-full rounded-xl border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-sm placeholder:text-gray-400 focus:border-[#145a3a]/50 focus:outline-hidden focus:ring-3 focus:ring-[#145a3a]/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
                        </div>

                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                Email Subject <span class="text-error-500">*</span>
                            </label>
                            <input type="text" name="subject" id="templateSubject" required
                                value="{{ old('subject', $template->subject ?? '') }}"
                                placeholder="e.g. Your Exit Interview is Scheduled"
                                class="h-11 w-full rounded-xl border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-sm placeholder:text-gray-400 focus:border-[#145a3a]/50 focus:outline-hidden focus:ring-3 focus:ring-[#145a3a]/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
                        </div>

                        <div class="flex items-center gap-2">
                            <input type="checkbox" id="is_default_announcement" name="is_default_announcement" value="1"
                                @checked(old('is_default_announcement', $template->is_default_announcement ?? false))
                                class="h-4 w-4 rounded border-gray-300 text-[#145a3a] accent-[#145a3a] focus:ring-[#145a3a]/40 dark:border-gray-700" />
                            <label for="is_default_announcement" class="text-sm font-medium text-gray-700 dark:text-gray-400">
                                Default Template (Offboarding Announcement)
                            </label>
                        </div>

                        <!-- <div x-data="{
                                isScheduled: {{ old('is_scheduled', $template->is_scheduled ?? false) ? 'true' : 'false' }},
                                scheduleType: '{{ old('schedule_type', $template->schedule_type ?? 'one_time') }}',
                            }">
                            <div class="flex items-center gap-2">
                                <input type="checkbox" id="is_scheduled" name="is_scheduled" value="1" x-model="isScheduled"
                                    class="h-4 w-4 rounded border-gray-300 text-[#145a3a] accent-[#145a3a] focus:ring-[#145a3a]/40 dark:border-gray-700" />
                                <label for="is_scheduled" class="text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Schedule Email/Notification
                                </label>
                            </div>

                            <div x-show="isScheduled" x-cloak class="mt-3 space-y-4">
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                        Schedule Type
                                    </label>
                                    <select name="schedule_type" x-model="scheduleType"
                                        class="h-11 w-full rounded-xl border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-sm focus:border-[#145a3a]/50 focus:outline-hidden focus:ring-3 focus:ring-[#145a3a]/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                                        <option value="one_time">One-time (Before/After Last Working Day)</option>
                                        <option value="recurring">Recurring (Every N Days from Request Creation)</option>
                                    </select>
                                </div>

                                <div x-show="scheduleType === 'one_time'" x-cloak class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                            Timing
                                        </label>
                                        <select name="schedule_timing"
                                            class="h-11 w-full rounded-xl border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-sm focus:border-[#145a3a]/50 focus:outline-hidden focus:ring-3 focus:ring-[#145a3a]/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                                            <option value="before" @selected(old('schedule_timing', $template->schedule_timing ?? 'before') === 'before')>Before Last Working Day</option>
                                            <option value="after" @selected(old('schedule_timing', $template->schedule_timing ?? '') === 'after')>After Last Working Day</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                            Number of Days
                                        </label>
                                        <input type="number" name="schedule_days" min="1" step="1"
                                            value="{{ old('schedule_days', $template->schedule_days ?? '') }}"
                                            placeholder="e.g. 5"
                                            class="h-11 w-full rounded-xl border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-sm placeholder:text-gray-400 focus:border-[#145a3a]/50 focus:outline-hidden focus:ring-3 focus:ring-[#145a3a]/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
                                    </div>
                                </div>

                                <div x-show="scheduleType === 'recurring'" x-cloak>
                                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                        Send Every (Days)
                                    </label>
                                    <input type="number" name="schedule_interval_days" min="1" step="1"
                                        value="{{ old('schedule_interval_days', $template->schedule_interval_days ?? '') }}"
                                        placeholder="e.g. 5"
                                        class="h-11 w-full max-w-xs rounded-xl border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-sm placeholder:text-gray-400 focus:border-[#145a3a]/50 focus:outline-hidden focus:ring-3 focus:ring-[#145a3a]/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
                                    <p class="mt-1.5 text-xs text-gray-400">
                                        Sends every {N} days starting from the offboarding request's creation date, and stops automatically once the Last Working Day is reached.
                                    </p>
                                </div>
                            </div>
                        </div> -->

                        <div>
                            <div class="mb-1.5 flex items-center justify-between">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Email Body
                                </label>
                                <button type="button" @click="undoDraggedNames()"
                                    class="flex items-center gap-1 rounded-md border border-gray-200 px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
                                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M7.5 4.16667L3.33333 8.33333L7.5 12.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        <path d="M3.33333 8.33333H12.5C14.8012 8.33333 16.6667 10.1989 16.6667 12.5C16.6667 14.8012 14.8012 16.6667 12.5 16.6667H8.33333" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                    Undo
                                </button>
                            </div>
                            @php
                                $defaultBody = old('html_content', $template->html_content ?? '');
                                if ($defaultBody === '') {
                                    $defaultBody = '<p>Good day Mrs/Mr. approver,</p>'
                                        . '<p>Please be informed that you have pending for approval for offboardee.</p>'
                                        . '<p>CIM Offboarding link.</p>'
                                        . '<p>Thank you.</p>'
                                        . '<p>Best regards,</p>'
                                        . '<p>HR</p>';
                                    }
                            @endphp
                            <!-- Wrapper (not the textarea itself) carries the drop handler: Summernote
                                 hides the raw textarea and injects its own editable area next to it, so a
                                 handler bound to the textarea would never see the drop. -->
                            <div @dragover.prevent @drop.prevent="handleEmployeeDrop($event)">
                                <textarea name="html_content" id="templateBody" class="summernote" data-height="350">{{ $defaultBody }}</textarea>
                            </div>
                            <p class="mt-1.5 text-xs text-gray-400">
                                Tip: drag an employee's name from the list on the right (or click Insert) to replace the nearest "approver", "offboardee", or "employee" placeholder. Click Undo to remove all dragged names and restore the placeholders.
                                Available variables:
                                <code>@{{approver_name}}</code>, <code>@{{employee_name}}</code>, <code>@{{employee_number}}</code>, <code>@{{checklist_name}}</code>, <code>@{{due_date}}</code>, <code>@{{offboarding_link}}</code>.
                                For an overdue-checklist template, also available:
                                <code>@{{department}}</code>, <code>@{{position}}</code>, <code>@{{days_overdue}}</code>, <code>@{{checklist_status}}</code>, <code>@{{pending_items}}</code> (a table of each checklist item's status and due date).
                                For a "Notify Approver" follow-up on a checklist (e.g. once it's ready for approval):
                                <code>@{{checklist_summary}}</code> (a table of each item's Checked By/Date/Remarks) and <code>@{{approve_button}}</code> (a one-click Approve button, valid whenever the recipient is that checklist's Clearance Signatory).
                                For the "Checklist Due Date Extended" notification (sent automatically once an Extend Due request is saved):
                                <code>@{{clearance_signatory_name}}</code>, <code>@{{original_due_date}}</code>, <code>@{{extension_days}}</code>, and <code>@{{extended_due_date}}</code>.
                            </p>
                        </div>
                    </div>

                    <div class="mt-6 flex items-center justify-end gap-2 border-t border-gray-200 pt-5 dark:border-gray-800">
                        <button type="button" @click="showPreview()"
                            class="flex h-10 items-center justify-center rounded-xl border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.03]">
                            Preview
                        </button>
                        <button type="submit" data-turbo-submits-with="Saving..."
                            class="flex h-10 items-center justify-center rounded-xl bg-[#145a3a] px-5 text-sm font-medium text-white shadow-sm hover:bg-[#0f4630]">
                            {{ $template ? 'Update Template' : 'Save Template' }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- RIGHT (25%) -->
            <div class="h-fit rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]">
                <h4 class="mb-3 text-sm font-semibold text-gray-800 dark:text-white/90">Employees</h4>
                <p class="mb-3 text-xs text-gray-400">Drag a name onto the email body, or click Insert.</p>

                <div class="max-h-[75vh] space-y-1 overflow-y-auto custom-scrollbar">
                    @forelse ($employees as $employee)
                        <div draggable="true"
                            @dragstart="$event.dataTransfer.setData('text/plain', @js($employee->name))"
                            class="flex cursor-grab items-center justify-between gap-2 rounded-lg px-3 py-2 hover:bg-gray-50 active:cursor-grabbing dark:hover:bg-white/5">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-700 dark:text-gray-300">{{ $employee->name }}</p>
                                @if ($employee->department)
                                    <p class="truncate text-xs text-gray-400">{{ $employee->department }}</p>
                                @endif
                            </div>
                            <button type="button" @click="insertEmployeeName(@js($employee->name))"
                                class="shrink-0 rounded-md bg-[#145a3a]/10 px-2 py-1 text-[11px] font-medium text-[#145a3a] hover:bg-[#145a3a]/20 dark:text-[#3aa876]">
                                Insert
                            </button>
                        </div>
                    @empty
                        <p class="px-1 py-6 text-center text-sm text-gray-400">No employees found.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- VIEW TEMPLATE MODAL -->
        <x-ui.modal x-data="{ open: false }" @open-view-template-modal.window="open = true" :isOpen="false" class="max-w-[640px]">
            <div class="no-scrollbar relative max-h-[85vh] w-full max-w-[640px] overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="viewing" x-cloak>
                <template x-if="viewing">
                    <div>
                        <h4 class="mb-1 text-xl font-semibold text-gray-800 dark:text-white/90" x-text="viewing.name"></h4>
                        <p class="mb-5 text-sm text-gray-500 dark:text-gray-400">
                            Subject: <span x-text="viewing.subject"></span>
                        </p>

                        <div class="rounded-xl border border-gray-200 bg-[#F8F9FA] p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                            <div class="email-preview-body text-sm text-gray-700 dark:text-gray-300" x-html="viewing.body"></div>
                        </div>

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

        <!-- PREVIEW MODAL -->
        <x-ui.modal x-data="{ open: false }" @open-preview-modal.window="open = true" :isOpen="false" class="max-w-[640px]">
            <div class="no-scrollbar relative max-h-[85vh] w-full max-w-[640px] overflow-y-auto rounded-3xl bg-white p-6 dark:bg-gray-900 lg:p-8" x-show="open" x-cloak>
                <h4 class="mb-1 text-xl font-semibold text-gray-800 dark:text-white/90">Email Preview</h4>
                <p class="mb-5 text-sm text-gray-500 dark:text-gray-400">This is how the current, unsaved content will look.</p>

                <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <div class="bg-[#F8F9FA] px-5 py-4 dark:bg-white/[0.03]">
                        <p class="text-xs text-gray-400">Subject</p>
                        <p class="mt-0.5 text-base font-semibold text-gray-800 dark:text-white/90" x-text="previewSubject || '(no subject)'"></p>
                    </div>
                    <div class="email-preview-body min-h-[150px] p-6 text-sm text-gray-700 dark:text-gray-300" x-html="previewHtml"></div>
                </div>

                <div class="mt-6 flex justify-end">
                    <button @click="open = false" type="button"
                        class="flex justify-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                        Close
                    </button>
                </div>
            </div>
        </x-ui.modal>
    </div>
@endsection
