<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-request snapshot of a General Signatory's Clearance Signing Due Date —
 * the General Signatory equivalent of `offboarding_request_approvers.clearance_signing_due_at`,
 * computed once at attachment (`ChecklistApprovalNotifier::notifyGeneralSignatories()`)
 * and recalculated by "Extend Due" (`ApprovalController::extendAllDue()`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_request_general_signatories', function (Blueprint $table) {
            $table->timestamp('due_at')->nullable()->after('is_final_pay_signatory');
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_request_general_signatories', function (Blueprint $table) {
            $table->dropColumn('due_at');
        });
    }
};
