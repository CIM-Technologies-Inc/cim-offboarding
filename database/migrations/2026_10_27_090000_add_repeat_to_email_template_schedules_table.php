<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Repeat" on an Email Notification Schedule: after its first occurrence
     * (the usual "N days before/after {event}" date) the schedule fires again
     * every `repeat_interval_days` until its stopping condition — see
     * `EmailTemplateScheduleDispatcher::dueOccurrence()`. Additive with safe
     * defaults: every existing schedule keeps `repeat_enabled = false` and so
     * behaves exactly as before (one occurrence only).
     */
    public function up(): void
    {
        Schema::table('email_template_schedules', function (Blueprint $table) {
            $table->boolean('repeat_enabled')->default(false)->after('channel');
            $table->unsignedInteger('repeat_interval_days')->nullable()->after('repeat_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('email_template_schedules', function (Blueprint $table) {
            $table->dropColumn(['repeat_enabled', 'repeat_interval_days']);
        });
    }
};
