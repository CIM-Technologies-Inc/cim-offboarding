<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The General Signatory equivalent of `offboarding_request_approver_id`
 * (see `2026_08_11_010000_add_offboarding_request_approver_id_to_offboarding_activities_table.php`)
 * — without this, a General Signatory's own activity rows (notification
 * resent, first viewed) have no way to say WHICH General Signatory
 * assignment they belong to on a request that has more than one, so
 * `OffboardingRequest::approverActivityTimeline()` could never correctly
 * attribute/display them under the right one's card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_activities', function (Blueprint $table) {
            // Default constraint name would exceed MySQL's 64-char
            // identifier limit — explicitly shortened, same convention
            // already used for `checklist_approval_tokens_approver_fk` /
            // `general_signatory_approval_tokens_assignment_fk` elsewhere.
            $table->foreignId('offboarding_request_general_signatory_id')->nullable()->after('offboarding_request_approver_id');
            $table->foreign('offboarding_request_general_signatory_id', 'offboarding_activities_gs_fk')
                ->references('id')->on('offboarding_request_general_signatories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_activities', function (Blueprint $table) {
            $table->dropForeign('offboarding_activities_gs_fk');
            $table->dropColumn('offboarding_request_general_signatory_id');
        });
    }
};
