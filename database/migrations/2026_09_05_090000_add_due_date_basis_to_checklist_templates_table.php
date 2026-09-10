<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Due Date Basis feature — an admin picks whether this checklist's
 * `due_in_days` counts from the offboarding request's Resignation Date
 * (`notice_date`) or its Last Working Day (`last_working_day`); see
 * `ChecklistApprovalNotifier::attachAndNotify()`, which now computes each
 * new request's `due_at` from this instead of the moment the checklist
 * happens to be attached.
 *
 * Defaults existing rows to 'resignation_date' — the closer of the two
 * analogs to the old "due N days from whenever this got attached" behavior,
 * since a checklist is normally attached the same day a request is
 * submitted, close to its own Resignation Date. Admins can freely switch
 * any existing template to 'last_working_day' afterward; this migration
 * only picks a safe starting value so nothing is left unset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->string('due_date_basis')->default('resignation_date')->after('due_in_days');
        });
    }

    public function down(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->dropColumn('due_date_basis');
        });
    }
};
