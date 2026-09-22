<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A General Signatory can now decline their assigned checklist(s) as a
 * completed action (mandatory reason, still counts as "done" for clearance
 * purposes — see `ChecklistCompletionService`/`ClearanceFormController`).
 * Mirrors the `declined_at`/`decline_reason` columns
 * `offboarding_request_approvers` already has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_request_general_signatories', function (Blueprint $table) {
            $table->dateTime('declined_at')->nullable()->after('approved_by');
            $table->text('decline_reason')->nullable()->after('declined_at');
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_request_general_signatories', function (Blueprint $table) {
            $table->dropColumn(['declined_at', 'decline_reason']);
        });
    }
};
