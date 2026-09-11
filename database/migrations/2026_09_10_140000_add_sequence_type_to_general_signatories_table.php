<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The same Core/Secondary/Final-Pay classification `checklist_templates`
 * already has (`sequence_type` + a final-pay flag), now on General
 * Signatories so they can stage through the same Sync/Async workflow — see
 * `ChecklistCompletionService::checkPrimaryGeneralSignatoriesCompletion()`.
 * `is_final_pay_signatory` is deliberately its own column (not a third
 * `sequence_type` value) for the same reason `checklist_templates.
 * is_final_pay_checklist` is kept separate from its own `sequence_type`.
 *
 * Backfills every EXISTING row to `sequence_type = 'primary'`,
 * `is_final_pay_signatory = false` — "Core" — so Sync mode behaves
 * sensibly immediately, with no required admin action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('general_signatories', function (Blueprint $table) {
            $table->enum('sequence_type', ['primary', 'secondary'])->nullable()->after('is_active');
            $table->boolean('is_final_pay_signatory')->default(false)->after('sequence_type');
        });

        DB::table('general_signatories')->update(['sequence_type' => 'primary']);
    }

    public function down(): void
    {
        Schema::table('general_signatories', function (Blueprint $table) {
            $table->dropColumn(['sequence_type', 'is_final_pay_signatory']);
        });
    }
};
