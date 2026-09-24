<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-request snapshot of the Clearance Signing Due Date — computed once at
 * checklist attachment time (Last Working Day + the checklist template's own
 * `clearance_signing_deadline_days`, see `ChecklistApprovalNotifier::attachAndNotify()`)
 * and recalculated by "Extend Due" (`ApprovalController::extendAllDue()`)
 * alongside the existing `due_at` column, which this is deliberately
 * independent of — see that column's own migration/docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            $table->timestamp('clearance_signing_due_at')->nullable()->after('due_at');
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            $table->dropColumn('clearance_signing_due_at');
        });
    }
};
