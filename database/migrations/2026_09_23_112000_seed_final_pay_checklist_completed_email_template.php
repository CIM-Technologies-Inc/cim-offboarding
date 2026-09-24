<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fixed-name, admin-editable email template sent by
 * `ChecklistCompletionService::sendFinalPayCompletionNotification()` to
 * admins and the offboarding request's creator once the Final Pay Checklist
 * has been fully completed (every assigned Clearance Signatory/Immediate
 * Head/Group Head has approved their items) and the request itself
 * transitions to `completed` — deliberately its own template, never reused
 * from Approve/Assignment/Overdue/Cancellation/Decline, since this is
 * specifically "the offboarding process for this employee has finished."
 * Same seed-once convention as every other fixed-name template.
 */
return new class extends Migration
{
    private const TEMPLATE_NAME = 'Final Pay Checklist Completed';

    public function up(): void
    {
        if (DB::table('email_templates')->where('template_name', self::TEMPLATE_NAME)->exists()) {
            return;
        }

        $subject = 'Final Pay Checklist Completed — {{employee_name}}';

        // Deliberately "Name"/"ID Number", never the bare words
        // "Offboardee"/"Employee"/"Approver" — see
        // `2026_09_16_090000_seed_checklist_due_date_extended_email_template.php`'s
        // own comment for why those exact words get silently corrupted
        // anywhere they appear, including plain label text.
        $body = '<p style="margin:0 0 4px;font-weight:bold;">Final Pay Checklist Completed</p>'
            .'<p>The <strong>Final Pay Checklist</strong> for <strong>{{employee_name}} ({{employee_number}})</strong> has been fully completed and approved by every assigned Clearance Signatory, Immediate Head, and Group Head as of <strong>{{completed_at}}</strong>.</p>'
            .'<p style="margin:0 0 16px;color:#145a3a;font-weight:bold;">The offboarding process for this employee is now complete.</p>'
            .'<table style="width:100%;border-collapse:collapse;font-size:14px;margin:12px 0;"><tbody>'
            .'<tr><td colspan="2" style="padding:8px 8px 2px;color:#374151;font-weight:bold;">Personnel Details</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;width:160px;">Name</td><td style="padding:4px 8px;font-weight:bold;">{{employee_name}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">ID Number</td><td style="padding:4px 8px;font-weight:bold;">{{employee_number}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Position</td><td style="padding:4px 8px;font-weight:bold;">{{position}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Department</td><td style="padding:4px 8px;font-weight:bold;">{{department}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Separation Type</td><td style="padding:4px 8px;font-weight:bold;">{{reason}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Last Working Day</td><td style="padding:4px 8px;font-weight:bold;">{{separation_date}}</td></tr>'
            .'<tr><td colspan="2" style="padding:12px 8px 2px;color:#374151;font-weight:bold;">Completion Details</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;width:160px;">Checklist</td><td style="padding:4px 8px;font-weight:bold;">{{checklist_name}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Completed On</td><td style="padding:4px 8px;font-weight:bold;">{{completed_at}}</td></tr>'
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
