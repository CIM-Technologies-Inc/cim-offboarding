{{--
    The action button(s) for one approval card — Review & Approve (General
    Signatory/Final Approval), or View Checklist + Checklist Status + Assign
    To/Assign Checklist (ordinary checklist card). Shared by Card View and
    List View (@include'd from both in index.blade.php) so this block's
    kind-based branching lives in exactly one place. Relies on Blade's
    default (non-isolated) @include scoping — $approval, $checklistStatusButtonLabel,
    and the enclosing @foreach's $loop are all already in scope from the
    calling view, never re-declared here. Only the WRAPPING container around
    this partial varies between the two views (Card View's bottom button row
    vs. List View's own buttons column) — this partial itself renders no
    outer wrapper, only the buttons themselves, so it never dictates layout.
--}}
@if (($approval['kind'] ?? null) === 'general_signatory')
    {{-- Merges in any client-side patch left by
         general-signatory-modal.blade.php's own
         rememberCardOverride() (declined-then-
         reopened this session) — see that
         method's own docblock for why the plain
         @js($approval) snapshot below, baked in
         at this page's render time, would
         otherwise show stale pre-decline data
         on every later reopen. --}}
    <button type="button" @click.stop="$dispatch('open-general-signatory-modal', Object.assign({}, @js($approval), window.__approvalCardOverrides?.['{{ $approval['id'] }}'] || {}))"
        class="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-[#145a3a] px-3 py-2 text-sm font-medium text-white hover:bg-[#0f4630]">
        <svg width="16" height="16" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path fill-rule="evenodd" clip-rule="evenodd" d="M3 4.5C3 4.08579 3.33579 3.75 3.75 3.75H14.25C14.6642 3.75 15 4.08579 15 4.5C15 4.91421 14.6642 5.25 14.25 5.25H3.75C3.33579 5.25 3 4.91421 3 4.5ZM3 9C3 8.58579 3.33579 8.25 3.75 8.25H14.25C14.6642 8.25 15 8.58579 15 9C15 9.41421 14.6642 9.75 14.25 9.75H3.75C3.33579 9.75 3 9.41421 3 9ZM3.75 12.75C3.33579 12.75 3 13.0858 3 13.5C3 13.9142 3.33579 14.25 3.75 14.25H10.5C10.9142 14.25 11.25 13.9142 11.25 13.5C11.25 13.0858 10.9142 12.75 10.5 12.75H3.75Z" fill="currentColor" />
        </svg>
        Review & Approve
    </button>
@elseif (($approval['kind'] ?? null) === 'final_approval')
    <button type="button" @click.stop="$dispatch('open-final-approval-modal', @js($approval))"
        class="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-[#145a3a] px-3 py-2 text-sm font-medium text-white hover:bg-[#0f4630]">
        <svg width="16" height="16" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path fill-rule="evenodd" clip-rule="evenodd" d="M3 4.5C3 4.08579 3.33579 3.75 3.75 3.75H14.25C14.6642 3.75 15 4.08579 15 4.5C15 4.91421 14.6642 5.25 14.25 5.25H3.75C3.33579 5.25 3 4.91421 3 4.5ZM3 9C3 8.58579 3.33579 8.25 3.75 8.25H14.25C14.6642 8.25 15 8.58579 15 9C15 9.41421 14.6642 9.75 14.25 9.75H3.75C3.33579 9.75 3 9.41421 3 9ZM3.75 12.75C3.33579 12.75 3 13.0858 3 13.5C3 13.9142 3.33579 14.25 3.75 14.25H10.5C10.9142 14.25 11.25 13.9142 11.25 13.5C11.25 13.0858 10.9142 12.75 10.5 12.75H3.75Z" fill="currentColor" />
        </svg>
        Review & Approve
    </button>
@else
    {{-- Merges in any client-side patch left by
         checklist-modal.blade.php's own
         rememberCardOverride() — see that
         method's own docblock. --}}
    <button type="button" @click.stop="$dispatch('open-checklist-modal', Object.assign({}, @js($approval), window.__approvalCardOverrides?.['{{ $approval['id'] }}'] || {}))"
        class="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-[#145a3a] px-3 py-2 text-sm font-medium text-white hover:bg-[#0f4630]">
        <svg width="16" height="16" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path fill-rule="evenodd" clip-rule="evenodd" d="M3 4.5C3 4.08579 3.33579 3.75 3.75 3.75H14.25C14.6642 3.75 15 4.08579 15 4.5C15 4.91421 14.6642 5.25 14.25 5.25H3.75C3.33579 5.25 3 4.91421 3 4.5ZM3 9C3 8.58579 3.33579 8.25 3.75 8.25H14.25C14.6642 8.25 15 8.58579 15 9C15 9.41421 14.6642 9.75 14.25 9.75H3.75C3.33579 9.75 3 9.41421 3 9ZM3.75 12.75C3.33579 12.75 3 13.0858 3 13.5C3 13.9142 3.33579 14.25 3.75 14.25H10.5C10.9142 14.25 11.25 13.9142 11.25 13.5C11.25 13.0858 10.9142 12.75 10.5 12.75H3.75Z" fill="currentColor" />
        </svg>
        View Checklist
    </button>
    {{-- Opens the Offboarding Status/Timeline modal — what clicking
         this card itself used to do, before that was moved to open
         the Checklist instead (see the card's own @click above).
         @click.stop keeps this from ALSO triggering the card's own
         click handler. --}}
    <button type="button" @click.stop="$dispatch('open-offboardee-modal', @js($approval))"
        class="flex flex-1 items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
        <svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M10 6.667v3.333l2.5 1.667" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
            <path d="M17.5 10A7.5 7.5 0 1 1 10 2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
            <path d="M17.5 4.167V7.5h-3.333" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        {{ $checklistStatusButtonLabel }}
    </button>
    {{-- Hidden once this card combines more than one checklist —
         bulk "Assign Checklist" below takes over for that case,
         since whole-card reassignment to a single person no longer
         makes sense once several distinct checklists are involved.
         Also hidden for a headless "Use Task Assignee as Clearance
         Signatory" card (`reassignChecklistUrl` null) — there's no
         owner to reassign FROM on a checklist that's designed to have
         none. Hands every not-yet-completed task on this checklist to
         the selected employee — see
         `ChecklistDelegationController::reassignChecklist()`'s own
         docblock — reusing checklist-modal.blade.php's own
         reassignChecklist()/SweetAlert picker without needing the
         modal itself open first (see that component's own
         `@reassign-checklist-request.window` listener). Merges in any
         client-side patch left by that same handler, same convention
         the other two card buttons already use. --}}
    @if ($approval['isPrimaryApprover'] && count($approval['checklistTemplates']) <= 1 && $approval['reassignChecklistUrl'])
        <button type="button" title="Assign To" @click.stop="$dispatch('reassign-checklist-request', Object.assign({}, @js($approval), window.__approvalCardOverrides?.['{{ $approval['id'] }}'] || {}))"
            class="flex items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M10 10a3.75 3.75 0 100-7.5 3.75 3.75 0 000 7.5ZM3.5 17.25a6.5 6.5 0 0113 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                <path d="M16.25 6.25v4M18.25 8.25h-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
    @endif
    {{-- Bulk "Assign Checklist": only shown when this card actually
         combines more than one checklist template (e.g. the same
         person is both a Clearance Signatory and the Immediate Head)
         — a single-checklist card has nothing else to bulk-open. --}}
    @if ($approval['showAssignChecklistPool'])
        <button type="button" title="Assign Checklist" @click.stop="$dispatch('open-assign-checklist-pool-modal', @js($approval))"
            class="flex items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M8.75 4.167h-2.5A1.667 1.667 0 0 0 4.583 5.833v9.167A1.667 1.667 0 0 0 6.25 16.667h7.5a1.667 1.667 0 0 0 1.667-1.667V5.833a1.667 1.667 0 0 0-1.667-1.666h-2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                <path d="M8.75 2.5h2.5a.833.833 0 0 1 .833.833V4.167a.833.833 0 0 1-.833.833h-2.5a.833.833 0 0 1-.833-.833V3.333A.833.833 0 0 1 8.75 2.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                <path d="M7.5 10.833l1.667 1.667L12.5 9.167" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
    @endif
@endif
