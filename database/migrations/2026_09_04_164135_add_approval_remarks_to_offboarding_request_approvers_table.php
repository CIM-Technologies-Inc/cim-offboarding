<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whole-checklist "why was this late" remark the Clearance Signatory/
 * Immediate Head may optionally leave when approving a checklist that has
 * reached or passed its due date — see `ApprovalController::approve()`/
 * `approveGroup()` and `checklist-modal.blade.php`'s due-date confirmation
 * dialog. Distinct from `checklist_item_progresses.remark` (a per-ITEM
 * remark left while checking/holding one specific item).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            $table->text('approval_remarks')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            $table->dropColumn('approval_remarks');
        });
    }
};
