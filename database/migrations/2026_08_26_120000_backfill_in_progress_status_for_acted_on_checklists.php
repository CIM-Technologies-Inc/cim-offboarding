<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time correction for `offboarding_request_approvers` rows that were
 * already genuinely acted on (a task assignee had checked/held at least one
 * item) before `OffboardingRequestApprover::markInProgressIfPending()`
 * existed to bump `status` from 'pending' to 'viewed' at the moment that
 * happens. Without this, an already-checked item's checklist would keep
 * showing "Pending" on the Offboarding Status page until someone happened
 * to save progress on it again. Only ever moves 'pending' -> 'viewed' —
 * never touches an already-'approved'/'declined' row, so no final status is
 * reopened.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('offboarding_request_approvers')
            ->where('status', 'pending')
            ->whereExists(function ($query) {
                $query->selectRaw(1)
                    ->from('checklist_item_progress')
                    ->whereColumn('checklist_item_progress.offboarding_request_approver_id', 'offboarding_request_approvers.id');
            })
            ->update(['status' => 'viewed']);
    }

    public function down(): void
    {
        // Not reversible — there's no record of which of these rows were
        // 'pending' purely because of this backfill versus a later,
        // legitimate transition (e.g. the primary approver has since viewed
        // the page too). Intentionally a no-op.
    }
};
