<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time data correction for the Clearance Signing Deadline feature
 * (`ChecklistTemplate.clearance_signing_deadline_days` /
 * `GeneralSignatory.due_in_days`): every checklist template and General
 * Signatory that existed BEFORE this feature shipped was left at its
 * fillable-default of `null` by the migrations that added these columns —
 * so none of them ever got the feature's own documented default of 19
 * days, and no already-attached offboarding request shows a Clearance
 * Signing Due Date at all (see `ChecklistApprovalNotifier`/
 * `OffboardingRequestApprover::recalculateClearanceSigningDueDate()`,
 * which only ever computes a due date from a non-null deadline).
 *
 * Backfills the 19-day default onto every such template/General Signatory,
 * then recalculates `clearance_signing_due_at`/`due_at` on every already-
 * attached `offboarding_request_approvers`/`offboarding_request_general_signatories`
 * row the exact same way `recalculateClearanceSigningDueDate()` would
 * (Last Working Day + deadline days, end of day) — so existing, currently
 * assigned checklists/General Signatories immediately show the due date
 * without needing an admin to manually re-open and re-save every one, or
 * an "Extend Due" to be triggered first.
 *
 * Scoped to `IS NULL` at every step, so this only ever fills in a genuinely
 * unset value — never overwrites a deadline an admin has already
 * configured, or a due date already computed from one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('checklist_templates')
            ->whereNull('clearance_signing_deadline_days')
            ->update(['clearance_signing_deadline_days' => 19]);

        DB::table('general_signatories')
            ->whereNull('due_in_days')
            ->update(['due_in_days' => 19]);

        DB::statement("
            UPDATE offboarding_request_approvers ora
            INNER JOIN checklist_templates ct ON ct.id = ora.checklist_template_id
            INNER JOIN offboarding_requests orq ON orq.id = ora.offboarding_request_id
            SET ora.clearance_signing_due_at = DATE_ADD(
                DATE_ADD(DATE(orq.last_working_day), INTERVAL ct.clearance_signing_deadline_days DAY),
                INTERVAL '23:59:59' HOUR_SECOND
            )
            WHERE ora.clearance_signing_due_at IS NULL
              AND ct.clearance_signing_deadline_days IS NOT NULL
        ");

        DB::statement("
            UPDATE offboarding_request_general_signatories orgs
            INNER JOIN general_signatories gs ON gs.id = orgs.general_signatory_id
            INNER JOIN offboarding_requests orq ON orq.id = orgs.offboarding_request_id
            SET orgs.due_at = DATE_ADD(
                DATE_ADD(DATE(orq.last_working_day), INTERVAL gs.due_in_days DAY),
                INTERVAL '23:59:59' HOUR_SECOND
            )
            WHERE orgs.due_at IS NULL
              AND gs.due_in_days IS NOT NULL
        ");
    }

    /**
     * Deliberately a no-op: reversing this would mean guessing which
     * `clearance_signing_deadline_days`/`due_in_days`/`clearance_signing_due_at`/
     * `due_at` values this migration itself set to 19/a computed date,
     * versus ones an admin has since explicitly configured on their own —
     * that distinction isn't recoverable, same reasoning as the sibling
     * `backfill_due_at_to_end_of_day` migration.
     */
    public function down(): void
    {
        //
    }
};
