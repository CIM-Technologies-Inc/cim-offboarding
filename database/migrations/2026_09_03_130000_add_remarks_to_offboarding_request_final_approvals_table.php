<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the Final Signatory optionally leave a remark/comment while
 * approving via the emailed link — previously there was nowhere at all to
 * record one, even though every other approval flow in this app (checklist
 * items, General Signatory decline reasons) supports a comment of some
 * kind. Nullable: most approvals will have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_request_final_approvals', function (Blueprint $table) {
            $table->text('remarks')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_request_final_approvals', function (Blueprint $table) {
            $table->dropColumn('remarks');
        });
    }
};
