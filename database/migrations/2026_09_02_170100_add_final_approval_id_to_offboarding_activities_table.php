<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Final Approval equivalent of `offboarding_request_general_signatory_id`
 * — lets a Final Approval activity (sent/viewed/approved) say which
 * `offboarding_request_final_approvals` row it belongs to. Explicitly
 * named constraint: the default name would exceed MySQL's 64-char
 * identifier limit, same issue as the General Signatory column before it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_activities', function (Blueprint $table) {
            $table->foreignId('offboarding_request_final_approval_id')->nullable()->after('offboarding_request_general_signatory_id');
            $table->foreign('offboarding_request_final_approval_id', 'offboarding_activities_final_approval_fk')
                ->references('id')->on('offboarding_request_final_approvals')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_activities', function (Blueprint $table) {
            $table->dropForeign('offboarding_activities_final_approval_fk');
            $table->dropColumn('offboarding_request_final_approval_id');
        });
    }
};
