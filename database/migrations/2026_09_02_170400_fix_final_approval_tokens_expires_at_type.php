<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `$table->timestamp('expires_at')` in the previous migration silently
 * compiled to a MySQL `TIMESTAMP ... DEFAULT CURRENT_TIMESTAMP ON UPDATE
 * CURRENT_TIMESTAMP` column — a legacy MySQL quirk (active whenever the
 * server's `explicit_defaults_for_timestamp` is OFF, as this one's is)
 * that auto-applies to the FIRST `TIMESTAMP`-typed column in a table with
 * no explicit default of its own. The practical effect: updating ANY
 * column on an existing row silently resets `expires_at` back to "now",
 * discarding its real 30-day expiry.
 *
 * Nothing in this app's normal flow currently updates an already-created
 * token row (they're create-once, read-only afterward), so this never
 * manifested in production — caught here while testing the approval flow
 * directly. Switched to `DATETIME`, which MySQL never auto-updates this
 * way (only genuine `TIMESTAMP` columns get this legacy treatment), via
 * raw SQL since `doctrine/dbal` (required for Schema::table()->change())
 * isn't installed in this project.
 *
 * The same `$table->timestamp('expires_at')` pattern is used by
 * `checklist_approval_tokens`, `general_signatory_approval_tokens`, and
 * `password_reset_requests` too — none of them currently update an
 * existing row either, so they're dormant, not actively broken, but carry
 * the identical latent risk. Left untouched here since fixing them isn't
 * part of the Final Approval feature this migration exists for.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE final_approval_tokens MODIFY expires_at DATETIME NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE final_approval_tokens MODIFY expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }
};
