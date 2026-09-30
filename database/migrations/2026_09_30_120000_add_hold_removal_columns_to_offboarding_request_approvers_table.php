<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Declining a checklist now places it on genuine hold (`status = 'on_hold'`
 * — see `ApprovalController::decline()`) rather than counting as a
 * completed action. These columns record the SEPARATE event of the SAME
 * signatory later removing that hold (mandatory reason, same convention as
 * `declined_at`/`decline_reason` already on this table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            $table->dateTime('on_hold_removed_at')->nullable()->after('decline_reason');
            $table->foreignId('on_hold_removed_by')->nullable()->after('on_hold_removed_at')->constrained('users')->nullOnDelete();
            $table->text('on_hold_removal_reason')->nullable()->after('on_hold_removed_by');
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('on_hold_removed_by');
            $table->dropColumn(['on_hold_removed_at', 'on_hold_removal_reason']);
        });
    }
};
