<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "{{offboarding_link}}" now renders as a dark green "CIM Offboarding"
 * button (see `EmailTemplate::placeholderValues()`), not inline link text.
 * Four seeded templates wrote it as text: three behind a label ("CIM
 * Offboarding: [CIM Offboarding]" would read twice), one mid-sentence
 * ("Please log in to [button] to review…"). This gives the button its own
 * paragraph in each.
 *
 * Exact-match only, same convention as this project's other template-update
 * migrations: a template whose wording an admin has since customised no
 * longer contains OLD and is left untouched.
 */
return new class extends Migration
{
    private const CHANGES = [
        'Offboarding Announcement' => [
            '<p>CIM Offboarding link: {{offboarding_link}}</p>',
            '<p>{{offboarding_link}}</p>',
        ],
        'Offboarding Reminder' => [
            '<p>CIM Offboarding: {{offboarding_link}}</p>',
            '<p>{{offboarding_link}}</p>',
        ],
        'Final Pay Checklist Approval' => [
            '<p>CIM Offboarding: {{offboarding_link}}</p>',
            '<p>{{offboarding_link}}</p>',
        ],
        'Clearance Signing Due Reached' => [
            '<p>Please log in to {{offboarding_link}} to review and complete your assigned checklist.</p>',
            '<p>Please log in to CIM Offboarding to review and complete your assigned checklist.</p><p>{{offboarding_link}}</p>',
        ],
    ];

    public function up(): void
    {
        foreach (self::CHANGES as $templateName => [$old, $new]) {
            $this->replace($templateName, $old, $new);
        }
    }

    public function down(): void
    {
        foreach (self::CHANGES as $templateName => [$old, $new]) {
            $this->replace($templateName, $new, $old);
        }
    }

    private function replace(string $templateName, string $from, string $to): void
    {
        $template = DB::table('email_templates')->where('template_name', $templateName)->first();

        if (! $template || ! str_contains($template->html_content, $from)) {
            return;
        }

        DB::table('email_templates')->where('id', $template->id)->update([
            'html_content' => str_replace($from, $to, $template->html_content),
            'updated_at' => now(),
        ]);
    }
};
