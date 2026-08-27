<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a second, independent scheduling mode alongside the existing
 * "N days before/after Last Working Day" one-time trigger
 * (`schedule_timing`/`schedule_days`): a recurring interval, anchored to the
 * offboarding request's own creation date, that keeps firing every
 * `schedule_interval_days` days until the request's Last Working Day is
 * reached. `schedule_type` picks which of the two modes a scheduled
 * template uses — defaulted to 'one_time' so every already-configured
 * scheduled template (which only ever knew the before/after mode) keeps
 * working unchanged after this migration runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->string('schedule_type')->default('one_time')->after('is_scheduled');
            $table->unsignedInteger('schedule_interval_days')->nullable()->after('schedule_days');
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn(['schedule_type', 'schedule_interval_days']);
        });
    }
};
