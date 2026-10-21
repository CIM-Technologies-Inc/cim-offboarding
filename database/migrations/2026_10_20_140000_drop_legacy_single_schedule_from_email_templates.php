<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the single-schedule-per-template mechanism
 * (`is_scheduled`/`schedule_type`/`schedule_timing`/`schedule_days`/`schedule_interval_days`
 * plus the `email_template_scheduled_sends` ledger) in favor of the
 * one-to-many `email_template_schedules` table (see the next two
 * migrations). Safe to drop outright rather than migrate forward: its UI
 * was never shipped (still commented out in `workspace.blade.php`) and a
 * direct check confirms zero rows anywhere have `is_scheduled = true` and
 * the ledger table is empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn(['is_scheduled', 'schedule_type', 'schedule_timing', 'schedule_days', 'schedule_interval_days']);
        });

        Schema::dropIfExists('email_template_scheduled_sends');
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->boolean('is_scheduled')->default(false)->after('is_default_announcement');
            $table->string('schedule_type')->default('one_time')->after('is_scheduled');
            $table->string('schedule_timing')->nullable()->after('schedule_type');
            $table->unsignedInteger('schedule_days')->nullable()->after('schedule_timing');
            $table->unsignedInteger('schedule_interval_days')->nullable()->after('schedule_days');
        });

        Schema::create('email_template_scheduled_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offboarding_request_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sent_at');
            $table->timestamps();
        });
    }
};
