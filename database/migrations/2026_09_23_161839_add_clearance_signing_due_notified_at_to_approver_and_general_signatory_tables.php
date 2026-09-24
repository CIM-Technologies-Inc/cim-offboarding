<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guards `app:notify-clearance-signing-due` (the scheduled command that
 * emails/notifies a Clearance Signatory or General Signatory once their
 * `clearance_signing_due_at`/`due_at` is reached) the exact same way
 * `overdue_notified_at` already guards `app:notify-overdue-checklists` —
 * set once the notification fires, so a later run of the same command
 * never re-sends it for the same due-date event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            $table->timestamp('clearance_signing_due_notified_at')->nullable()->after('clearance_signing_due_at');
        });

        Schema::table('offboarding_request_general_signatories', function (Blueprint $table) {
            $table->timestamp('clearance_signing_due_notified_at')->nullable()->after('due_at');
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            $table->dropColumn('clearance_signing_due_notified_at');
        });

        Schema::table('offboarding_request_general_signatories', function (Blueprint $table) {
            $table->dropColumn('clearance_signing_due_notified_at');
        });
    }
};
