<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fixed-name, admin-editable email template mirroring the content of the
 * automatic `ChecklistReadyForApprovalMail` (`emails.checklist-ready-for-
 * approval` Blade view, sent once by `ChecklistApprovalNotifier::notifyDepartmentHeadReady()`
 * the moment a checklist requiring full completion — e.g. a Department
 * Head/Immediate Head checklist — is fully checked). That automatic send
 * stays exactly as it is; this template exists so an admin/HR user can
 * additionally FOLLOW UP with the Clearance Signatory afterward, using the
 * existing Offboarding Status/Timeline "Notify Approver" picker (every
 * active template is selectable there — see
 * `ApprovalController::remind()`), with a genuinely working one-click
 * Approve link (`{{approve_button}}`) and the same per-item Checked
 * By/Date/Remarks summary (`{{checklist_summary}}`, built by
 * `OffboardingRequestApprover::checkedItemsSummaryHtml()`) the original
 * email shows. Same seed-once convention as every other fixed-name
 * template: admins can freely edit its subject/body afterward from the
 * Email Templates page, this migration only guarantees a sensible
 * starting point exists.
 */
return new class extends Migration
{
    private const TEMPLATE_NAME = 'Checklist Ready for Department Head Approval';

    public function up(): void
    {
        if (DB::table('email_templates')->where('template_name', self::TEMPLATE_NAME)->exists()) {
            return;
        }

        $subject = 'Checklist Ready for Department Head Approval — {{employee_name}} ({{employee_number}})';

        $body = '<p>Hello {{approver_name}},</p>'
            .'<p>All required checklist items for the following employee offboarding request have been checked and are now ready for your final review and approval.</p>'
            .'<table style="width:100%;border-collapse:collapse;font-size:14px;margin:12px 0;"><tbody>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;width:140px;">Employee</td><td style="padding:4px 8px;font-weight:bold;">{{employee_name}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Employee No.</td><td style="padding:4px 8px;font-weight:bold;">{{employee_number}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Checklist</td><td style="padding:4px 8px;font-weight:bold;">{{checklist_name}}</td></tr>'
            .'</tbody></table>'
            .'<p style="margin:0 0 4px;font-weight:bold;">Current Status:</p>'
            .'<p style="margin:0 0 16px;color:#145a3a;font-weight:bold;">Ready for Department Head Approval</p>'
            .'{{checklist_summary}}'
            .'<p>Please review the checklist in the system, or approve it directly from this email.</p>'
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
