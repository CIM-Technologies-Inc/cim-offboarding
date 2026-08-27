<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `email_template_scheduled_sends` was originally unique on
 * (email_template_id, offboarding_request_id) because the only scheduling
 * mode that existed fired at most once per (template, request) pair ever.
 * The new recurring schedule type needs to record one row per occurrence —
 * e.g. every 5 days for the life of the request — so that strict uniqueness
 * must be relaxed. Duplicate-prevention for a single scheduled day is now
 * handled in `SendScheduledEmailTemplates` by checking whether a row
 * already exists for today's date before sending (see
 * `processRecurringTemplate()`); the one-time mode is unaffected — it still
 * relies on `whereDoesntHave('scheduledEmailSends', ...)` (an existence
 * check, not the DB constraint) to never fire more than once.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The replacement index must exist BEFORE the unique one is dropped —
        // MySQL uses that unique index's leftmost column to back the
        // `email_template_id` foreign key, and refuses to drop it while it's
        // the only index satisfying that constraint.
        Schema::table('email_template_scheduled_sends', function (Blueprint $table) {
            $table->index(['email_template_id', 'offboarding_request_id'], 'email_template_sched_sends_lookup');
        });

        Schema::table('email_template_scheduled_sends', function (Blueprint $table) {
            $table->dropUnique('email_template_sched_sends_unique');
        });
    }

    public function down(): void
    {
        Schema::table('email_template_scheduled_sends', function (Blueprint $table) {
            $table->unique(['email_template_id', 'offboarding_request_id'], 'email_template_sched_sends_unique');
        });

        Schema::table('email_template_scheduled_sends', function (Blueprint $table) {
            $table->dropIndex('email_template_sched_sends_lookup');
        });
    }
};
