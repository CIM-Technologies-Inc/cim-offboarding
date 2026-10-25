<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fixed-name, admin-editable email template mirroring the content of the
 * previously-hardcoded `ChecklistItemApproverAssignedMail` (`emails.checklist-
 * item-approver-assigned` Blade view) — sent to a Task Assignee whenever
 * they're assigned one or more checklist items (see `ChecklistApprovalNotifier::notifyItemApprovers()`
 * and the 3 analogous sends in `ChecklistDelegationController`). Same
 * seed-once convention as every other fixed-name template (e.g.
 * `2026_09_03_120000_seed_checklist_ready_for_department_head_approval_email_template.php`):
 * admins can freely edit its subject/body afterward from the Email and
 * Notification page, this migration only guarantees a sensible starting
 * point exists.
 *
 * The old Blade view conditionally hid its "Your Initial Login Credentials"
 * box when no new account was created for this send (`@if ($credentials)`)
 * — a static, placeholder-substituted template has no equivalent
 * conditional, so `{{username}}`/`{{temporary_password}}` are shown
 * unconditionally here instead, exactly like "Offboarding Details
 * Notification – Employee" already does: blank when not applicable, never
 * hidden.
 */
return new class extends Migration
{
    private const TEMPLATE_NAME = 'Offboarding Checklist Assigned to You';

    public function up(): void
    {
        if (DB::table('email_templates')->where('template_name', self::TEMPLATE_NAME)->exists()) {
            return;
        }

        $subject = 'Offboarding Checklist Assigned to You';

        $body = '<p>Hello {{approver_name}},</p>'
            .'<p>You have been assigned checklist item(s) that require your review.</p>'
            .'<table style="width:100%;border-collapse:collapse;font-size:14px;margin:12px 0;"><tbody>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;width:140px;">Employee</td><td style="padding:4px 8px;font-weight:bold;">{{employee_name}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Employee No.</td><td style="padding:4px 8px;font-weight:bold;">{{employee_number}}</td></tr>'
            .'</tbody></table>'
            .'{{checklist_summary}}'
            .'<p>Please log in to the CIM OffBoarding system to review and complete your assigned checklist.</p>'
            .'<p>{{offboarding_link}}</p>'
            .'<table style="width:100%;border-collapse:collapse;font-size:14px;margin:12px 0;background-color:#fef9c3;border:1px solid #fde68a;border-radius:8px;"><tbody>'
            .'<tr><td style="padding:12px 16px;"><p style="margin:0 0 8px;font-weight:bold;">Your Initial Login Credentials</p>'
            .'<p style="margin:0;">Username: <strong>{{username}}</strong></p>'
            .'<p style="margin:0;">Temporary Password: <strong>{{temporary_password}}</strong></p>'
            .'<p style="margin:8px 0 0;color:#92400e;">If these apply to you, please change your password after logging in.</p>'
            .'</td></tr></tbody></table>'
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
