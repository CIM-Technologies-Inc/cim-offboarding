<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fixed-name, admin-editable email template sent by
 * `OffboardingRequestController::cancel()` to every Clearance Signatory,
 * General Signatory, and Task Assignee who was ever notified about this
 * offboarding request — only once cancellation and all its dependent
 * record deletions have actually committed. Same seed-once convention as
 * every other fixed-name template (e.g. `2026_09_16_090000_seed_checklist_due_date_extended_email_template.php`):
 * admins can freely edit its subject/body afterward from the Email
 * Templates page, this migration only guarantees a sensible starting
 * point exists.
 */
return new class extends Migration
{
    private const TEMPLATE_NAME = 'Offboarding Request Cancelled';

    public function up(): void
    {
        if (DB::table('email_templates')->where('template_name', self::TEMPLATE_NAME)->exists()) {
            return;
        }

        $subject = 'Offboarding Request Cancelled — {{employee_name}} ({{employee_number}})';

        // Deliberately "Name"/"ID Number", never "Offboardee"/"Employee" as
        // plain label text — see `2026_09_16_090000_seed_checklist_due_date_extended_email_template.php`'s
        // own comment for why those exact bare words get silently
        // corrupted anywhere they appear in a template, not just inside a
        // "{{...}}" token.
        $body = '<p style="margin:0 0 4px;font-weight:bold;">Offboarding Request Cancelled</p>'
            .'<p>Please be informed that the offboarding request for <strong>{{employee_name}} ({{employee_number}})</strong> has been cancelled/retracted by <strong>{{cancelled_by}}</strong> on <strong>{{cancelled_at}}</strong>.</p>'
            .'<table style="width:100%;border-collapse:collapse;font-size:14px;margin:12px 0;"><tbody>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;width:160px;">Name</td><td style="padding:4px 8px;font-weight:bold;">{{employee_name}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">ID Number</td><td style="padding:4px 8px;font-weight:bold;">{{employee_number}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Department</td><td style="padding:4px 8px;font-weight:bold;">{{department}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Cancelled On</td><td style="padding:4px 8px;font-weight:bold;">{{cancelled_at}}</td></tr>'
            .'</tbody></table>'
            .'<p style="margin:0 0 16px;color:#145a3a;font-weight:bold;">Any checklist, clearance, approval, or task assignments associated with this offboarding request are no longer active. No further action is required for this cancelled request.</p>'
            .'<p>Thank you.</p>'
            .'<p>Regards,</p>'
            .'<p>HR</p>';

        DB::table('email_templates')->insert([
            'template_name' => self::TEMPLATE_NAME,
            'subject' => $subject,
            'html_content' => $body,
            'is_active' => true,
            'is_default_announcement' => false,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('email_templates')->where('template_name', self::TEMPLATE_NAME)->delete();
    }
};
