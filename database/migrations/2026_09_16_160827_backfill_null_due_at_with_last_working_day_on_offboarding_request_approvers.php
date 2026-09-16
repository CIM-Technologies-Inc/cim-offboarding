<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time data correction to match the newly-established rule (see
 * `ChecklistApprovalNotifier::attachAndNotify()`): a checklist template
 * with no `due_in_days` configured at all now defaults its due date to
 * the offboardee's own Last Working Day (+0 days) instead of having no
 * due date. Every EXISTING `OffboardingRequestApprover` row that was
 * already attached under the old (no-fallback) rule and still shows a
 * null `due_at` gets backfilled here so it matches what it would have
 * been assigned under the corrected rule — only rows genuinely missing a
 * due date are touched; anything that already has one (including a
 * previously-extended one) is left exactly as-is.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE offboarding_request_approvers AS approvers
            INNER JOIN offboarding_requests AS requests
                ON requests.id = approvers.offboarding_request_id
            SET approvers.due_at = requests.last_working_day
            WHERE approvers.due_at IS NULL
                AND requests.last_working_day IS NOT NULL
        SQL);
    }

    /**
     * Not reversible: nothing records which rows were genuinely null
     * before this ran versus already carrying a real due date, so there's
     * no way to distinguish "restore to null" from "leave alone" on a
     * rollback.
     */
    public function down(): void
    {
        //
    }
};
