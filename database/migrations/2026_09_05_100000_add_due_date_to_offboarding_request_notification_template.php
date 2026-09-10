<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds a "Due Date" row to the "Offboarding Request Notification" email's
 * default content — the notice every Clearance Signatory/Immediate Head
 * gets the moment a new offboarding request assigns them a checklist (see
 * `ChecklistApprovalNotifier::notifyDepartmentHeadsOfNewRequest()`, which
 * now also computes and passes a `{{due_date}}` value). Only touches the
 * row if its `html_content` still exactly matches the ORIGINAL seeded
 * default — an admin who already customized this template keeps their own
 * wording untouched; they can add `{{due_date}}` themselves from the Email
 * Templates page if they want it.
 */
return new class extends Migration
{
    private const TEMPLATE_NAME = 'Offboarding Request Notification';

    private const ORIGINAL_CONTENT = '<p>Good day Mrs/Mr. {{approver_name}},</p><p>A new offboarding request has been submitted and requires your review. Please see the checklist(s) below assigned to you as Clearance Signatory.</p><table style="width:100%;border-collapse:collapse;font-size:14px;margin:12px 0;"><tbody><tr><td style="padding:4px 8px;color:#6b7280;">Name</td><td style="padding:4px 8px;font-weight:bold;">{{employee_name}} ({{employee_number}})</td></tr><tr><td style="padding:4px 8px;color:#6b7280;">Department</td><td style="padding:4px 8px;">{{department}}</td></tr><tr><td style="padding:4px 8px;color:#6b7280;">Position</td><td style="padding:4px 8px;">{{position}}</td></tr><tr><td style="padding:4px 8px;color:#6b7280;">Date Hired</td><td style="padding:4px 8px;">{{date_hired}}</td></tr><tr><td style="padding:4px 8px;color:#6b7280;">Separation Date</td><td style="padding:4px 8px;">{{separation_date}}</td></tr><tr><td style="padding:4px 8px;color:#6b7280;">Reason</td><td style="padding:4px 8px;">{{reason}}</td></tr><tr><td style="padding:4px 8px;color:#6b7280;">Checklist(s) Assigned to You</td><td style="padding:4px 8px;font-weight:bold;">{{checklist_name}}</td></tr></tbody></table><p>Please log in to CIM Offboarding to review this request.</p><p>{{offboarding_link}}</p><p>Thank you.</p><p>Regards,</p><p>HR</p>';

    private const UPDATED_CONTENT = '<p>Good day Mrs/Mr. {{approver_name}},</p><p>A new offboarding request has been submitted and requires your review. Please see the checklist(s) below assigned to you as Clearance Signatory.</p><table style="width:100%;border-collapse:collapse;font-size:14px;margin:12px 0;"><tbody><tr><td style="padding:4px 8px;color:#6b7280;">Name</td><td style="padding:4px 8px;font-weight:bold;">{{employee_name}} ({{employee_number}})</td></tr><tr><td style="padding:4px 8px;color:#6b7280;">Department</td><td style="padding:4px 8px;">{{department}}</td></tr><tr><td style="padding:4px 8px;color:#6b7280;">Position</td><td style="padding:4px 8px;">{{position}}</td></tr><tr><td style="padding:4px 8px;color:#6b7280;">Date Hired</td><td style="padding:4px 8px;">{{date_hired}}</td></tr><tr><td style="padding:4px 8px;color:#6b7280;">Separation Date</td><td style="padding:4px 8px;">{{separation_date}}</td></tr><tr><td style="padding:4px 8px;color:#6b7280;">Reason</td><td style="padding:4px 8px;">{{reason}}</td></tr><tr><td style="padding:4px 8px;color:#6b7280;">Checklist(s) Assigned to You</td><td style="padding:4px 8px;font-weight:bold;">{{checklist_name}}</td></tr><tr><td style="padding:4px 8px;color:#6b7280;">Due Date</td><td style="padding:4px 8px;font-weight:bold;">{{due_date}}</td></tr></tbody></table><p>Please log in to CIM Offboarding to review this request.</p><p>{{offboarding_link}}</p><p>Thank you.</p><p>Regards,</p><p>HR</p>';

    public function up(): void
    {
        DB::table('email_templates')
            ->where('template_name', self::TEMPLATE_NAME)
            ->where('html_content', self::ORIGINAL_CONTENT)
            ->update(['html_content' => self::UPDATED_CONTENT]);
    }

    public function down(): void
    {
        DB::table('email_templates')
            ->where('template_name', self::TEMPLATE_NAME)
            ->where('html_content', self::UPDATED_CONTENT)
            ->update(['html_content' => self::ORIGINAL_CONTENT]);
    }
};
