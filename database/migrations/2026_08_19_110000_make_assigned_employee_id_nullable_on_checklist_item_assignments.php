<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Plain ->change() would need doctrine/dbal, which isn't installed
        // in this project — raw SQL avoids that dependency entirely.
        Schema::table('checklist_item_assignments', function (Blueprint $table) {
            $table->dropForeign(['assigned_employee_id']);
        });

        DB::statement('ALTER TABLE checklist_item_assignments MODIFY assigned_employee_id BIGINT UNSIGNED NULL');

        Schema::table('checklist_item_assignments', function (Blueprint $table) {
            // Nullable so a row can represent an explicit "no signatory"
            // snapshot — locking in, at request-creation time, that this
            // item had no signatory (and no applicable group) and must
            // stay Department-Head-only for this request forever, even if
            // the template later gains one. See
            // ChecklistApprovalNotifier::snapshotItemSignatories().
            $table->foreign('assigned_employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('checklist_item_assignments', function (Blueprint $table) {
            $table->dropForeign(['assigned_employee_id']);
        });

        DB::statement('ALTER TABLE checklist_item_assignments MODIFY assigned_employee_id BIGINT UNSIGNED NOT NULL');

        Schema::table('checklist_item_assignments', function (Blueprint $table) {
            $table->foreign('assigned_employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });
    }
};
