<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A General Signatory's own "Clearance Signing Deadline" — the same concept
 * `checklist_templates.clearance_signing_deadline_days` adds for a Core/
 * Primary checklist, mirrored here since a General Signatory had no
 * due-date concept at all before this. Named `due_in_days` (not
 * `clearance_signing_deadline_days`) since a General Signatory has only
 * ONE deadline field, unlike a checklist template which also has the
 * pre-existing, independent `due_in_days` — no naming collision risk here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('general_signatories', function (Blueprint $table) {
            $table->unsignedInteger('due_in_days')->nullable()->after('clearance_signatory_id');
        });
    }

    public function down(): void
    {
        Schema::table('general_signatories', function (Blueprint $table) {
            $table->dropColumn('due_in_days');
        });
    }
};
