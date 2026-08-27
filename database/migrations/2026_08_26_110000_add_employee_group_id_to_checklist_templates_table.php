<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Clearance Signatory picker on the Offboarding Checklist create/edit
 * form used to be keyed by the selected Group Head's EMPLOYEE id
 * (`department_head_id`) alone — ambiguous whenever two different Employee
 * Master groups share the same Group Head (e.g. "Admin and Operations
 * Group" and "Human Resources Group" both headed by EMP0001), since both
 * would collapse into a single, indistinguishable option. `employee_group_id`
 * stores which specific GROUP was actually selected, resolving that
 * ambiguity, while `department_head_id` is left fully intact — still
 * populated (from the selected group's own `group_head_employee_id`) and
 * still read exactly as before by every existing consumer
 * (`ChecklistApprovalNotifier`, `OffboardingRequest::scopeVisibleTo()`, the
 * Clearance Form's indirect read via `OffboardingRequestApprover`, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->foreignId('employee_group_id')->nullable()->after('department_head_id')
                ->constrained('employee_groups')->nullOnDelete();
        });

        // Backfill every pre-existing template the same way the (now fixed)
        // ambiguous lookup used to resolve it — first matching group, by id
        // — so already-saved templates keep behaving exactly as they do
        // today. This can't retroactively recover which group an admin
        // originally meant when duplicates already existed; it only
        // preserves today's status quo for old data while every new
        // selection going forward is unambiguous.
        DB::table('checklist_templates')
            ->whereNotNull('department_head_id')
            ->whereNull('employee_group_id')
            ->orderBy('id')
            ->get(['id', 'department_head_id'])
            ->each(function ($template) {
                $groupId = DB::table('employee_groups')
                    ->where('group_head_employee_id', $template->department_head_id)
                    ->orderBy('id')
                    ->value('id');

                if ($groupId) {
                    DB::table('checklist_templates')->where('id', $template->id)->update(['employee_group_id' => $groupId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_group_id');
        });
    }
};
