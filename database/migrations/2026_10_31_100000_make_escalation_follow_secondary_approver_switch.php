<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Enable Automatic Escalation" was removed from the checklist template
 * form: escalation is now automatic whenever "Enable Secondary Approver" is
 * on. `escalation_enabled` stays as an internal mirror of that switch (the
 * escalation engine and every request's snapshot still read it), so align
 * existing templates with the new rule. Data only — no schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('checklist_templates')->update(['escalation_enabled' => DB::raw('secondary_approver_enabled')]);
    }

    public function down(): void
    {
        // Nothing to restore: the previous per-template checkbox values
        // aren't recoverable, and the mirror is harmless either way.
    }
};
