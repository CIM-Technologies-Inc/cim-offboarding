<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A decline no longer "does NOT stop or block the offboarding process" —
 * see `ApprovalController::decline()` — it now places the checklist On
 * Hold, blocking every task/approve/decline action on it until the SAME
 * signatory removes the hold. Updates the seeded
 * 'Checklist Signatory Declined' template's copy in place to match; the
 * false green "does NOT stop" line is replaced with the real On Hold
 * behavior. All existing placeholders are unchanged.
 */
return new class extends Migration
{
    private const TEMPLATE_NAME = 'Checklist Signatory Declined';

    private const OLD_LINE = '<p style="margin:0 0 16px;color:#145a3a;font-weight:bold;">This decline does NOT stop or block the offboarding process — it is recorded as a completed signatory action, and the request continues normally.</p>';

    private const NEW_LINE = '<p style="margin:0 0 16px;color:#92400e;font-weight:bold;">This checklist is now ON HOLD and cannot proceed — all tasks, approval, and further decline actions are disabled until {{declined_by}} removes the hold.</p>';

    public function up(): void
    {
        $template = DB::table('email_templates')->where('template_name', self::TEMPLATE_NAME)->first();

        if (! $template || ! str_contains($template->html_content, self::OLD_LINE)) {
            return;
        }

        DB::table('email_templates')->where('template_name', self::TEMPLATE_NAME)->update([
            'html_content' => str_replace(self::OLD_LINE, self::NEW_LINE, $template->html_content),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $template = DB::table('email_templates')->where('template_name', self::TEMPLATE_NAME)->first();

        if (! $template || ! str_contains($template->html_content, self::NEW_LINE)) {
            return;
        }

        DB::table('email_templates')->where('template_name', self::TEMPLATE_NAME)->update([
            'html_content' => str_replace(self::NEW_LINE, self::OLD_LINE, $template->html_content),
            'updated_at' => now(),
        ]);
    }
};
