<?php

namespace App\Support;

/**
 * Translates `OffboardingRequest::approverActivityTimeline()`'s raw step
 * arrays into the same display text/color bucket the admin-facing
 * Offboarding Status/Timeline modal's "Timeline" tab already computes
 * client-side in Alpine (`status-timeline-modal.blade.php`'s own `:class`/
 * `x-text` ternaries) — needed because the Clearance Form + Timeline PDF
 * (`clearance-form/_timeline.blade.php`) renders this same data server-side,
 * where there's no Alpine to evaluate those ternaries. Kept as its own small
 * static helper (same pattern as `UserAgentParser`) rather than inlined in
 * the Blade view, so the port from the live tab's JS logic lives in one
 * place and stays easy to diff against it if that tab's own logic ever
 * changes. `approverActivityTimeline()` itself stays the single,
 * display-agnostic source of truth consumed by both the live tab and this
 * PDF — it has no reason to know about either one's own presentation.
 */
class OffboardingTimelineStepPresenter
{
    /**
     * 1:1 port of the badge-text ternary in `status-timeline-modal.blade.php`
     * (Timeline tab, the `x-text` on the rich-step status badge). Only ever
     * called for a rich step (`$step['rich'] === true`). An approved
     * checklist always reads "Completed" here, on-time vs late conveyed
     * only by `badgeClass()`'s color below — not by a different word.
     */
    public static function badgeLabel(array $step): string
    {
        if (! empty($step['isOverdue'])) {
            return 'Overdue';
        }

        if (($step['isGeneralSignatory'] ?? false) && ($step['hasTasks'] ?? null) === false && $step['status'] === 'pending') {
            return 'Ready for Approval';
        }

        if ($step['status'] === 'approved') {
            return 'Completed';
        }

        if ($step['status'] === 'viewed' || ($step['status'] === 'pending' && ! empty($step['hasRecordedProgress']))) {
            return 'In Progress';
        }

        if ($step['status'] === 'on_hold') {
            return 'On Hold';
        }

        return ucfirst($step['status']);
    }

    /**
     * Same precedence as `badgeLabel()`, mapped to the PDF-only badge CSS
     * classes declared in `clearance-form/pdf-with-timeline.blade.php` — a
     * simpler color bucket than the live tab's own `:class` object (no dark
     * mode/Tailwind parity needed in a static, printed document).
     */
    public static function badgeClass(array $step): string
    {
        if (! empty($step['isOverdue'])) {
            return 'badge-overdue';
        }

        if (($step['isGeneralSignatory'] ?? false) && ($step['hasTasks'] ?? null) === false && $step['status'] === 'pending') {
            return 'badge-cleared';
        }

        if ($step['status'] === 'approved') {
            return ! empty($step['wasCompletedLate']) ? 'badge-error' : 'badge-cleared';
        }

        if ($step['status'] === 'declined') {
            return 'badge-error';
        }

        if ($step['status'] === 'on_hold') {
            return 'badge-hold';
        }

        if ($step['status'] === 'viewed' || ($step['status'] === 'pending' && ! empty($step['hasRecordedProgress']))) {
            return 'badge-progress';
        }

        return 'badge-pending';
    }

    /**
     * Port of `checklist-item-status-list.blade.php`'s own status-label map
     * — the same 5 statuses a checklist item's `status` field can ever
     * hold. A checked-off item (by its Task Assignee) reads "Cleared" here
     * — deliberately the opposite word from `badgeLabel()`'s
     * checklist-level "Completed" above, so the two levels (item vs whole
     * checklist) never read as the same kind of event.
     */
    public static function checklistItemStatusLabel(string $status): string
    {
        return match ($status) {
            'completed' => 'Cleared',
            'on_hold' => 'On Hold',
            'declined' => 'Declined',
            'in_progress' => 'In Progress',
            default => 'Pending',
        };
    }
}
