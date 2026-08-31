<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A checklist configured to "Use Task Assignee as Clearance Signatory" has
// no Department Head / Clearance Signatory at all — the row still needs to
// exist (for item tracking, visibility, and the Clearance Form), just with
// no owning employee. See ChecklistApprovalNotifier::attachAndNotify().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
        });

        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->change();
        });

        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
        });

        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable(false)->change();
        });

        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });
    }
};
