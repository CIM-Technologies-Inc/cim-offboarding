<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The permanent audit trail for every "Extend Due" action taken on a
 * checklist (`OffboardingRequestApprover`) from the Offboarding Status
 * page — one row per extension, never updated or overwritten, so a
 * checklist extended multiple times keeps its full history. See
 * `OffboardingRequestApprover::extendDue()`, the only place these rows are
 * ever created.
 *
 * `checklist_template_id`/`checklist_title` are both captured here even
 * though they're reachable via `offboarding_request_approver_id` — the
 * template itself (its title, or its own `due_in_days`) can change or be
 * deleted after the fact, and this row must keep describing exactly what
 * was true at the moment of THIS extension, not whatever the template
 * looks like now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_due_date_extensions', function (Blueprint $table) {
            $table->id();

            // Every foreign key here is given an explicit short constraint
            // name — this table's own long name means MySQL's default
            // auto-generated constraint names (table + column + "_foreign")
            // would exceed its 64-char identifier limit, exactly the same
            // reasoning `2026_09_02_170000_create_final_approval_tables.php`
            // already documents for the same problem.
            $table->foreignId('offboarding_request_approver_id');
            $table->foreign('offboarding_request_approver_id', 'checklist_due_date_ext_approver_fk')
                ->references('id')->on('offboarding_request_approvers')->cascadeOnDelete();

            $table->foreignId('offboarding_request_id');
            $table->foreign('offboarding_request_id', 'checklist_due_date_ext_request_fk')
                ->references('id')->on('offboarding_requests')->cascadeOnDelete();

            $table->foreignId('checklist_template_id')->nullable();
            $table->foreign('checklist_template_id', 'checklist_due_date_ext_template_fk')
                ->references('id')->on('checklist_templates')->nullOnDelete();

            $table->string('checklist_title')->nullable();
            // `dateTime`, not `timestamp` — MySQL strict mode rejects a
            // second non-nullable `timestamp` column with no explicit
            // default in the same table (only the first such column may
            // implicitly default), and both this and `new_due_date` below
            // are always explicitly supplied, never needing that behavior.
            $table->dateTime('previous_due_date');
            // The checklist TEMPLATE's own configured `due_in_days` at the
            // moment of this extension (the "Before" field shown in the
            // Extend Due modal) — purely informational, never itself added
            // to `previous_due_date`; only `additional_extension_days`
            // (the admin-entered "After" value) drives the actual
            // calculation. Null when the template had none configured.
            $table->unsignedInteger('configured_extension_days')->nullable();
            $table->unsignedInteger('additional_extension_days');
            $table->dateTime('new_due_date');
            $table->foreignId('extended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['offboarding_request_approver_id', 'created_at'], 'checklist_due_date_extensions_approver_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_due_date_extensions');
    }
};
