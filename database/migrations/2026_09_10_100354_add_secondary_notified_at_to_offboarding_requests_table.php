<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Sync workflow's Primary -> Secondary stage transition needs its own
 * one-shot idempotency lock, the exact same pattern `final_pay_notified_at`
 * already uses for the Secondary -> Final Pay transition — see
 * `ChecklistCompletionService::checkPrimaryChecklistsCompletion()`.
 * Meaningless for an Async request (never set), and for any request created
 * before this feature existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->timestamp('secondary_notified_at')->nullable()->after('final_pay_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->dropColumn('secondary_notified_at');
        });
    }
};
