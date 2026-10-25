<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the old single, 4-value `timing` enum (`before`/`after`/
 * `on_request_creation`/`after_request_creation`) with a cleaner 2-field
 * model: a dedicated `event` (Creation, Last Working Day, Clearance, Final
 * Pay) crossed with a strictly-binary `timing` (before/after) — conflating
 * "which milestone" with "which direction" into one field was what forced
 * the previous 4-value enum to keep growing every time a new milestone was
 * needed. Also adds `clearance_signing_deadline_days`, a NEW, per-schedule
 * setting (default 19, independent of — not reusing — the existing
 * per-checklist-template `clearance_signing_deadline_days` that already
 * drives `NotifyClearanceSigningDue`; the two are deliberately separate
 * systems, see `EmailTemplateScheduleDispatcher`'s own docblock).
 *
 * `event` is added as a plain nullable string first so the backfill UPDATE
 * below can run against it with no NOT NULL constraint fighting it, then
 * locked down to an ENUM NOT NULL afterward — same two-step approach
 * already proven necessary for a MySQL enum column in this app (no clean
 * Schema-builder "add enum + backfill + enforce NOT NULL" single step).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_template_schedules', function (Blueprint $table) {
            $table->string('event')->nullable()->after('email_template_id');
            $table->unsignedInteger('clearance_signing_deadline_days')->nullable()->after('days');
        });

        // Backfill `event` from today's 4-value `timing`, collapsing
        // `timing` down to the new 2-value scheme in the same pass. Days
        // are left exactly as they were — `on_request_creation` was always
        // persisted with days=0 already (enforced server-side at the time),
        // so it needs no separate remap from `after_request_creation`
        // beyond the shared `event = 'creation'` assignment.
        DB::statement("UPDATE email_template_schedules SET event = 'creation', timing = 'after' WHERE timing IN ('on_request_creation', 'after_request_creation')");
        DB::statement("UPDATE email_template_schedules SET event = 'last_working_day' WHERE timing IN ('before', 'after')");

        DB::statement("ALTER TABLE email_template_schedules MODIFY COLUMN event ENUM('creation','last_working_day','clearance','final_pay') NOT NULL");
        DB::statement("ALTER TABLE email_template_schedules MODIFY COLUMN timing ENUM('before','after') NOT NULL");
    }

    public function down(): void
    {
        // Widen `timing` back to the legacy 4-value enum before remapping
        // into it, then remap every row back to its closest legacy
        // equivalent. `clearance`/`final_pay` (and the degenerate
        // `before`+`creation` combination) have no legacy equivalent at
        // all — fall back to `'after'`, the same safe default every other
        // enum-narrowing migration's own `down()` in this app already uses
        // rather than leaving an invalid value or failing outright.
        DB::statement("ALTER TABLE email_template_schedules MODIFY COLUMN timing ENUM('before','after','on_request_creation','after_request_creation') NOT NULL");

        DB::statement("UPDATE email_template_schedules SET timing = 'on_request_creation' WHERE event = 'creation' AND timing = 'after' AND days = 0");
        DB::statement("UPDATE email_template_schedules SET timing = 'after_request_creation' WHERE event = 'creation' AND timing = 'after' AND days > 0");
        DB::statement("UPDATE email_template_schedules SET timing = 'after' WHERE event IN ('clearance', 'final_pay') OR (event = 'creation' AND timing = 'before')");
        // event = 'last_working_day' rows already hold a valid legacy
        // `timing` ('before'/'after') and need no remap at all.

        Schema::table('email_template_schedules', function (Blueprint $table) {
            $table->dropColumn(['event', 'clearance_signing_deadline_days']);
        });
    }
};
