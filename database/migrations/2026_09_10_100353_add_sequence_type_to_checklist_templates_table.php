<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Primary/Secondary classification the Sync workflow stages checklist
 * attachment/notification through (see `ChecklistCompletionService::checkPrimaryChecklistsCompletion()`).
 * Irrelevant to a Final Pay checklist — that's still identified solely by
 * the existing `is_final_pay_checklist` flag, kept deliberately separate
 * per the feature's own requirement that Final Pay stays a distinct concept
 * from Primary/Secondary, not a third value competing with them.
 *
 * Backfills every EXISTING template so Sync mode behaves sensibly the
 * moment it's turned on, with no required admin action: a non-final-pay
 * template whose title mentions "Final" (but not "Final Pay") becomes
 * `secondary` — matching this feature's own worked example (IT/HR/
 * Department Head/Admin = Primary, "Final Checklist" = Secondary) — every
 * other non-final-pay template becomes `primary`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->enum('sequence_type', ['primary', 'secondary'])->nullable()->after('is_final_pay_checklist');
        });

        DB::table('checklist_templates')
            ->where('is_final_pay_checklist', false)
            ->where('title', 'like', '%Final%')
            ->where('title', 'not like', '%Final Pay%')
            ->update(['sequence_type' => 'secondary']);

        DB::table('checklist_templates')
            ->where('is_final_pay_checklist', false)
            ->whereNull('sequence_type')
            ->update(['sequence_type' => 'primary']);
    }

    public function down(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->dropColumn('sequence_type');
        });
    }
};
