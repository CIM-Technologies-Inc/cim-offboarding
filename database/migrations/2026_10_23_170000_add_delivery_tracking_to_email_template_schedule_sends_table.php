<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-recipient delivery tracking on each schedule occurrence — see
     * `EmailTemplateScheduleDispatcher::sendForRequest()`. `recipient_log`
     * records who each attempt resolved to and what happened to each
     * address (sent/failed/skipped, plus CC), so a retry after a partial
     * failure only re-sends to the addresses that didn't get it, and the
     * ledger itself shows who an occurrence actually went to. `attempts`
     * counts how many times the occurrence has been processed. Both are
     * additive with safe defaults; existing rows are left untouched.
     */
    public function up(): void
    {
        Schema::table('email_template_schedule_sends', function (Blueprint $table) {
            $table->json('recipient_log')->nullable()->after('failure_reason');
            $table->unsignedInteger('attempts')->default(0)->after('recipient_log');
        });
    }

    public function down(): void
    {
        Schema::table('email_template_schedule_sends', function (Blueprint $table) {
            $table->dropColumn(['recipient_log', 'attempts']);
        });
    }
};
