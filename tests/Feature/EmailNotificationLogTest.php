<?php

use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\OffboardingRequest;
use App\Models\User;
use App\Support\EmailLogContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\RecordingTransport;

/*
 * Email notification log (Logs → Email Notifications): every email the app
 * sends is recorded centrally from Laravel's mail pipeline — see
 * EmailLogRecorder / EmailLogContext / LoggingTransport.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    RecordingTransport::reset();
    EmailLogContext::flush();
    Mail::extend('recording', fn () => new RecordingTransport);
    config(['mail.mailers.recording' => ['transport' => 'recording'], 'mail.default' => 'recording', 'mail.redirect_to' => null]);
    Mail::forgetMailers();

    $this->travelTo(Carbon::parse('2026-10-20 10:00:00'));

    $this->admin = User::factory()->create(['name' => 'Hera HrAdmin', 'username' => 'emaillog.admin']);
    $this->admin->forceFill(['must_change_password' => false])->save();
    $this->admin->assignRole(User::ROLE_ADMIN);

    $this->offboardee = Employee::factory()->create(['name' => 'Olivia Offboardee']);
    $this->request = OffboardingRequest::factory()->create(['employee_id' => $this->offboardee->id, 'status' => 'pending']);

    $this->template = EmailTemplate::forceCreate([
        'template_name' => 'Checklist Reminder Notice',
        'subject' => 'Reminder: checklist for {{offboardee_name}}',
        'html_content' => '<p>Hello {{approver_name}}, your temporary password is SECRET-123.</p>',
        'is_active' => true,
    ]);
});

function sendTemplatedEmail(EmailTemplate $template, string $to, array $cc = []): ChecklistSignatoryAnnouncementMail
{
    [$subject, $body] = $template->render('Ann Approver', 'Olivia Offboardee');
    $mail = new ChecklistSignatoryAnnouncementMail($subject, $body);
    Mail::to($to)->cc($cc)->send($mail);

    return $mail;
}

it('records a system-triggered email from a web action as Sent only after the mail server accepted it', function () {
    $user = User::factory()->create(['name' => 'Reset Me', 'email' => 'reset.me@example.com']);

    $this->post(route('password.email'), ['email' => 'reset.me@example.com'])->assertRedirect();

    $log = EmailLog::sole();
    expect($log->status)->toBe('sent')
        ->and($log->notification_type)->toBe('system')
        ->and($log->subject)->toBe('Password Reset Requested')
        ->and($log->to_recipients)->toBe(['reset.me@example.com'])
        ->and($log->mailable)->toBe('PasswordResetMail')
        ->and($log->trigger)->toBe('Password › Email (web action)')
        ->and($log->trigger_source)->toBe('web')
        ->and($log->sent_at)->not->toBeNull()
        ->and($log->smtp_message_id)->not->toBeEmpty()
        ->and($log->attempt)->toBe(1);
});

it('names the template, offboardee, request, CC recipients and initiating user — never the email body', function () {
    $this->actingAs($this->admin);
    sendTemplatedEmail($this->template, 'ann@example.com', ['cc.one@example.com']);

    $log = EmailLog::sole();
    expect($log->status)->toBe('sent')
        ->and($log->subject)->toBe('Reminder: checklist for Olivia Offboardee')
        ->and($log->template_name)->toBe('Checklist Reminder Notice')
        ->and($log->offboardee_name)->toBe('Olivia Offboardee')
        ->and($log->offboarding_request_id)->toBe($this->request->id)
        ->and($log->cc_recipients)->toBe(['cc.one@example.com'])
        ->and($log->recipients_search)->toContain('ann@example.com')->toContain('cc.one@example.com')
        ->and($log->triggered_by_name)->toBe('Hera HrAdmin');

    // Sensitive content (credentials in the body) is never stored anywhere in the log.
    expect(json_encode($log->getAttributes()))->not->toContain('SECRET-123');
});

it('records a failed send with the error, then links a later retry to the original attempt', function () {
    RecordingTransport::$down = true;
    expect(fn () => sendTemplatedEmail($this->template, 'ann@example.com'))->toThrow(Exception::class);

    $failed = EmailLog::sole();
    expect($failed->status)->toBe('failed')
        ->and($failed->error_message)->toContain('Connection could not be established')
        ->and($failed->failed_at)->not->toBeNull()
        ->and($failed->sent_at)->toBeNull();

    // App-level re-send (a new mail object, same notification) → attempt 2, linked.
    RecordingTransport::$down = false;
    sendTemplatedEmail($this->template, 'ann@example.com');

    $retry = EmailLog::latest('id')->first();
    expect(EmailLog::count())->toBe(2)
        ->and($retry->status)->toBe('sent')
        ->and($retry->attempt)->toBe(2)
        ->and($retry->retry_of_id)->toBe($failed->id)
        ->and($retry->message_key)->toBe($failed->message_key)
        ->and($failed->fresh()->status)->toBe('failed');
});

it('links queue-style retries of the same queued email through its frozen message key', function () {
    RecordingTransport::$rejecting = ['ann@example.com'];
    [$subject, $body] = $this->template->render('Ann Approver', 'Olivia Offboardee');
    $mail = new ChecklistSignatoryAnnouncementMail($subject, $body);

    // What the queue worker does on each try: send the SAME (unserialized) mailable again.
    expect(fn () => Mail::to('ann@example.com')->sendNow($mail))->toThrow(Exception::class);
    RecordingTransport::$rejecting = [];
    Mail::to('ann@example.com')->sendNow($mail);

    $attempts = EmailLog::orderBy('id')->get();
    expect($attempts->pluck('status')->all())->toBe(['failed', 'sent'])
        ->and($attempts->pluck('attempt')->all())->toBe([1, 2])
        ->and($attempts->pluck('message_key')->unique())->toHaveCount(1)
        ->and($attempts[0]->error_message)->toContain('Recipient address rejected')
        ->and($attempts[1]->retry_of_id)->toBe($attempts[0]->id);
});

it('classifies emails sent by scheduled notification commands and configured schedules as Scheduled', function () {
    // A scheduled reminder command running in the background (no user session).
    EmailLogContext::commandStarted('app:send-checklist-reminders');
    sendTemplatedEmail($this->template, 'ann@example.com');
    EmailLogContext::commandFinished('app:send-checklist-reminders');

    $reminder = EmailLog::sole();
    expect($reminder->notification_type)->toBe('scheduled')
        ->and($reminder->trigger)->toBe('Checklist reminder (scheduled) (app:send-checklist-reminders)')
        ->and($reminder->trigger_source)->toBe('console')
        ->and($reminder->triggered_by_user_id)->toBeNull();

    // The scheduled email template dispatcher's own context: schedule name + intended date.
    EmailLogContext::scope([
        'notification_type' => EmailLog::TYPE_SCHEDULED,
        'trigger' => 'Scheduled email template',
        'schedule_name' => 'Checklist Reminder Notice — 3 days Before Last Working Day',
        'scheduled_for' => '2026-10-20',
        'offboarding_request_id' => $this->request->id,
    ], fn () => sendTemplatedEmail($this->template, 'bob@example.com'));

    $scheduled = EmailLog::latest('id')->first();
    expect($scheduled->notification_type)->toBe('scheduled')
        ->and($scheduled->schedule_name)->toBe('Checklist Reminder Notice — 3 days Before Last Working Day')
        ->and($scheduled->scheduled_for->toDateString())->toBe('2026-10-20')
        ->and($scheduled->offboarding_request_id)->toBe($this->request->id);

    // An automated but event-driven command stays System-Triggered.
    EmailLogContext::commandStarted('app:escalate-clearance-signatories');
    sendTemplatedEmail($this->template, 'carl@example.com');
    EmailLogContext::commandFinished('app:escalate-clearance-signatories');
    expect(EmailLog::latest('id')->first()->notification_type)->toBe('system');
});

it('lists, filters and shows email notification logs to HR/Admin only', function () {
    $this->actingAs($this->admin);
    sendTemplatedEmail($this->template, 'ann@example.com');
    RecordingTransport::$down = true;
    try {
        sendTemplatedEmail($this->template, 'zed@example.com');
    } catch (Exception) {
    }
    RecordingTransport::$down = false;

    $this->get(route('logs.email-notifications'))->assertOk()
        ->assertSee('Email Notifications')
        ->assertSee('ann@example.com')
        ->assertSee('zed@example.com')
        ->assertSee('Sent: 1')
        ->assertSee('Failed: 1');

    $this->get(route('logs.email-notifications', ['status' => 'failed']))->assertOk()
        ->assertSee('zed@example.com')->assertDontSee('ann@example.com');
    $this->get(route('logs.email-notifications', ['recipient' => 'ann@']))->assertOk()
        ->assertSee('ann@example.com')->assertDontSee('zed@example.com');
    $this->get(route('logs.email-notifications', ['type' => 'scheduled']))->assertOk()
        ->assertSee('No email notifications match the current filters.');
    $this->get(route('logs.email-notifications', ['date_from' => '2026-10-21']))->assertOk()
        ->assertDontSee('ann@example.com');

    $failed = EmailLog::where('status', 'failed')->sole();
    $this->getJson(route('logs.email-notifications.show', $failed))->assertOk()
        ->assertJsonPath('statusLabel', 'Failed')
        ->assertJsonPath('typeLabel', 'System-Triggered')
        ->assertJsonPath('templateName', 'Checklist Reminder Notice')
        ->assertJsonPath('to', ['zed@example.com'])
        ->assertJsonPath('offboardingRequestId', $this->request->id);

    // The activity log tab is still there, alongside.
    $this->get(route('logs.index'))->assertOk()->assertSee(route('logs.email-notifications'), false);

    $employee = User::factory()->create(['username' => 'no.logs']);
    $employee->forceFill(['must_change_password' => false])->save();
    $employee->assignRole(User::ROLE_EMPLOYEE);
    $this->actingAs($employee)->get(route('logs.email-notifications'))->assertForbidden();
});

it('does not log anything when mail is faked', function () {
    Mail::fake();
    sendTemplatedEmail($this->template, 'ann@example.com');

    expect(EmailLog::count())->toBe(0);
});
