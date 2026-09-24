<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fixed-name, admin-editable email template sent by
 * `App\Console\Commands\NotifyClearanceSigningDue` to a Clearance
 * Signatory/General Signatory once their own Clearance Signing Due Date
 * (`OffboardingRequestApprover.clearance_signing_due_at` /
 * `OffboardingRequestGeneralSignatory.due_at`) is reached — deliberately
 * its own template, never reused from the pre-existing "Offboarding
 * Overdue Notice" (which fires off the separate, independent `due_at`
 * field). Same seed-once convention as every other fixed-name template.
 */
return new class extends Migration
{
    private const TEMPLATE_NAME = 'Clearance Signing Due Reached';

    public function up(): void
    {
        if (DB::table('email_templates')->where('template_name', self::TEMPLATE_NAME)->exists()) {
            return;
        }

        $subject = 'Clearance Signing Due Date Reached — {{employee_name}}';

        // Deliberately "Name"/"ID Number", never the bare words
        // "Offboardee"/"Employee"/"Approver" — see
        // `2026_09_16_090000_seed_checklist_due_date_extended_email_template.php`'s
        // own comment for why those exact words get silently corrupted
        // anywhere they appear, including plain label text.
        $body = '<p style="margin:0 0 4px;font-weight:bold;">Clearance Signing Due Date Reached</p>'
            .'<p>Your clearance signing deadline for <strong>{{employee_name}} ({{employee_number}})</strong> has been reached. Please complete and approve your assigned checklist as soon as possible.</p>'
            .'<table style="width:100%;border-collapse:collapse;font-size:14px;margin:12px 0;"><tbody>'
            .'<tr><td colspan="2" style="padding:8px 8px 2px;color:#374151;font-weight:bold;">Personnel Details</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;width:160px;">Name</td><td style="padding:4px 8px;font-weight:bold;">{{employee_name}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">ID Number</td><td style="padding:4px 8px;font-weight:bold;">{{employee_number}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Position</td><td style="padding:4px 8px;font-weight:bold;">{{position}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Department</td><td style="padding:4px 8px;font-weight:bold;">{{department}}</td></tr>'
            .'<tr><td colspan="2" style="padding:12px 8px 2px;color:#374151;font-weight:bold;">Checklist Details</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;width:160px;">Checklist</td><td style="padding:4px 8px;font-weight:bold;">{{checklist_name}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Clearance Signing Due Date</td><td style="padding:4px 8px;font-weight:bold;">{{due_date}}</td></tr>'
            .'</tbody></table>'
            .'<p>Please log in to {{offboarding_link}} to review and complete your assigned checklist.</p>'
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
