<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TEMPLATE_NAME = 'User Account Reactivation Notification';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('email_templates')->where('template_name', self::TEMPLATE_NAME)->exists()) {
            return;
        }

        $subject = 'Your OffBoarding Application Account Has Been Reactivated';

        $body = '<p>Hello {{employee_name}},</p>'
            .'<p>Your OffBoarding Application account has been successfully reactivated by the administrator.</p>'
            .'<table style="width:100%;border-collapse:collapse;font-size:14px;margin:12px 0;"><tbody>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Username</td><td style="padding:4px 8px;font-weight:bold;">{{username}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Temporary Password</td><td style="padding:4px 8px;font-weight:bold;">{{temporary_password}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Account Status</td><td style="padding:4px 8px;font-weight:bold;">{{account_status}}</td></tr>'
            .'<tr><td style="padding:4px 8px;color:#6b7280;">Reactivated On</td><td style="padding:4px 8px;">{{reactivated_at}}</td></tr>'
            .'</tbody></table>'
            .'<p>Your account is now active and you may log in using the credentials above. You will be asked to set a new password on your first login.</p>'
            .'<p>{{offboarding_link}}</p>'
            .'<p>If you did not request this account reactivation, please contact the administrator immediately.</p>'
            .'<p>Thank you.</p>'
            .'<p>Regards,</p>'
            .'<p>HR</p>';

        DB::table('email_templates')->insert([
            'template_name' => self::TEMPLATE_NAME,
            'subject' => $subject,
            'html_content' => $body,
            'is_active' => true,
            'is_default_announcement' => false,
            'is_default_reactivation' => true,
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
