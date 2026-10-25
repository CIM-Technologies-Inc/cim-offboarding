<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widens the dedup key on `email_template_schedule_sends` from
 * `(schedule, request)` to `(schedule, request, scheduled_for)` — see
 * `SendEmailTemplateSchedules`'s own docblock for why. The old key made the
 * first row ever written for a (schedule, request) pair — success, failure,
 * or cancellation — permanently block every future evaluation of that pair,
 * which made a failed send unretryable and made a Last Working Day
 * extension unable to ever re-fire a schedule that had already delivered
 * once under the old date. Tracking delivery per computed occurrence
 * (`scheduled_for`) instead lets a `'failed'` row be retried and lets a
 * freshly-computed future date (after an extension) be evaluated on its own
 * merits, while a `'sent'` row for that EXACT date still blocks a resend of
 * that same occurrence. `'cancelled'` rows have no `scheduled_for` at all
 * (set before any date is computed) and are guarded separately, in code, by
 * checking for an existing `'cancelled'` row rather than by this index.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The new index is added BEFORE the old one is dropped — the old
        // index's leftmost column (`email_template_schedule_id`) backs a
        // foreign key, and MySQL refuses to drop the only index covering an
        // FK column. Since the new index shares that same leftmost column,
        // creating it first gives MySQL a replacement to fall back on.
        Schema::table('email_template_schedule_sends', function (Blueprint $table) {
            $table->unique(
                ['email_template_schedule_id', 'offboarding_request_id', 'scheduled_for'],
                'email_template_schedule_sends_occurrence_unique'
            );
        });

        Schema::table('email_template_schedule_sends', function (Blueprint $table) {
            $table->dropUnique('email_template_schedule_sends_unique');
        });
    }

    public function down(): void
    {
        Schema::table('email_template_schedule_sends', function (Blueprint $table) {
            $table->unique(
                ['email_template_schedule_id', 'offboarding_request_id'],
                'email_template_schedule_sends_unique'
            );
        });

        Schema::table('email_template_schedule_sends', function (Blueprint $table) {
            $table->dropUnique('email_template_schedule_sends_occurrence_unique');
        });
    }
};
