<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds two new `timing` options anchored to the offboarding request's own
 * creation date instead of its Last Working Day: `on_request_creation`
 * (always due immediately — `days` is forced to 0 at save time, see
 * `EmailTemplateController::syncSchedules()`) and `after_request_creation`
 * (`days` = N, due `created_at + N days`).
 *
 * A plain `enum` column has no clean Schema-builder "modify" API in
 * Laravel — same situation `2026_09_03_140000_update_offboarding_requests_reason_enum.php`
 * already hit, so this uses the identical raw `MODIFY COLUMN` technique.
 * Safe here since every existing row's `timing` is currently 'before' or
 * 'after' (unchanged, still included in the new enum).
 */
return new class extends Migration
{
    private const OLD_VALUES = "'before','after'";

    private const NEW_VALUES = "'before','after','on_request_creation','after_request_creation'";

    public function up(): void
    {
        DB::statement('ALTER TABLE email_template_schedules MODIFY COLUMN timing ENUM('.self::NEW_VALUES.') NOT NULL');
    }

    public function down(): void
    {
        // No existing row can legitimately hold one of the two new values
        // yet when this is first rolled back, but defensively remap any
        // that do (to 'after', the closest equivalent) before shrinking the
        // column back — same safety pattern as the precedent migration.
        DB::statement("UPDATE email_template_schedules SET timing = 'after' WHERE timing NOT IN (".self::OLD_VALUES.')');
        DB::statement('ALTER TABLE email_template_schedules MODIFY COLUMN timing ENUM('.self::OLD_VALUES.') NOT NULL');
    }
};
