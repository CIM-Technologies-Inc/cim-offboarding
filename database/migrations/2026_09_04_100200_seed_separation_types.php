<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds Separation Type Management with the same 6 categories that used to
 * be hardcoded in `OffboardingRequest::REASONS` (now removed), so existing
 * behavior is preserved on deploy — admins can freely rename/retire/add to
 * these afterward from the new admin page; this migration only guarantees a
 * sensible starting point exists, same convention as every other
 * seed-once-then-editable migration in this app (e.g. the fixed-name email
 * templates).
 *
 * Default Notice Period values are a reasonable starting point per common
 * Labor Code practice — 30 days for a voluntary separation or an authorized-
 * cause involuntary one, 0 for a just-cause termination, death, or a
 * contract simply ending on schedule — editable per-type afterward.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $types = [
            ['title' => 'Resignation', 'description' => "Employee's voluntary termination of employment.", 'days' => 30],
            ['title' => 'Retirement', 'description' => "Employee's voluntary separation due to age or years of service.", 'days' => 30],
            ['title' => 'Involuntary (Just Cause)', 'description' => 'Serious misconduct, willful disobedience, gross neglect, fraud, or crime (Article 282, Labor Code).', 'days' => 0],
            ['title' => 'Involuntary (Authorized Cause)', 'description' => 'Redundancy, retrenchment, business closure, or disease/illness (Articles 283 and 284, Labor Code).', 'days' => 30],
            ['title' => 'Death', 'description' => "Employee's passing.", 'days' => 0],
            ['title' => 'End of Contract', 'description' => 'Fixed-term or project-based contract ends, or probationary employment does not meet required standards.', 'days' => 0],
        ];

        foreach ($types as $type) {
            if (DB::table('separation_types')->where('title', $type['title'])->exists()) {
                continue;
            }

            DB::table('separation_types')->insert([
                'title' => $type['title'],
                'description' => $type['description'],
                'default_notice_period_days' => $type['days'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Back-fill the traceability link and description snapshot (never
        // re-read live afterward — see `2026_09_04_100100_...`'s docblock)
        // on any existing request whose freshly-relabeled `reason` matches
        // one of these titles exactly.
        foreach (DB::table('separation_types')->get(['id', 'title', 'description']) as $separationType) {
            DB::table('offboarding_requests')
                ->where('reason', $separationType->title)
                ->whereNull('separation_type_id')
                ->update([
                    'separation_type_id' => $separationType->id,
                    'separation_type_description' => $separationType->description,
                ]);
        }
    }

    public function down(): void
    {
        DB::table('separation_types')->whereIn('title', [
            'Resignation', 'Retirement', 'Involuntary (Just Cause)',
            'Involuntary (Authorized Cause)', 'Death', 'End of Contract',
        ])->delete();
    }
};
