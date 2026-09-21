<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * For a "Use Task Assignee as Clearance Signatory" checklist, checking an
 * item off is no longer automatically final when the person who checked it
 * is a regular employee (not a Department/Group Head themselves) — their
 * own Department/Group Head must additionally approve THAT item. These
 * columns are set once, at the moment an item is first checked (see
 * `ChecklistItemProgress::syncForAssignment()`), and never recomputed
 * afterward — same "frozen snapshot" convention as this table's own
 * existing `checked_by_user_id`/`checked_at` columns, so a later org-chart
 * change never silently moves who must approve an already-checked item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_item_progress', function (Blueprint $table) {
            $table->boolean('head_approval_required')->default(false)->after('is_checked');
            $table->foreignId('head_approver_employee_id')->nullable()->after('head_approval_required')
                ->constrained('employees')->nullOnDelete();
            $table->dateTime('head_approved_at')->nullable()->after('head_approver_employee_id');
            $table->foreignId('head_approved_by_user_id')->nullable()->after('head_approved_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('checklist_item_progress', function (Blueprint $table) {
            $table->dropConstrainedForeignId('head_approver_employee_id');
            $table->dropConstrainedForeignId('head_approved_by_user_id');
            $table->dropColumn(['head_approval_required', 'head_approved_at']);
        });
    }
};
