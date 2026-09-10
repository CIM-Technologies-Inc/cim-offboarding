<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The department this group represents — now an explicit, admin-picked
 * field on the Create/Edit Group form, replacing the old free-choice
 * "search and pick any employee" Group Head field. `group_head_employee_id`
 * is auto-resolved FROM this department (see
 * `EmployeeGroupController::resolveGroupHeadForDepartment()`), never picked
 * directly, so it's no longer the primary input.
 *
 * Nullable rather than required at the DB level — a group saved before this
 * feature shipped has no `department` yet; it's backfilled the next time
 * that group is edited (the form always requires picking one going
 * forward), never retroactively guessed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_groups', function (Blueprint $table) {
            $table->string('department')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('employee_groups', function (Blueprint $table) {
            $table->dropColumn('department');
        });
    }
};
