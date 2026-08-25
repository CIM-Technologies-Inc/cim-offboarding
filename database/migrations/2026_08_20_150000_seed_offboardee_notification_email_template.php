<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TEMPLATE_NAME = 'Offboarding Details Notification – Employee';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('email_templates')->where('template_name', self::TEMPLATE_NAME)->exists()) {
            return;
        }

        $subject = 'Your Offboarding Process Has Started — {{employee_number}}';

        $body = '<p>Good day Mrs/Mr. {{employee_name}},</p>'
            .'<p>Your offboarding request has been submitted and your clearance process has now started. Below are your offboarding details, assigned checklists, and your login information for the CIM Offboarding system.</p>'
            .'<table style="width:100%;border-collapse:collapse;font-size:14px;margin:12px 0;"><tbody>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Name</td><td style="padding:4px 8px;font-weight:bold;">{{employee_name}} ({{employee_number}})</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Position</td><td style="padding:4px 8px;">{{position}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Department</td><td style="padding:4px 8px;">{{department}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Date Hired</td><td style="padding:4px 8px;">{{date_hired}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Offboarding Request Date</td><td style="padding:4px 8px;">{{request_date}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Last Working Day</td><td style="padding:4px 8px;">{{separation_date}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Offboarding Status</td><td style="padding:4px 8px;font-weight:bold;">{{offboarding_status}}</td></tr>'
            .'</tbody></table>'
            .'<p><strong>Your Assigned Checklists:</strong></p>'
            .'{{checklist_summary}}'
            .'<p><strong>Your Login Information:</strong></p>'
            .'<table style="width:100%;border-collapse:collapse;font-size:14px;margin:12px 0;"><tbody>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Username</td><td style="padding:4px 8px;font-weight:bold;">{{username}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Temporary Password</td><td style="padding:4px 8px;font-weight:bold;">{{temporary_password}}</td></tr>'
            .'</tbody></table>'
            .'<p>Please log in to CIM Offboarding to monitor your offboarding progress. You will be asked to set a new password on your first login.</p>'
            .'<p>{{offboarding_link}}</p>'
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('email_templates')->where('template_name', self::TEMPLATE_NAME)->delete();
    }
};
