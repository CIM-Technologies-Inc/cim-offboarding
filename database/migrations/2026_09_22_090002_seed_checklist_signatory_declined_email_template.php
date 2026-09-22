<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fixed-name, admin-editable email template sent by
 * `ApprovalController::sendDeclineNotification()`/`GeneralSignatoryApprovalController::sendDeclineNotification()`
 * to admins and the offboarding request's creator once a Clearance Signatory
 * or General Signatory declines a Core/Primary checklist — deliberately its
 * own template (never reused from Approve/Assignment/Overdue/Cancellation),
 * since a decline is neither a clearance nor a cancellation: the request
 * keeps moving, this is purely "someone needs to know this happened." Same
 * seed-once convention as every other fixed-name template.
 */
return new class extends Migration
{
    private const TEMPLATE_NAME = 'Checklist Signatory Declined';

    public function up(): void
    {
        if (DB::table('email_templates')->where('template_name', self::TEMPLATE_NAME)->exists()) {
            return;
        }

        $subject = 'Checklist Declined — {{checklist_name}} ({{employee_name}})';

        // Deliberately "Name"/"ID Number"/"Signatory", never the bare words
        // "Offboardee"/"Employee"/"Approver" — see
        // `2026_09_16_090000_seed_checklist_due_date_extended_email_template.php`'s
        // own comment for why those exact words get silently corrupted
        // anywhere they appear, including plain label text.
        $body = '<p style="margin:0 0 4px;font-weight:bold;">Checklist Declined</p>'
            .'<p><strong>{{declined_by}}</strong> ({{signatory_type}}) has declined the <strong>{{checklist_name}}</strong> checklist for <strong>{{employee_name}} ({{employee_number}})</strong> on <strong>{{declined_at}}</strong>.</p>'
            .'<p style="margin:0 0 16px;color:#145a3a;font-weight:bold;">This decline does NOT stop or block the offboarding process — it is recorded as a completed signatory action, and the request continues normally.</p>'
            .'<table style="width:100%;border-collapse:collapse;font-size:14px;margin:12px 0;"><tbody>'
            .'<tr><td colspan="2" style="padding:8px 8px 2px;color:#374151;font-weight:bold;">Personnel Details</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;width:160px;">Name</td><td style="padding:4px 8px;font-weight:bold;">{{employee_name}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">ID Number</td><td style="padding:4px 8px;font-weight:bold;">{{employee_number}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Position</td><td style="padding:4px 8px;font-weight:bold;">{{position}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Department</td><td style="padding:4px 8px;font-weight:bold;">{{department}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Separation Type</td><td style="padding:4px 8px;font-weight:bold;">{{reason}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Last Working Day</td><td style="padding:4px 8px;font-weight:bold;">{{separation_date}}</td></tr>'
            .'<tr><td colspan="2" style="padding:12px 8px 2px;color:#374151;font-weight:bold;">Checklist Details</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;width:160px;">Checklist Title</td><td style="padding:4px 8px;font-weight:bold;">{{checklist_name}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Checklist Type</td><td style="padding:4px 8px;font-weight:bold;">{{checklist_type}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Due Date</td><td style="padding:4px 8px;font-weight:bold;">{{due_date}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Current Status</td><td style="padding:4px 8px;font-weight:bold;">{{checklist_status}}</td></tr>'
            .'<tr><td colspan="2" style="padding:12px 8px 2px;color:#374151;font-weight:bold;">Declined By</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;width:160px;">Signatory Name</td><td style="padding:4px 8px;font-weight:bold;">{{declined_by}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Signatory Type</td><td style="padding:4px 8px;font-weight:bold;">{{signatory_type}}</td></tr>'
            .'<tr><td colspan="2" style="padding:12px 8px 2px;color:#374151;font-weight:bold;">Decline Details</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;width:160px;">Declined Date/Time</td><td style="padding:4px 8px;font-weight:bold;">{{declined_at}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Reason/Remarks</td><td style="padding:4px 8px;font-weight:bold;">{{decline_reason}}</td></tr>'
            .'</tbody></table>'
            .'<p>You may review the full details on the Offboarding Status page for this request (ID: {{offboarding_request_id}}).</p>'
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
