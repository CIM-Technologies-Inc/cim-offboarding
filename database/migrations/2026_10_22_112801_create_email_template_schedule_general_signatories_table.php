<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "Active General Signatory Selection" on a single `EmailTemplateSchedule`
 * — which active General Signatories this one schedule's reminder should
 * reach (via their own Clearance Signatory, same resolution the rest of the
 * app already uses for General Signatory notifications) for each eligible
 * offboarding request. An empty pivot set means "no selection made" —
 * resolved as "every General Signatory actually attached to the request",
 * never as "send to nobody". Same shape as the checklist-templates sibling
 * pivot in this same migration pair.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Explicit `foreign()` calls with short custom names — same reason
        // as the checklist-templates sibling migration: the auto-generated
        // constraint name for this table exceeds MySQL's 64-character
        // identifier limit.
        Schema::create('email_template_schedule_general_signatories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('email_template_schedule_id');
            $table->unsignedBigInteger('general_signatory_id');
            $table->timestamps();

            $table->foreign('email_template_schedule_id', 'etsgs_schedule_id_foreign')
                ->references('id')->on('email_template_schedules')->cascadeOnDelete();
            $table->foreign('general_signatory_id', 'etsgs_general_signatory_id_foreign')
                ->references('id')->on('general_signatories')->cascadeOnDelete();

            $table->unique(['email_template_schedule_id', 'general_signatory_id'], 'email_template_schedule_general_signatories_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_template_schedule_general_signatories');
    }
};
