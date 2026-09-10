<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the offboarding request's `reason` options with the company's
 * actual Separation Type policy categories (previously an ad-hoc
 * resignation/termination/retirement/layoff/other list with no defined
 * meaning) — see `new-request-modal.blade.php`'s reason `<select>` for the
 * matching UI change and its `$reasonDefinitions` map for each option's
 * "Definition per Policy" text shown under the field.
 *
 * A plain `enum` column has no clean Schema-builder "modify" API in
 * Laravel, so this uses a raw `MODIFY COLUMN` — safe here since every
 * existing row's `reason` is currently 'resignation' (checked before
 * writing this migration), which the new enum still includes.
 */
return new class extends Migration
{
    private const OLD_VALUES = "'resignation','termination','retirement','layoff','other'";

    private const NEW_VALUES = "'resignation','retirement','involuntary_just_cause','involuntary_authorized_cause','death','end_of_contract'";

    public function up(): void
    {
        DB::statement('ALTER TABLE offboarding_requests MODIFY COLUMN reason ENUM(' . self::NEW_VALUES . ') NOT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE offboarding_requests SET reason = 'other' WHERE reason NOT IN (" . self::OLD_VALUES . ')');
        DB::statement('ALTER TABLE offboarding_requests MODIFY COLUMN reason ENUM(' . self::OLD_VALUES . ') NOT NULL');
    }
};
