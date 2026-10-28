<?php

use App\Models\EmailTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 *   DB_CONNECTION=mysql DB_DATABASE=cim_db_test vendor/bin/pest tests/Feature/EmailTemplateLinkButtonTest.php
 */

uses(RefreshDatabase::class);

function linkButtonTemplate(string $html): EmailTemplate
{
    return EmailTemplate::forceCreate(['template_name' => 'Link Button '.uniqid(), 'subject' => 'S', 'html_content' => $html, 'is_active' => true]);
}

it('renders {{offboarding_link}} as a dark green CIM Offboarding button', function () {
    [, $body] = linkButtonTemplate('<p>{{offboarding_link}}</p>')->render(approverName: 'A', offboardeeName: 'B');

    expect($body)->toContain('href="'.route('login').'"')
        ->and($body)->toContain('background-color:#145a3a')
        ->and($body)->toContain('color:#ffffff')
        ->and($body)->toContain('display:inline-block')
        ->and($body)->toMatch('/<p><a [^>]+>CIM Offboarding<\/a><\/p>/');
});

it('uses the per-recipient link when one is given, safely escaped', function () {
    [, $body] = linkButtonTemplate('<p>{{offboarding_link}}</p>')->render(
        approverName: 'A', offboardeeName: 'B', offboardingLinkUrl: 'https://example.test/auto-login/7/tok?a=1&b="x"',
    );

    expect($body)->toContain('href="https://example.test/auto-login/7/tok?a=1&amp;b=&quot;x&quot;"');
});

it('leaves templates without the link untouched (no button added)', function () {
    [, $body] = linkButtonTemplate('<p>Hello {{approver_name}}</p>')->render(approverName: 'A', offboardeeName: 'B');

    expect($body)->toBe('<p>Hello A</p>');
});

it('migrates the four templates that had the link inline so the button gets its own line, skipping customised wording', function () {
    $old = [
        'Offboarding Announcement' => '<p>Intro.</p><p>CIM Offboarding link: {{offboarding_link}}</p><p>Thanks.</p>',
        'Offboarding Reminder' => '<p>CIM Offboarding: {{offboarding_link}}</p>',
        'Final Pay Checklist Approval' => '<p>Customised by an admin: {{offboarding_link}}</p>',
    ];
    foreach ($old as $name => $html) {
        EmailTemplate::where('template_name', $name)->delete();
        linkButtonTemplate($html)->forceFill(['template_name' => $name])->save();
    }
    // Seeded by its own migration, already carrying the old mid-sentence wording.
    $clearance = EmailTemplate::where('template_name', 'Clearance Signing Due Reached')->value('html_content');
    if (! str_contains($clearance, 'log in to {{offboarding_link}}')) {
        EmailTemplate::where('template_name', 'Clearance Signing Due Reached')->update(['html_content' => '<p>Please log in to {{offboarding_link}} to review and complete your assigned checklist.</p>']);
    }

    $migration = require database_path('migrations/2026_10_26_110000_place_offboarding_link_button_on_its_own_line_in_email_templates.php');
    $migration->up();

    $html = fn ($name) => EmailTemplate::where('template_name', $name)->value('html_content');
    expect($html('Offboarding Announcement'))->toBe('<p>Intro.</p><p>{{offboarding_link}}</p><p>Thanks.</p>')
        ->and($html('Offboarding Reminder'))->toBe('<p>{{offboarding_link}}</p>')
        ->and($html('Final Pay Checklist Approval'))->toBe('<p>Customised by an admin: {{offboarding_link}}</p>')
        ->and($html('Clearance Signing Due Reached'))->toContain('<p>Please log in to CIM Offboarding to review and complete your assigned checklist.</p><p>{{offboarding_link}}</p>');

    $migration->down();
    expect($html('Offboarding Announcement'))->toBe($old['Offboarding Announcement'])
        ->and($html('Clearance Signing Due Reached'))->toContain('log in to {{offboarding_link}} to review');
});
