@php
    use App\Http\Controllers\EmailTemplateController;

    $metadata = EmailTemplateController::templateMetadataFor($item);
    $isBeingEdited = $template && $template->id === $item->id;
@endphp
<div class="rounded-lg border p-3 transition-shadow {{ $isBeingEdited
    ? 'border-[#145a3a] bg-[#145a3a]/5 shadow-md ring-1 ring-[#145a3a]/30 dark:border-[#3aa876] dark:bg-[#3aa876]/10 dark:ring-[#3aa876]/30'
    : 'border-gray-200 dark:border-gray-800' }}">
    <div class="flex flex-wrap items-center gap-1.5">
        <p class="truncate text-sm font-medium {{ $isBeingEdited ? 'text-[#145a3a] dark:text-[#3aa876]' : 'text-gray-700 dark:text-gray-300' }}">
            {{ $item->template_name }}
        </p>
        @if ($isBeingEdited)
            <span class="shrink-0 rounded-full bg-[#145a3a] px-2 py-0.5 text-[10px] font-medium text-white dark:bg-[#3aa876] dark:text-gray-900">
                Editing
            </span>
        @endif
        @if ($isSystem)
            <span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-400">
                System
            </span>
        @else
            <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                Customizable
            </span>
        @endif
        @if ($item->is_default_announcement)
            <span class="shrink-0 rounded-full bg-[#145a3a]/10 px-2 py-0.5 text-[10px] font-medium text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]">
                Default: Announcement
            </span>
        @endif
        @if ($item->is_default_reactivation)
            <span class="shrink-0 rounded-full bg-[#145a3a]/10 px-2 py-0.5 text-[10px] font-medium text-[#145a3a] dark:bg-[#3aa876]/15 dark:text-[#3aa876]">
                Default: Reactivation
            </span>
        @endif
        <span class="ml-auto shrink-0 text-[10px] font-medium {{ $item->is_active ? 'text-[#145a3a] dark:text-[#3aa876]' : 'text-gray-400' }}">
            {{ $item->is_active ? 'Active' : 'Inactive' }}
        </span>
    </div>

    @if ($isSystem)
        <p class="mt-1.5 text-sm leading-snug text-gray-600 dark:text-gray-300">
            {{ $metadata['purpose'] }}
        </p>
    @else
        {{-- Customizable templates have no fixed purpose text — show the start
             of their actual email body instead (plain text, placeholders kept
             as-is, escaped). Recomputed on every page load, so it reflects
             the body as soon as it's saved. --}}
        @php($preview = $item->bodyPreview())
        <p style="overflow-wrap: anywhere" class="mt-1.5 text-xs leading-snug {{ $preview ? 'text-gray-600 dark:text-gray-300' : 'italic text-gray-400' }}"
            @if ($preview) title="{{ $item->bodyPreview(600) }}" @endif>
            {{ $preview ?? 'No email body available.' }}
        </p>
    @endif

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
        @if ($isSystem)
            <button type="button" disabled title="System templates can't be deleted — deactivate instead."
                class="ml-auto cursor-not-allowed rounded-md border border-gray-200 px-2.5 py-1 text-xs font-medium text-gray-300 dark:border-gray-700 dark:text-gray-600">
                Delete
            </button>
        @else
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
        @endif
    </div>
</div>
