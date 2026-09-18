<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-checklist "Schedule Email/Notification" configuration — independent
 * of, and in addition to, `email_templates`' own request-wide schedule and
 * `checklist_items`' own per-item one. Both of those key off the
 * offboardee's Last Working Day directly; this one keys off THIS
 * checklist's own current due date (`offboarding_request_approvers.due_at`
 * — already recalculated by the Extend Due feature), so each checklist can
 * have its own independent reminder timing (e.g. "5 days before" for HR,
 * "3 days before" for IT) without affecting any other checklist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->boolean('notification_enabled')->default(false)->after('due_in_days');
            $table->foreignId('notification_email_template_id')->nullable()->after('notification_enabled')
                ->constrained('email_templates')->nullOnDelete();
            $table->unsignedInteger('notification_days_before')->nullable()->after('notification_email_template_id');
            $table->time('notification_time')->nullable()->after('notification_days_before');
            // 'email' | 'system' | 'both' — only 'email' actually sends
            // anything today (this app has no in-app notification feature
            // yet); the other two values are accepted and stored so the
            // config isn't lost once one is added.
            $table->string('notification_type')->default('email')->after('notification_time');
            $table->boolean('notification_repeat')->default(false)->after('notification_type');
            $table->unsignedInteger('notification_repeat_interval_days')->nullable()->after('notification_repeat');
            $table->unsignedInteger('notification_max_reminders')->nullable()->after('notification_repeat_interval_days');
        });
    }

    public function down(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('notification_email_template_id');
            $table->dropColumn([
                'notification_enabled',
                'notification_days_before',
                'notification_time',
                'notification_type',
                'notification_repeat',
                'notification_repeat_interval_days',
                'notification_max_reminders',
            ]);
        });
    }
};
