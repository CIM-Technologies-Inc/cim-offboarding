<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secondary Clearance Signatory & Automatic Escalation.
 *
 * - `checklist_templates`: the configuration (who the backup signatory is,
 *   whether escalation is on, after how many calendar/working days, and
 *   whether HR/Admin is told about a first-level escalation).
 * - `offboarding_request_approvers` (one row per checklist instance): a
 *   SNAPSHOT of that configuration taken at assignment time, plus the
 *   assignment/escalation state. `employee_id` stays the authoritative
 *   CURRENT active Clearance Signatory — every existing access check and
 *   notification already reads it — while the new columns record who the
 *   original primary/secondary were and each stage's own deadline.
 * - `checklist_signatory_assignments`: append-only history of every
 *   initial assignment, automatic escalation, secondary failure and HR/Admin
 *   reassignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->foreignId('secondary_signatory_employee_id')->nullable()->constrained('employees', 'id', 'ct_secondary_signatory_fk')->nullOnDelete();
            $table->boolean('escalation_enabled')->default(false);
            $table->unsignedSmallInteger('escalation_days')->nullable();
            $table->string('escalation_day_type', 10)->default('calendar');
            $table->boolean('escalation_notify_hr')->default(true);
        });

        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            // Snapshot of the template configuration at assignment time.
            $table->foreignId('primary_signatory_employee_id')->nullable()->constrained('employees', 'id', 'ora_primary_signatory_fk')->nullOnDelete();
            $table->foreignId('secondary_signatory_employee_id')->nullable()->constrained('employees', 'id', 'ora_secondary_signatory_fk')->nullOnDelete();
            $table->boolean('escalation_enabled')->default(false);
            $table->unsignedSmallInteger('escalation_days')->nullable();
            $table->string('escalation_day_type', 10)->nullable();
            $table->boolean('escalation_notify_hr')->default(true);

            // State. NULL escalation_status = not tracked (every request
            // created before this feature, and headless checklists).
            $table->string('escalation_status', 30)->nullable()->index();
            $table->dateTime('signatory_assigned_at')->nullable();
            $table->dateTime('signatory_deadline_at')->nullable()->index();
            $table->dateTime('primary_deadline_at')->nullable();
            $table->dateTime('secondary_assigned_at')->nullable();
            $table->dateTime('secondary_deadline_at')->nullable();
            $table->dateTime('escalated_at')->nullable();
            $table->dateTime('secondary_failed_at')->nullable();
            $table->dateTime('hr_alert_sent_at')->nullable();
            $table->dateTime('hr_reassigned_at')->nullable();
            $table->foreignId('hr_reassigned_by_user_id')->nullable()->constrained('users', 'id', 'ora_hr_reassigned_by_fk')->nullOnDelete();
            $table->text('hr_reassignment_reason')->nullable();
            $table->string('escalation_note')->nullable();
        });

        Schema::create('checklist_signatory_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offboarding_request_approver_id')->constrained('offboarding_request_approvers', 'id', 'csa_approver_fk')->cascadeOnDelete();
            $table->foreignId('offboarding_request_id')->constrained('offboarding_requests', 'id', 'csa_request_fk')->cascadeOnDelete();
            $table->foreignId('checklist_template_id')->nullable()->constrained('checklist_templates', 'id', 'csa_template_fk')->nullOnDelete();
            $table->string('event', 40);
            $table->string('stage', 30)->nullable();
            $table->string('trigger', 20);
            $table->foreignId('previous_employee_id')->nullable()->constrained('employees', 'id', 'csa_previous_employee_fk')->nullOnDelete();
            $table->foreignId('new_employee_id')->nullable()->constrained('employees', 'id', 'csa_new_employee_fk')->nullOnDelete();
            // Names copied in so history stays readable if an employee is
            // later removed from Employee Master.
            $table->string('previous_employee_name')->nullable();
            $table->string('new_employee_name')->nullable();
            $table->dateTime('assigned_at')->nullable();
            $table->dateTime('deadline_at')->nullable();
            $table->dateTime('occurred_at');
            $table->string('previous_status', 30)->nullable();
            $table->string('new_status', 30)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users', 'id', 'csa_performed_by_fk')->nullOnDelete();
            // Per-recipient notification outcome: [{channel, recipient, status, error?, attempts}]
            $table->json('notifications')->nullable();
            $table->string('notification_status', 20)->nullable()->index();
            $table->unsignedTinyInteger('notification_attempts')->default(0);
            $table->timestamps();

            $table->index(['offboarding_request_approver_id', 'event'], 'csa_approver_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_signatory_assignments');

        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            $table->dropForeign('ora_primary_signatory_fk');
            $table->dropForeign('ora_secondary_signatory_fk');
            $table->dropForeign('ora_hr_reassigned_by_fk');
            $table->dropColumn([
                'primary_signatory_employee_id', 'secondary_signatory_employee_id', 'escalation_enabled', 'escalation_days',
                'escalation_day_type', 'escalation_notify_hr', 'escalation_status', 'signatory_assigned_at', 'signatory_deadline_at',
                'primary_deadline_at', 'secondary_assigned_at', 'secondary_deadline_at', 'escalated_at', 'secondary_failed_at',
                'hr_alert_sent_at', 'hr_reassigned_at', 'hr_reassigned_by_user_id', 'hr_reassignment_reason', 'escalation_note',
            ]);
        });

        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->dropForeign('ct_secondary_signatory_fk');
            $table->dropColumn(['secondary_signatory_employee_id', 'escalation_enabled', 'escalation_days', 'escalation_day_type', 'escalation_notify_hr']);
        });
    }
};
