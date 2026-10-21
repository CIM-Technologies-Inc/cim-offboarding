<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Multiple Signatory Checklist" (`checklist_templates.use_task_assignee_as_signatory`)
 * is no longer an independently submitted checkbox on the Create/Edit
 * Checklist Template forms — it's now derived purely from the Checklist
 * Priority selection (`checklist_classification`): a Final Pay checklist
 * (`is_final_pay_checklist = true`) is always treated as one, every other
 * priority never is. See `ChecklistTemplateController::store()`/`update()`.
 *
 * This establishes that same invariant for every row that already existed
 * before the change, so no already-saved template can be left pointing the
 * old, now-impossible-to-reach way (e.g. a Primary checklist someone had
 * manually flagged as Multiple Signatory, or a Final Pay checklist that
 * somehow wasn't). Verified against live data before writing this migration:
 * every existing row already satisfies the new rule, so this is a no-op in
 * practice today — it exists for correctness in any environment where that
 * might not hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('checklist_templates')
            ->where('is_final_pay_checklist', true)
            ->update(['use_task_assignee_as_signatory' => true]);

        DB::table('checklist_templates')
            ->where('is_final_pay_checklist', false)
            ->update(['use_task_assignee_as_signatory' => false]);
    }

    /**
     * Deliberately a no-op: reversing would mean restoring whatever
     * `use_task_assignee_as_signatory` value a row had before this ran,
     * which this migration doesn't record — same reasoning as this
     * project's other one-directional backfill migrations.
     */
    public function down(): void
    {
        //
    }
};
