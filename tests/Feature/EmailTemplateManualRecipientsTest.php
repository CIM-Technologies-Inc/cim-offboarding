<?php

use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\EmployeeGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Email and Notification → Recipients: manually entered email addresses
 * (company or personal, not necessarily any employee's) alongside the
 * selected employees and Employee Master groups.
 *
 *   DB_CONNECTION=mysql DB_DATABASE=cim_db_test vendor/bin/pest tests/Feature/EmailTemplateManualRecipientsTest.php
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $employee = Employee::factory()->create(['name' => 'Mail Admin']);
    $this->admin = User::factory()->create(['username' => $employee->employee_code_digits]);
    $this->admin->forceFill(['must_change_password' => false])->save();
    $this->admin->assignRole(User::ROLE_ADMIN);
});

it('saves manually entered company and personal addresses, normalized, alongside employee recipients', function () {
    $employee = Employee::factory()->create(['email' => 'ana.cruz@company.com']);

    $this->actingAs($this->admin)->post(route('email-templates.store'), [
        'template_name' => 'Exit Interview Invitation',
        'subject' => 'Exit interview',
        'html_content' => '<p>Hi</p>',
        'recipient_employee_ids' => [$employee->id],
        'recipient_emails' => [' Payroll@Company.com ', 'juan.delacruz@gmail.com'],
    ])->assertSessionHasNoErrors();

    $template = EmailTemplate::where('template_name', 'Exit Interview Invitation')->sole();

    expect($template->recipient_emails)->toBe(['payroll@company.com', 'juan.delacruz@gmail.com'])
        ->and($template->recipientEmployees->pluck('id')->all())->toBe([$employee->id]);

    // Reopening the template shows them again (the editor's initial state).
    $html = $this->actingAs($this->admin)->get(route('email-templates.edit', $template))->assertOk()->getContent();
    expect($html)->toContain('payroll@company.com')->toContain('juan.delacruz@gmail.com')
        ->toContain('name="recipient_emails[]"', false);
});

it('rejects invalid and duplicate (case-insensitive) addresses with clear messages', function () {
    $post = fn (array $emails) => $this->actingAs($this->admin)->post(route('email-templates.store'), [
        'template_name' => 'T '.uniqid(), 'subject' => 'S', 'recipient_emails' => $emails,
    ]);

    $post(['not-an-email'])->assertSessionHasErrors(['recipient_emails.0' => '"not-an-email" is not a valid email address.']);
    $post(['ok@company.com', 'OK@Company.com'])->assertSessionHasErrors('recipient_emails.1');
    $post(['missing-at.company.com'])->assertSessionHasErrors('recipient_emails.0');

    expect(EmailTemplate::where('template_name', 'like', 'T %')->count())->toBe(0);
});

it('updates the manual list — adding, removing and clearing addresses', function () {
    $template = EmailTemplate::forceCreate(['template_name' => 'Reminder', 'subject' => 'S', 'html_content' => '<p>x</p>', 'is_active' => true, 'recipient_emails' => ['a@company.com', 'b@company.com']]);
    $put = fn (array $extra) => $this->actingAs($this->admin)->put(route('email-templates.update', $template), ['template_name' => 'Reminder', 'subject' => 'S', 'html_content' => '<p>x</p>'] + $extra);

    $put(['recipient_emails' => ['b@company.com', 'c.personal@yahoo.com']])->assertSessionHasNoErrors();
    expect($template->fresh()->recipient_emails)->toBe(['b@company.com', 'c.personal@yahoo.com']);

    // Removing every tag submits none -> cleared.
    $put([])->assertSessionHasNoErrors();
    expect($template->fresh()->recipient_emails)->toBeNull();
});

it('includes manual addresses in the CC list used by every send, deduped against employee and primary recipients', function () {
    $member = Employee::factory()->create(['email' => 'member@company.com']);
    $group = EmployeeGroup::create(['name' => 'Finance', 'is_active' => true]);
    $member->update(['employee_group_id' => $group->id]);
    $picked = Employee::factory()->create(['name' => 'Pia Picked', 'email' => 'pia@company.com']);

    $template = EmailTemplate::forceCreate(['template_name' => 'CC test', 'subject' => 'S', 'html_content' => '<p>x</p>', 'is_active' => true,
        'recipient_emails' => ['external@gmail.com', 'pia@company.com', 'primary@company.com']]);
    $template->recipientEmployees()->sync([$picked->id]);
    $template->recipientGroups()->sync([$group->id]);
    $template->load('recipientEmployees', 'recipientGroups.employees');

    expect($template->ccRecipientEmails('Primary@Company.com'))
        ->toEqualCanonicalizing(['pia@company.com', 'member@company.com', 'external@gmail.com'])
        ->and($template->ccRecipientNames())->toContain('Pia Picked')->toContain('external@gmail.com')
        // pia@company.com is shown once, by name (not again as a raw address).
        ->not->toContain('pia@company.com');
});

it('handles a template whose only recipients are manually entered addresses (no employees selected)', function () {
    $template = EmailTemplate::forceCreate(['template_name' => 'Manual only', 'subject' => 'S', 'html_content' => '<p>x</p>', 'is_active' => true, 'recipient_emails' => ['only.manual@gmail.com']]);
    $template->load('recipientEmployees', 'recipientGroups.employees');

    expect($template->ccRecipientNames())->toBe(['only.manual@gmail.com'])
        ->and($template->ccRecipientEmails())->toBe(['only.manual@gmail.com']);
});

it('opens the Review Request step when a scheduled template has only manually entered recipients', function () {
    $template = EmailTemplate::forceCreate(['template_name' => 'Daily Reminder', 'subject' => 'S', 'html_content' => '<p>x</p>', 'is_active' => true, 'recipient_emails' => ['only.manual@gmail.com']]);
    \App\Models\EmailTemplateSchedule::forceCreate(['email_template_id' => $template->id, 'event' => 'last_working_day', 'timing' => 'before', 'days' => 2, 'channel' => 'email', 'is_active' => true]);
    $offboardee = Employee::factory()->create();

    $response = $this->actingAs($this->admin)->postJson(route('offboarding-requests.preview'), [
        'employee_id' => $offboardee->id,
        'notice_date' => now()->toDateString(),
        'last_working_day' => now()->addDays(14)->toDateString(),
        'separation_type_id' => \App\Models\SeparationType::value('id'),
    ])->assertOk();

    expect(collect($response->json('scheduled_notifications'))->firstWhere('template_name', 'Daily Reminder'))->not->toBeNull();
});
