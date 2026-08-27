<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A General Signatory is now a real Clearance Signatory/Approver — see
 * `GeneralSignatoryApprovalController` — so the "General Signatory
 * Offboarding Notification" email template needs a one-click "Approve"
 * button (the "{{approve_button}}" placeholder,
 * `ChecklistApprovalNotifier::buildApproveButtonHtml()`) added to its
 * existing, admin-authored content. Only touches the exact known snippet
 * left in place by the template's original content — a no-op if that
 * snippet isn't found (already customized differently, or the token is
 * already present) or the template row doesn't exist at all, so this never
 * clobbers content an admin has since rewritten.
 */
return new class extends Migration
{
    private const TEMPLATE_NAME = 'General Signatory Offboarding Notification';

    private const FROM = '{{general_signatory_tasks}}<p>Please log in to CIM Offboarding to review this request.</p>';

    private const TO = '{{general_signatory_tasks}}'
        .'<p>Review the offboardee details above (and your Task List, if shown) — your clearance approval is all '
        .'that is required to complete this step. Click Approve below, or log in to CIM Offboarding to review and '
        .'approve this request.</p>'
        .'{{approve_button}}';

    public function up(): void
    {
        $template = DB::table('email_templates')->where('template_name', self::TEMPLATE_NAME)->first();

        if (! $template || str_contains((string) $template->html_content, '{{approve_button}}')) {
            return;
        }

        if (! str_contains((string) $template->html_content, self::FROM)) {
            return;
        }

        DB::table('email_templates')
            ->where('id', $template->id)
            ->update([
                'html_content' => str_replace(self::FROM, self::TO, $template->html_content),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        $template = DB::table('email_templates')->where('template_name', self::TEMPLATE_NAME)->first();

        if (! $template || ! str_contains((string) $template->html_content, self::TO)) {
            return;
        }

        DB::table('email_templates')
            ->where('id', $template->id)
            ->update([
                'html_content' => str_replace(self::TO, self::FROM, $template->html_content),
                'updated_at' => now(),
            ]);
    }
};
