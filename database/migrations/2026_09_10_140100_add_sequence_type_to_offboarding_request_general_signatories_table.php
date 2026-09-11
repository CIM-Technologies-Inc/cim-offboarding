<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A per-request SNAPSHOT of the General Signatory's `sequence_type`/
 * `is_final_pay_signatory` at the moment it was attached to this request
 * (see `ChecklistApprovalNotifier::notifyGeneralSignatories()`) — so a later
 * edit to a General Signatory's classification can never change the
 * workflow/staging of a request it was already attached to. Every staging
 * decision in `ChecklistCompletionService` reads these snapshot columns,
 * never the live `general_signatories` row, once a row here exists.
 *
 * Backfills every EXISTING row to `sequence_type = 'primary'`,
 * `is_final_pay_signatory = false` — every pre-existing request already got
 * all its General Signatories in one unconditional batch, so tagging them
 * uniformly "Core" introduces no retroactive staging/blocking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_request_general_signatories', function (Blueprint $table) {
            $table->enum('sequence_type', ['primary', 'secondary'])->nullable()->after('general_signatory_id');
            $table->boolean('is_final_pay_signatory')->default(false)->after('sequence_type');
        });

        DB::table('offboarding_request_general_signatories')->update(['sequence_type' => 'primary']);
    }

    public function down(): void
    {
        Schema::table('offboarding_request_general_signatories', function (Blueprint $table) {
            $table->dropColumn(['sequence_type', 'is_final_pay_signatory']);
        });
    }
};
