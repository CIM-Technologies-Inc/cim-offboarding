<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the old fixed `reason` ENUM (backed by the hardcoded
 * `OffboardingRequest::REASONS` PHP array) with the admin-managed
 * `separation_types` table. `reason` itself is kept — column and name both
 * — but changes from an ENUM of internal keys ('resignation', 'layoff', …)
 * to a plain string holding the Separation Type's actual TITLE, frozen at
 * submission time (`OffboardingRequestController::store()`). This is the
 * SAME "freeze a human-readable snapshot, never re-read live config later"
 * convention every other per-request snapshot in this app already follows
 * (checklist templates, General Signatories, the Final Approver).
 *
 * `separation_type_id` is a nullable, non-authoritative reference back to
 * `separation_types` (nulled if that row is later deleted) — useful for
 * reporting/traceability only; the Clearance Form, Offboardee page, and
 * Calendar must always read `reason`/`separation_type_description` instead,
 * never re-resolve this FK, so a later edit or delete in Separation Type
 * Management can never alter an already-created request's own record.
 */
return new class extends Migration
{
    /**
     * Old fixed-enum keys never stored a human label — existing rows (this
     * app currently has none outside 'resignation', checked before writing
     * this migration) get upgraded to a real label so they display
     * correctly now that `reason` is shown as-is, with no lookup table.
     */
    private const LEGACY_LABELS = [
        'resignation' => 'Resignation',
        'termination' => 'Termination',
        'retirement' => 'Retirement',
        'layoff' => 'Layoff',
        'other' => 'Other',
    ];

    public function up(): void
    {
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->foreignId('separation_type_id')->nullable()->after('reason')
                ->constrained('separation_types')->nullOnDelete();
            $table->text('separation_type_description')->nullable()->after('separation_type_id');
        });

        DB::statement('ALTER TABLE offboarding_requests MODIFY COLUMN reason VARCHAR(255) NOT NULL');

        foreach (self::LEGACY_LABELS as $key => $label) {
            DB::table('offboarding_requests')->where('reason', $key)->update(['reason' => $label]);
        }
    }

    public function down(): void
    {
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('separation_type_id');
            $table->dropColumn('separation_type_description');
        });
    }
};
