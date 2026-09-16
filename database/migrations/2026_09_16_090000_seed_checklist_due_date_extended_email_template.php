<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fixed-name, admin-editable email template sent by
 * `ApprovalController::extendDue()` to a checklist's Clearance Signatory
 * once `OffboardingRequestApprover::extendDue()` has successfully saved
 * the new due date — never before, so a failed/rejected extend attempt
 * never sends a stale or misleading notice. Same seed-once convention as
 * every other fixed-name template (e.g. `2026_09_03_120000_seed_checklist_ready_for_department_head_approval_email_template.php`):
 * admins can freely edit its subject/body afterward from the Email
 * Templates page, this migration only guarantees a sensible starting
 * point exists.
 */
return new class extends Migration
{
    private const TEMPLATE_NAME = 'Checklist Due Date Extended';

    public function up(): void
    {
        if (DB::table('email_templates')->where('template_name', self::TEMPLATE_NAME)->exists()) {
            return;
        }

        $subject = 'Checklist Due Date Extended — {{checklist_name}} ({{employee_name}})';

        // Deliberately NOT "Offboardee"/"Employee No." as plain label text —
        // `EmailTemplate::fillPlaceholders()`'s legacy bare-word fallback
        // (for the drag-and-drop editor's "approver"/"offboardee"/"employee"
        // name-dragging feature) replaces those exact words ANYWHERE they
        // appear, including inside plain label text, not just inside a
        // "{{...}}" token — using them here would silently corrupt these
        // row labels into someone's name.
        $body = '<p>Hello {{clearance_signatory_name}},</p>'
            .'<p>The due date for the following checklist has been extended. Please complete the checklist before the new extended due date.</p>'
            .'<table style="width:100%;border-collapse:collapse;font-size:14px;margin:12px 0;"><tbody>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;width:160px;">Name</td><td style="padding:4px 8px;font-weight:bold;">{{employee_name}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">ID Number</td><td style="padding:4px 8px;font-weight:bold;">{{employee_number}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Checklist</td><td style="padding:4px 8px;font-weight:bold;">{{checklist_name}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Original Due Date</td><td style="padding:4px 8px;font-weight:bold;">{{original_due_date}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Extension Period</td><td style="padding:4px 8px;font-weight:bold;">{{extension_days}} day(s)</td></tr>'
            .'</tbody></table>'
            .'<p style="margin:0 0 4px;font-weight:bold;">New Extended Due Date:</p>'
            .'<p style="margin:0 0 16px;color:#145a3a;font-weight:bold;font-size:18px;">{{extended_due_date}}</p>'
            .'<p>Kindly ensure this checklist is completed before the new extended due date above.</p>'
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
