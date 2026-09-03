<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots the Employee behind whichever `FinalApprover` config row is
 * active at the moment this request is created (or reset) — a frozen
 * reference to the EMPLOYEE, not to the `final_approvers` row itself, so a
 * later admin action on the config table (setting a new active Final
 * Approver, or even editing/removing an old row) can never retroactively
 * change who an already-created request's Clearance Form shows. Same
 * "snapshot now, never re-resolve later" convention already used for
 * `immediate_head_id` and the General Signatory pivot.
 *
 * Nullable: a request created before this feature existed has no value
 * here — `ClearanceFormController::buildData()` falls back to the
 * original hardcoded "VICTORIANO T. YAP / President" text for those, so no
 * backfill is needed and no historical Clearance Form changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->foreignId('final_approver_employee_id')->nullable()->after('immediate_head_id')
                ->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('final_approver_employee_id');
        });
    }
};
