<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TEMPLATE_NAME = 'Employee Offboarding Follow-Up Notification';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('email_templates')->where('template_name', self::TEMPLATE_NAME)->exists()) {
            return;
        }

        $subject = 'Follow-Up: Pending Checklist — {{checklist_name}} ({{employee_number}})';

        $body = '<p>Good day Mrs/Mr. {{approver_name}},</p>'
            .'<p>The employee below is following up regarding a pending checklist for their offboarding process.</p>'
            .'<table style="width:100%;border-collapse:collapse;font-size:14px;margin:12px 0;"><tbody>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Employee</td><td style="padding:4px 8px;font-weight:bold;">{{employee_name}} ({{employee_number}})</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Position</td><td style="padding:4px 8px;">{{position}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Department</td><td style="padding:4px 8px;">{{department}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Offboarding Request Date</td><td style="padding:4px 8px;">{{request_date}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Last Working Day</td><td style="padding:4px 8px;">{{separation_date}}</td></tr>'
            .'</tbody></table>'
            .'<table style="width:100%;border-collapse:collapse;font-size:14px;margin:12px 0;"><tbody>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Checklist</td><td style="padding:4px 8px;font-weight:bold;">{{checklist_name}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Department/Area</td><td style="padding:4px 8px;">{{department}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Assigned Department Head</td><td style="padding:4px 8px;">{{department_head_name}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Assigned Signatory/Approver(s)</td><td style="padding:4px 8px;">{{assigned_signatories}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Status</td><td style="padding:4px 8px;font-weight:bold;">{{checklist_status}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Due Date</td><td style="padding:4px 8px;">{{due_date}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Progress</td><td style="padding:4px 8px;">{{checklist_progress}}</td></tr>'
            .'</tbody></table>'
            .'<p><strong>Remaining/Pending Items:</strong></p>'
            .'{{remaining_items}}'
            .'<p style="margin-top:16px;color:#6b7280;font-size:13px;">Follow-up sent: {{follow_up_sent_at}}</p>'
            .'<p>Please log in to CIM Offboarding to review this checklist.</p>'
            .'<p>{{offboarding_link}}</p>'
            .'<p>Thank you.</p>';

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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('email_templates')->where('template_name', self::TEMPLATE_NAME)->delete();
    }
};
