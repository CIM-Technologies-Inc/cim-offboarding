{{--
    Numbered checklist badge list — the number as a small primary-colored
    badge beside each checklist's plain-text title (replacing the previous
    "N. Title" inline text), shared by Card View and List View (@include'd
    from both, inside each one's own `@if (!empty($approval['checklists']))`
    branch) so the badge styling/ordering logic lives in exactly one place.
    Each badge+title pair stays one unbreakable `whitespace-nowrap` flex
    child so a multi-checklist list wraps onto new lines whole-item-at-a-
    time, never splitting a badge from its own title — same wrapping
    behavior the previous plain-text version already had. Numbering itself
    is unchanged: still 1-based, still simply the array index of
    `$approval['checklists']` (see that array's own docblock in
    index.blade.php for how it's built/ordered) — only how the number is
    PRESENTED changed, never what it counts or the order it counts in.
    Expects $approval already in scope (Blade's default non-isolated
    @include scoping — same convention card-buttons.blade.php already
    uses).
--}}
<div class="flex flex-wrap items-center gap-x-4 gap-y-2">
    @foreach ($approval['checklists'] as $checklistIndex => $checklistEntry)
        <span class="inline-flex items-center gap-2 whitespace-nowrap">
            <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[#145a3a] text-xs font-semibold text-white dark:bg-[#3aa876]">
                {{ $checklistIndex + 1 }}
            </span>
            <span class="text-sm font-medium text-[#145a3a] dark:text-[#3aa876]">{{ $checklistEntry['title'] }}</span>
        </span>
    @endforeach
</div>
