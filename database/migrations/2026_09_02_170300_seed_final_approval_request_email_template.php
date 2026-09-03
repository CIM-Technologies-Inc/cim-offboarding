<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fixed-name email template for the Final Approval Request sent to the
 * active Final Signatory once an offboarding request is completed — see
 * `FinalApprovalController::EMAIL_TEMPLATE_NAME`. Same seed-once convention
 * as the other fixed-name templates (e.g. "General Signatory Offboarding
 * Notification"): admins can freely edit its subject/body afterward from
 * the Email Templates page, this migration only guarantees a sensible
 * starting point exists.
 */
return new class extends Migration
{
    private const TEMPLATE_NAME = 'Final Approval Request';

    public function up(): void
    {
        if (DB::table('email_templates')->where('template_name', self::TEMPLATE_NAME)->exists()) {
            return;
        }

        $subject = 'Final Approval Required — {{employee_name}} ({{employee_number}})';

        $body = '<p>Dear {{approver_name}},</p>'
            .'<p>The offboarding process for the employee below has been fully completed and is now ready for your Final Approval.</p>'
            .'<table style="width:100%;border-collapse:collapse;font-size:14px;margin:12px 0;"><tbody>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Name</td><td style="padding:4px 8px;font-weight:bold;">{{employee_name}} ({{employee_number}})</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Department</td><td style="padding:4px 8px;">{{department}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Position</td><td style="padding:4px 8px;">{{position}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Last Working Day</td><td style="padding:4px 8px;">{{separation_date}}</td></tr>'
            .'</tbody></table>'
            .'<p>The completed Clearance Form is attached to this email (PDF and image) for your review.</p>'
            .'<p>Click Approve below to give your Final Approval. You will need an e-signature uploaded to your profile before this link will let you approve.</p>'
            .'{{approve_button}}'
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
