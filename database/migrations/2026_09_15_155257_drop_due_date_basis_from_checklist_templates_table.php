<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the "After Resignation Date" / "After Last Working Day" choice
 * added by `2026_09_05_090000_add_due_date_basis_to_checklist_templates_table.php`
 * — every checklist's `due_in_days` now counts from the offboardee's own
 * Last Working Day unconditionally (see `ChecklistApprovalNotifier`), so
 * the per-template basis selection no longer has anything to select
 * between.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->dropColumn('due_date_basis');
        });
    }

    public function down(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->string('due_date_basis')->default('resignation_date')->after('due_in_days');
        });
    }
};
