<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (checklist instance, recipient, reminder cycle) actually
 * processed by `app:send-checklist-reminders` — the de-dup ledger that
 * guarantees a given recipient never gets the same reminder cycle for the
 * same checklist twice, via the unique index below, not just application
 * logic. Also doubles as the notification history requirement #7 asks for.
 *
 * Deliberately NOT pre-populated with "pending" rows ahead of time: the
 * command computes each cycle's due date fresh from the checklist's own
 * CURRENT `due_at` every run, so a Last Working Day extension is picked up
 * automatically for any cycle not yet logged here — nothing to
 * cancel/invalidate separately. A row only ever appears once a cycle has
 * actually been attempted (sent, skipped because the checklist was already
 * complete, or failed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offboarding_request_approver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offboarding_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recipient_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('recipient_email');
            $table->string('notification_type')->default('email');
            $table->unsignedInteger('reminder_number')->default(1);
            $table->dateTime('scheduled_at');
            $table->dateTime('sent_at')->nullable();
            $table->string('status'); // sent | skipped | failed
            $table->timestamps();

            $table->unique(
                ['offboarding_request_approver_id', 'recipient_employee_id', 'reminder_number'],
                'checklist_reminder_logs_unique_cycle'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_reminder_logs');
    }
};
