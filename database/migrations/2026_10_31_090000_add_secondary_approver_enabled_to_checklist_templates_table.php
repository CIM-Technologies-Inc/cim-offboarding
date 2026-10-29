<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Enable Secondary Approver" on the checklist template form — the master
 * switch for the Secondary Clearance Signatory & Escalation Settings. Off
 * means the feature is disabled for the template (no secondary, no
 * escalation on new requests) while its saved settings are kept, so turning
 * it back on restores them.
 *
 * Templates that were already configured before this switch existed are
 * turned on, so nothing that works today silently stops escalating.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->boolean('secondary_approver_enabled')->default(false)->after('secondary_signatory_employee_id');
        });

        DB::table('checklist_templates')
            ->where(fn ($query) => $query->whereNotNull('secondary_signatory_employee_id')->orWhere('escalation_enabled', true))
            ->update(['secondary_approver_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->dropColumn('secondary_approver_enabled');
        });
    }
};
