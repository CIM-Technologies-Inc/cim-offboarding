<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time data correction to match the "Until End of the Day" label the
 * Offboarding Status/Timeline tabs already show next to every checklist's
 * due date (see `status-timeline-modal.blade.php`). Every `due_at` this
 * migration finds was computed BEFORE `ChecklistApprovalNotifier::attachAndNotify()`/
 * `ApprovalController::extendAllDue()` started appending `->endOfDay()`, so
 * it's still sitting at midnight (00:00:00) of its due date rather than the
 * end of it (23:59:59) — meaning a checklist read as overdue the instant
 * its due date's calendar day BEGAN, a full day earlier than that label
 * promises. Shifts the TIME only, never the calendar date itself, so which
 * day is due is completely unaffected — only when, within that day, a
 * checklist actually becomes overdue.
 *
 * Scoped to exactly `00:00:00` so this can never touch a row a later
 * extension/attachment already correctly set to end-of-day, making this
 * safe to run more than once (a fresh `due_at` computed after this
 * migration already lands at 23:59:59 and is excluded by the same WHERE
 * clause).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('offboarding_request_approvers')
            ->whereNotNull('due_at')
            ->whereTime('due_at', '00:00:00')
            ->update([
                'due_at' => DB::raw("DATE_ADD(DATE(due_at), INTERVAL '23:59:59' HOUR_SECOND)"),
            ]);
    }

    /**
     * Deliberately a no-op: reversing this would mean guessing which
     * 23:59:59 rows used to be genuine midnight values this migration
     * shifted versus ones a later, correctly-computed extension already
     * set on its own — that distinction isn't recoverable, and rolling
     * every 23:59:59 row back to midnight would reintroduce the exact bug
     * this migration exists to fix.
     */
    public function down(): void
    {
        //
    }
};
