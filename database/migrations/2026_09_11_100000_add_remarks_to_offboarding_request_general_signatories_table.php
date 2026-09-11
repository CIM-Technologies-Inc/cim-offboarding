<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The General Signatory's own optional comment left on the Approvals
     * page at Submit time — the General Signatory equivalent of
     * `offboarding_request_final_approvals.remarks` and
     * `offboarding_request_approvers.approval_remarks`, same `text`
     * nullable shape, added after `approved_by` for the same reason: it's
     * only ever meaningful once the row is actually approved.
     */
    public function up(): void
    {
        Schema::table('offboarding_request_general_signatories', function (Blueprint $table) {
            $table->text('remarks')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_request_general_signatories', function (Blueprint $table) {
            $table->dropColumn('remarks');
        });
    }
};
