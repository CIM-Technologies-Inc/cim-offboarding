<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot idempotency locks for the General Signatory track's own
 * Core -> Secondary -> Final Pay staging — the exact same pattern
 * `secondary_notified_at`/`final_pay_notified_at` already use for the
 * checklist track (see `ChecklistCompletionService::checkPrimaryGeneralSignatoriesCompletion()`/
 * `attachFinalPayGeneralSignatoriesIfReady()`), but kept as their own,
 * independent columns since General Signatory is a deliberately separate
 * parallel track that can resolve before, after, or interleaved with the
 * checklist track. Meaningless for an Async request's Secondary stage
 * (Core+Secondary attach together, so it's never set) and for any request
 * created before this feature existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->timestamp('general_signatory_secondary_notified_at')->nullable()->after('secondary_notified_at');
            $table->timestamp('general_signatory_final_pay_notified_at')->nullable()->after('general_signatory_secondary_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->dropColumn(['general_signatory_secondary_notified_at', 'general_signatory_final_pay_notified_at']);
        });
    }
};
