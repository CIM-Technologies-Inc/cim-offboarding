<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "Active Checklist Selection" on a single `EmailTemplateSchedule` —
 * which active checklist templates this one schedule's reminder should
 * reach the Clearance Signatory of, for each eligible offboarding request.
 * An empty pivot set for a schedule means "no selection made" — resolved by
 * `SendEmailTemplateSchedules::resolveRecipients()` as "every checklist
 * actually attached to the request", not as "send to nobody". Scoped to the
 * SCHEDULE, not the template, so two schedules on the same template can
 * target different checklists independently. Same plain many-to-many pivot
 * shape as `email_template_recipient_employees`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Explicit `foreign()` calls with short custom names, rather than
        // `foreignId()->constrained()` — the auto-generated constraint name
        // for this table (`{table}_{column}_foreign`) exceeds MySQL's
        // 64-character identifier limit given how long this table's own
        // name already is.
        Schema::create('email_template_schedule_checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('email_template_schedule_id');
            $table->unsignedBigInteger('checklist_template_id');
            $table->timestamps();

            $table->foreign('email_template_schedule_id', 'etsct_schedule_id_foreign')
                ->references('id')->on('email_template_schedules')->cascadeOnDelete();
            $table->foreign('checklist_template_id', 'etsct_checklist_template_id_foreign')
                ->references('id')->on('checklist_templates')->cascadeOnDelete();

            $table->unique(['email_template_schedule_id', 'checklist_template_id'], 'email_template_schedule_checklists_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_template_schedule_checklist_templates');
    }
};
