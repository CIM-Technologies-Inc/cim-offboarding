<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Core/Primary checklist's own "Clearance Signing Deadline" — how many
 * days after the offboardee's Last Working Day the assigned Clearance
 * Signatory has to complete/approve it. Deliberately a SEPARATE column from
 * the existing `due_in_days` ("Due Date Extension (Days)", which continues
 * to drive `OffboardingRequestApprover.due_at`/the overdue badge/"Extend
 * Due" untouched) — the two are independently configurable, see
 * `ChecklistTemplateController::store()`/`update()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->unsignedInteger('clearance_signing_deadline_days')->nullable()->after('due_in_days');
        });
    }

    public function down(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->dropColumn('clearance_signing_deadline_days');
        });
    }
};
