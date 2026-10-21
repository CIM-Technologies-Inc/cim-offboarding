<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedup ledger for `email_template_schedules` — same role
 * `email_template_scheduled_sends` played for the old single-schedule
 * mechanism, and the same "a row only exists once something actually
 * happened" pattern `checklist_reminder_logs`/`checklist_item_scheduled_sends`
 * already use elsewhere in this app. There's no stored 'pending' status:
 * "pending" is simply "no row yet for this (schedule, request) pair",
 * computed for display rather than persisted — see
 * `SendEmailTemplateSchedules`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_template_schedule_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_template_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offboarding_request_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['sent', 'failed', 'cancelled']);
            $table->date('scheduled_for')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamp('system_sent_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['email_template_schedule_id', 'offboarding_request_id'], 'email_template_schedule_sends_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_template_schedule_sends');
    }
};
