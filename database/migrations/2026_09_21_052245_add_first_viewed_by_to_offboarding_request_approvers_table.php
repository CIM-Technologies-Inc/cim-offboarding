<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records WHO first viewed a checklist assignment, alongside the existing
 * `first_viewed_at` timestamp — previously only the timestamp was kept, with
 * no way to show "First Viewed By" on the Offboarding Status page. Set once,
 * the moment `first_viewed_at` itself is first set, and never recomputed
 * afterward — same frozen-snapshot convention as this table's own
 * `checked_by_user_id`-style columns, so a later viewer opening the same
 * checklist never overwrites who genuinely viewed it first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            // Explicit short constraint name — the auto-generated one
            // (`offboarding_request_approvers_first_viewed_by_employee_id_foreign`)
            // exceeds MySQL's 64-character identifier limit.
            $table->foreignId('first_viewed_by_employee_id')->nullable()->after('first_viewed_at')
                ->constrained('employees', indexName: 'orq_approvers_first_viewed_by_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            $table->dropForeign('orq_approvers_first_viewed_by_fk');
            $table->dropColumn('first_viewed_by_employee_id');
        });
    }
};
