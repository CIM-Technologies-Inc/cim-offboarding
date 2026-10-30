<?php

use App\Http\Requests\StoreEmailTemplateRequest;
use App\Http\Requests\UpdateEmailTemplateRequest;
use App\Models\ChecklistTemplate;
use App\Models\EmailTemplate;
use App\Models\EmailTemplateSchedule;
use App\Models\EmailTemplateScheduleSend;
use App\Models\Employee;
use App\Models\GeneralSignatory;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use App\Models\OffboardingRequestGeneralSignatory;
use App\Models\User;
use App\Services\EmailTemplateScheduleDispatcher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Tests\Support\RecordingTransport;

/*
 * End-to-end coverage of the Email Notification Schedule Timeline
 * (`EmailTemplateSchedule` -> `app:send-email-template-schedules` ->
 * `EmailTemplateScheduleDispatcher` -> mail transport / database
 * notifications -> `EmailTemplateScheduleSend` ledger).
 *
 * Uses a recording transport in place of SMTP (see `RecordingTransport`)
 * rather than `Mail::fake()`, so assertions are made against the envelope
 * recipients the transport actually received.
 *
 * Needs the MySQL/MariaDB schema (several migrations use `ALTER ... MODIFY`):
 *   DB_CONNECTION=mysql DB_DATABASE=cim_db_test vendor/bin/pest tests/Feature/ScheduledEmailNotificationsTest.php
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    RecordingTransport::reset();
    Mail::extend('recording', fn () => new RecordingTransport);
    config(['mail.mailers.recording' => ['transport' => 'recording'], 'mail.default' => 'recording']);
    Mail::forgetMailers();
    Cache::flush();

    $this->travelTo(Carbon::parse('2026-10-01 08:00:00'));
});

function schedEmployee(array $attributes = []): Employee
{
    return Employee::factory()->create($attributes);
}

function schedRequest(array $attributes = []): OffboardingRequest
{
    return OffboardingRequest::factory()->create(array_merge([
        'employee_id' => schedEmployee(['status' => 'offboarding', 'name' => 'Olivia Offboardee'])->id,
        'status' => 'pending',
        'completed_at' => null,
        'created_at' => Carbon::parse('2026-10-01 09:30:00'),
        'last_working_day' => '2026-10-31',
    ], $attributes));
}

function schedChecklist(string $title, bool $finalPay = false, ?int $clearanceDeadlineDays = 19): ChecklistTemplate
{
    return ChecklistTemplate::forceCreate(['title' => $title, 'is_final_pay_checklist' => $finalPay, 'clearance_signing_deadline_days' => $clearanceDeadlineDays]);
}

function schedApprover(OffboardingRequest $request, ChecklistTemplate $checklist, Employee $signatory, array $attributes = []): OffboardingRequestApprover
{
    return OffboardingRequestApprover::forceCreate(array_merge([
        'offboarding_request_id' => $request->id,
        'checklist_template_id' => $checklist->id,
        'employee_id' => $signatory->id,
        'status' => 'pending',
        'due_at' => '2026-10-31 23:59:59',
    ], $attributes));
}

function schedGeneralSignatory(OffboardingRequest $request, Employee $signatory): GeneralSignatory
{
    $gs = GeneralSignatory::forceCreate(['clearance_signatory_id' => $signatory->id, 'is_active' => true]);

    OffboardingRequestGeneralSignatory::forceCreate([
        'offboarding_request_id' => $request->id,
        'general_signatory_id' => $gs->id,
        'status' => 'pending',
    ]);

    return $gs;
}

function schedTemplate(array $attributes = []): EmailTemplate
{
    return EmailTemplate::forceCreate(array_merge([
        'template_name' => 'Audit Reminder '.uniqid(),
        'subject' => 'Reminder for {{employee_name}}',
        'html_content' => '<p>Hello {{approver_name}}, please action the clearance for {{employee_name}} ({{employee_number}}).</p>',
        'is_active' => true,
    ], $attributes));
}

function schedSchedule(array $attributes = [], ?EmailTemplate $template = null): EmailTemplateSchedule
{
    return EmailTemplateSchedule::forceCreate(array_merge([
        'email_template_id' => ($template ?? schedTemplate())->id,
        'event' => 'last_working_day',
        'timing' => 'before',
        'days' => 7,
        'channel' => 'email',
        'is_active' => true,
    ], $attributes));
}

function runScheduler(): void
{
    Artisan::call('app:send-email-template-schedules');
}

function sendsFor(EmailTemplateSchedule $schedule)
{
    return EmailTemplateScheduleSend::where('email_template_schedule_id', $schedule->id)->get();
}

// ---------------------------------------------------------------------
// Date calculation
// ---------------------------------------------------------------------

it('computes Before/After/zero-day dates against the Last Working Day', function () {
    $request = schedRequest(['last_working_day' => '2026-10-31']);
    $dispatcher = app(EmailTemplateScheduleDispatcher::class);

    expect($dispatcher->computeScheduledDate(schedSchedule(['timing' => 'before', 'days' => 7]), $request)->toDateString())->toBe('2026-10-24')
        ->and($dispatcher->computeScheduledDate(schedSchedule(['timing' => 'after', 'days' => 3]), $request)->toDateString())->toBe('2026-11-03')
        ->and($dispatcher->computeScheduledDate(schedSchedule(['timing' => 'before', 'days' => 0]), $request)->toDateString())->toBe('2026-10-31')
        ->and($dispatcher->computeScheduledDate(schedSchedule(['timing' => 'after', 'days' => 0]), $request)->toDateString())->toBe('2026-10-31');
});

it('handles month-end and leap-year boundaries', function () {
    $dispatcher = app(EmailTemplateScheduleDispatcher::class);

    expect($dispatcher->computeScheduledDate(schedSchedule(['timing' => 'after', 'days' => 1]), schedRequest(['last_working_day' => '2028-02-28']))->toDateString())->toBe('2028-02-29')
        ->and($dispatcher->computeScheduledDate(schedSchedule(['timing' => 'before', 'days' => 1]), schedRequest(['last_working_day' => '2027-03-01']))->toDateString())->toBe('2027-02-28')
        ->and($dispatcher->computeScheduledDate(schedSchedule(['timing' => 'after', 'days' => 1]), schedRequest(['last_working_day' => '2026-12-31']))->toDateString())->toBe('2027-01-01');
});

it('uses the latest Final Pay checklist due date as the Final Pay reference, and nothing before one exists', function () {
    $request = schedRequest();
    $schedule = schedSchedule(['event' => 'final_pay', 'timing' => 'before', 'days' => 2]);
    $dispatcher = app(EmailTemplateScheduleDispatcher::class);

    expect($dispatcher->computeScheduledDate($schedule, $request))->toBeNull();

    schedApprover($request, schedChecklist('IT'), schedEmployee(), ['due_at' => '2026-12-31 23:59:59']);
    schedApprover($request, schedChecklist('Payroll', true), schedEmployee(), ['due_at' => '2026-11-08 23:59:59']);
    schedApprover($request, schedChecklist('Finance', true), schedEmployee(), ['due_at' => '2026-11-10 23:59:59']);

    expect($dispatcher->computeScheduledDate($schedule, $request)->toDateString())->toBe('2026-11-08');
});

it('sends an "N days after Creation" schedule on the calendar date, regardless of the creation time-of-day', function () {
    $request = schedRequest(['created_at' => Carbon::parse('2026-10-01 15:45:00')]);
    schedApprover($request, schedChecklist('IT'), schedEmployee(['email' => 'it@example.test']));
    $schedule = schedSchedule(['event' => 'creation', 'timing' => 'after', 'days' => 1]);

    // The daily 08:00 sweep on the due date must pick it up — not a day late.
    $this->travelTo(Carbon::parse('2026-10-02 08:00:00'));
    runScheduler();

    expect(RecordingTransport::toAddresses())->toBe(['it@example.test'])
        ->and(sendsFor($schedule)->first()->scheduled_for->toDateString())->toBe('2026-10-02');
});

it('sends a "Before Final Pay" schedule on its date even though Final Pay is due at 23:59:59', function () {
    $request = schedRequest();
    schedApprover($request, schedChecklist('Payroll', true), schedEmployee(['email' => 'payroll@example.test']), ['due_at' => '2026-11-10 23:59:59']);
    $schedule = schedSchedule(['event' => 'final_pay', 'timing' => 'before', 'days' => 2]);

    $this->travelTo(Carbon::parse('2026-11-08 08:00:00'));
    runScheduler();

    expect(RecordingTransport::toAddresses())->toBe(['payroll@example.test'])
        ->and(sendsFor($schedule)->first()->scheduled_for->toDateString())->toBe('2026-11-08');
});

it('resolves the due date in the application timezone (Asia/Manila), not UTC', function () {
    expect(config('app.timezone'))->toBe('Asia/Manila');

    $request = schedRequest(['last_working_day' => '2026-10-31']);
    schedApprover($request, schedChecklist('IT'), schedEmployee(['email' => 'it@example.test']));
    schedSchedule(['timing' => 'before', 'days' => 7]); // due 2026-10-24

    // 2026-10-24 00:30 in Manila is still 2026-10-23 in UTC.
    $this->travelTo(Carbon::parse('2026-10-24 00:30:00', 'Asia/Manila'));
    runScheduler();

    expect(RecordingTransport::toAddresses())->toBe(['it@example.test']);
});

// ---------------------------------------------------------------------
// Due / not due / workflow state
// ---------------------------------------------------------------------

it('never sends before the scheduled date, then sends exactly once on it', function () {
    $request = schedRequest(['last_working_day' => '2026-10-31']);
    schedApprover($request, schedChecklist('IT'), schedEmployee(['email' => 'it@example.test']));
    $schedule = schedSchedule(['timing' => 'before', 'days' => 7]);

    $this->travelTo(Carbon::parse('2026-10-23 23:59:00'));
    runScheduler();
    expect(RecordingTransport::$messages)->toBeEmpty()
        ->and(sendsFor($schedule))->toHaveCount(0);

    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();
    runScheduler();
    $this->travelTo(Carbon::parse('2026-10-25 08:00:00'));
    runScheduler();

    expect(RecordingTransport::toAddresses())->toBe(['it@example.test'])
        ->and(sendsFor($schedule))->toHaveCount(1)
        ->and(sendsFor($schedule)->first()->status)->toBe('sent');
});

it('catches up an occurrence missed while the scheduler was down, once', function () {
    $request = schedRequest(['last_working_day' => '2026-10-31']);
    schedApprover($request, schedChecklist('IT'), schedEmployee(['email' => 'it@example.test']));
    $schedule = schedSchedule(['timing' => 'before', 'days' => 7]);

    $this->travelTo(Carbon::parse('2026-10-27 08:00:00'));
    runScheduler();
    runScheduler();

    expect(RecordingTransport::toAddresses())->toBe(['it@example.test'])
        ->and(sendsFor($schedule)->first()->scheduled_for->toDateString())->toBe('2026-10-24');
});

it('recalculates after a Last Working Day extension and never uses the stale date', function () {
    $request = schedRequest(['last_working_day' => '2026-10-31']);
    schedApprover($request, schedChecklist('IT'), schedEmployee(['email' => 'it@example.test']));
    $schedule = schedSchedule(['timing' => 'before', 'days' => 7]); // 2026-10-24

    // Extended before the original reminder date: nothing on the old date.
    $request->update(['last_working_day' => '2026-11-15']); // -> 2026-11-08
    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();
    expect(RecordingTransport::$messages)->toBeEmpty();

    $this->travelTo(Carbon::parse('2026-11-08 08:00:00'));
    runScheduler();
    runScheduler();

    expect(RecordingTransport::toAddresses())->toBe(['it@example.test'])
        ->and(sendsFor($schedule)->pluck('scheduled_for')->map->toDateString()->all())->toBe(['2026-11-08']);
});

it('never sends for a retracted/cancelled request and records why', function () {
    $request = schedRequest(['status' => 'cancelled']);
    schedApprover($request, schedChecklist('IT'), schedEmployee(['email' => 'it@example.test']));
    $schedule = schedSchedule(['timing' => 'before', 'days' => 7]);

    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();
    runScheduler();

    expect(RecordingTransport::$messages)->toBeEmpty()
        ->and(sendsFor($schedule)->pluck('status')->all())->toBe(['cancelled']);
});

it('never sends for a request completed before the reminder came due', function () {
    $request = schedRequest(['status' => 'completed', 'completed_at' => now()]);
    schedApprover($request, schedChecklist('IT'), schedEmployee(['email' => 'it@example.test']));
    schedSchedule(['timing' => 'before', 'days' => 7]);

    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();

    expect(RecordingTransport::$messages)->toBeEmpty();
});

it('skips inactive schedules and schedules on inactive templates', function () {
    $request = schedRequest();
    schedApprover($request, schedChecklist('IT'), schedEmployee(['email' => 'it@example.test']));
    schedSchedule(['is_active' => false]);
    schedSchedule([], schedTemplate(['is_active' => false]));

    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();

    expect(RecordingTransport::$messages)->toBeEmpty()
        ->and(EmailTemplateScheduleSend::count())->toBe(0);
});

it('does nothing (and records nothing) for a Final Pay schedule with no Final Pay checklist yet', function () {
    $request = schedRequest();
    schedApprover($request, schedChecklist('IT'), schedEmployee(['email' => 'it@example.test']));
    schedSchedule(['event' => 'final_pay', 'timing' => 'after', 'days' => 0]);

    $this->travelTo(Carbon::parse('2027-01-01 08:00:00'));
    runScheduler();

    expect(RecordingTransport::$messages)->toBeEmpty()
        ->and(EmailTemplateScheduleSend::count())->toBe(0);
});

// ---------------------------------------------------------------------
// Recipients (To / CC / BCC)
// ---------------------------------------------------------------------

function recipientFixture(): array
{
    $request = schedRequest();
    $it = schedChecklist('IT');
    $hr = schedChecklist('HR');
    schedApprover($request, $it, schedEmployee(['email' => 'it.signatory@example.test']));
    schedApprover($request, $hr, schedEmployee(['email' => 'hr.signatory@example.test']));
    $gs = schedGeneralSignatory($request, schedEmployee(['email' => 'general.signatory@example.test']));

    // A second, unrelated request whose people must never receive the first request's notification.
    $other = schedRequest(['last_working_day' => '2026-12-31']);
    schedApprover($other, $it, schedEmployee(['email' => 'other.request@example.test']));
    schedGeneralSignatory($other, schedEmployee(['email' => 'other.gs@example.test']));

    return [$request, $it, $hr, $gs];
}

it('sends to every Clearance and General Signatory on THIS request when nothing is selected — never the offboardee or another request', function () {
    [$request] = recipientFixture();
    schedSchedule(['timing' => 'before', 'days' => 7]);

    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();

    $to = RecordingTransport::toAddresses();
    sort($to);

    expect($to)->toBe(['general.signatory@example.test', 'hr.signatory@example.test', 'it.signatory@example.test'])
        ->and($to)->not->toContain($request->employee->email);

    foreach (RecordingTransport::$messages as $message) {
        expect($message['to'])->toHaveCount(1)
            ->and($message['envelope'])->toBe($message['to']);
    }
});

it('restricts recipients to the selected checklists / general signatories only', function () {
    [, $it, , $gs] = recipientFixture();
    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));

    $checklistOnly = schedSchedule();
    $checklistOnly->checklistTemplates()->sync([$it->id]);
    $gsOnly = schedSchedule();
    $gsOnly->generalSignatories()->sync([$gs->id]);

    $dispatcher = app(EmailTemplateScheduleDispatcher::class);
    $request = OffboardingRequest::with(['approvers.employee', 'generalSignatoryApprovals.generalSignatory.clearanceSignatory'])->find(sendsRequestId());

    expect($dispatcher->resolveRecipients($checklistOnly->fresh(['checklistTemplates', 'generalSignatories']), $request)->pluck('email')->values()->all())->toBe(['it.signatory@example.test'])
        ->and($dispatcher->resolveRecipients($gsOnly->fresh(['checklistTemplates', 'generalSignatories']), $request)->pluck('email')->values()->all())->toBe(['general.signatory@example.test']);

    runScheduler();

    $to = RecordingTransport::toAddresses();
    sort($to);
    expect($to)->toBe(['general.signatory@example.test', 'it.signatory@example.test']);
});

function sendsRequestId(): int
{
    return OffboardingRequest::orderBy('id')->value('id');
}

it('adds configured template recipients as CC only, never as To, and never CCs the primary recipient to themselves', function () {
    $request = schedRequest();
    schedApprover($request, schedChecklist('IT'), schedEmployee(['email' => 'it.signatory@example.test']));
    schedApprover($request, schedChecklist('HR'), $hrHead = schedEmployee(['email' => 'hr.head@example.test']));

    $template = schedTemplate();
    $template->recipientEmployees()->sync([schedEmployee(['email' => 'hr.cc@example.test'])->id, $hrHead->id]);
    schedSchedule([], $template);

    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();

    $byTo = collect(RecordingTransport::$messages)->keyBy(fn ($m) => $m['to'][0]);

    expect($byTo->keys()->sort()->values()->all())->toBe(['hr.head@example.test', 'it.signatory@example.test'])
        ->and($byTo['it.signatory@example.test']['cc'])->toEqualCanonicalizing(['hr.cc@example.test', 'hr.head@example.test'])
        ->and($byTo['hr.head@example.test']['cc'])->toBe(['hr.cc@example.test'])
        ->and(collect(RecordingTransport::$messages)->pluck('bcc')->flatten()->all())->toBe([]);
});

it('emails a shared address once, and skips missing/invalid addresses without blocking the rest', function () {
    $request = schedRequest();
    schedApprover($request, schedChecklist('IT'), schedEmployee(['email' => 'Shared@Example.test']));
    schedApprover($request, schedChecklist('HR'), schedEmployee(['email' => 'shared@example.test ']));
    schedApprover($request, schedChecklist('Admin'), schedEmployee(['email' => 'not-an-email']));
    schedApprover($request, schedChecklist('Finance'), schedEmployee(['email' => 'finance@example.test']));
    $schedule = schedSchedule();

    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();

    $to = RecordingTransport::toAddresses();
    sort($to);
    expect($to)->toBe(['finance@example.test', 'shared@example.test'])
        ->and(sendsFor($schedule)->first()->status)->toBe('sent');
});

it('records every resolved recipient, including skipped ones, on the ledger row', function () {
    $request = schedRequest();
    schedApprover($request, schedChecklist('IT'), schedEmployee(['email' => 'it@example.test']));
    schedApprover($request, schedChecklist('Admin'), schedEmployee(['email' => 'not-an-email', 'name' => 'Bad Email']));
    $template = schedTemplate();
    $template->recipientEmployees()->sync([schedEmployee(['email' => 'hr.cc@example.test'])->id]);
    $schedule = schedSchedule([], $template);

    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();

    $log = collect(sendsFor($schedule)->first()->recipient_log)->keyBy('email');

    expect($log['it@example.test']['status'])->toBe('sent')
        ->and($log['it@example.test']['cc'])->toBe(['hr.cc@example.test'])
        ->and($log['not-an-email']['status'])->toBe('skipped')
        ->and(sendsFor($schedule)->first()->attempts)->toBe(1);
});

it('renders the template with the correct offboardee details for each recipient', function () {
    $request = schedRequest();
    schedApprover($request, schedChecklist('IT'), schedEmployee(['email' => 'it@example.test', 'name' => 'Ivan Signatory']));
    schedSchedule();

    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();

    $message = RecordingTransport::$messages[0];
    expect($message['subject'])->toBe('Reminder for Olivia Offboardee')
        ->and($message['html'])->toContain('Hello Ivan Signatory')
        ->and($message['html'])->toContain($request->employee->employee_code);
});

// ---------------------------------------------------------------------
// Channels
// ---------------------------------------------------------------------

function channelFixture(string $channel): EmailTemplateSchedule
{
    $request = schedRequest();
    $signatory = schedEmployee(['email' => 'it@example.test']);
    User::factory()->create(['username' => $signatory->employee_code_digits, 'email' => 'it@example.test']);
    schedApprover($request, schedChecklist('IT'), $signatory);

    return schedSchedule(['channel' => $channel]);
}

it('Email channel sends only an email', function () {
    channelFixture('email');
    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();

    expect(RecordingTransport::$messages)->toHaveCount(1)
        ->and(DB::table('notifications')->count())->toBe(0);
});

it('System Notification channel creates only an in-app notification', function () {
    $schedule = channelFixture('system');
    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();
    runScheduler();

    expect(RecordingTransport::$messages)->toHaveCount(0)
        ->and(DB::table('notifications')->where('type', 'scheduled_email_template')->count())->toBe(1)
        ->and(sendsFor($schedule)->first()->system_sent_at)->not->toBeNull()
        ->and(sendsFor($schedule)->first()->email_sent_at)->toBeNull();
});

it('Both channel sends one email and one notification, once', function () {
    channelFixture('email_and_system');
    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();
    runScheduler();

    expect(RecordingTransport::$messages)->toHaveCount(1)
        ->and(DB::table('notifications')->count())->toBe(1);
});

// ---------------------------------------------------------------------
// Failures, retries, duplicate prevention
// ---------------------------------------------------------------------

it('records an SMTP outage as failed with the reason, then retries and flips to sent', function () {
    Log::spy();
    $request = schedRequest();
    schedApprover($request, schedChecklist('IT'), schedEmployee(['email' => 'it@example.test']));
    $schedule = schedSchedule();

    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    RecordingTransport::$down = true;
    runScheduler();

    $row = sendsFor($schedule)->first();
    expect($row->status)->toBe('failed')
        ->and($row->failure_reason)->toContain('Connection could not be established')
        ->and(RecordingTransport::$messages)->toBeEmpty();
    Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'email template schedule'));

    RecordingTransport::$down = false;
    $this->travelTo(Carbon::parse('2026-10-25 08:00:00'));
    runScheduler();

    expect(sendsFor($schedule))->toHaveCount(1)
        ->and(sendsFor($schedule)->first()->status)->toBe('sent')
        ->and(sendsFor($schedule)->first()->attempts)->toBe(2)
        ->and(RecordingTransport::toAddresses())->toBe(['it@example.test']);
});

it('does not re-send to recipients who already received it when retrying a partial failure', function () {
    $request = schedRequest();
    schedApprover($request, schedChecklist('IT'), schedEmployee(['email' => 'a@example.test']));
    schedApprover($request, schedChecklist('HR'), schedEmployee(['email' => 'b@example.test']));
    $schedule = schedSchedule();

    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    RecordingTransport::$rejecting = ['b@example.test'];
    runScheduler();

    expect(sendsFor($schedule)->first()->status)->toBe('failed')
        ->and(RecordingTransport::toAddresses())->toBe(['a@example.test']);

    RecordingTransport::$rejecting = [];
    runScheduler();

    expect(RecordingTransport::toAddresses())->toBe(['a@example.test', 'b@example.test'])
        ->and(sendsFor($schedule)->first()->status)->toBe('sent');
});

it('does not repeat the system notification when only the email half failed', function () {
    $schedule = channelFixture('email_and_system');
    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    RecordingTransport::$down = true;
    runScheduler();
    RecordingTransport::$down = false;
    runScheduler();

    expect(DB::table('notifications')->count())->toBe(1)
        ->and(RecordingTransport::$messages)->toHaveCount(1)
        ->and(sendsFor($schedule)->first()->status)->toBe('sent');
});

it('skips an occurrence another process is already sending (overlapping runs)', function () {
    $request = schedRequest();
    schedApprover($request, schedChecklist('IT'), schedEmployee(['email' => 'it@example.test']));
    $schedule = schedSchedule();
    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));

    $lock = Cache::lock(EmailTemplateScheduleDispatcher::occurrenceLockKey($schedule->id, $request->id, '2026-10-24'), 60);
    expect($lock->get())->toBeTrue();

    runScheduler();
    expect(RecordingTransport::$messages)->toBeEmpty();

    $lock->release();
    runScheduler();
    expect(RecordingTransport::toAddresses())->toBe(['it@example.test']);
});

it('sends the email synchronously from the scheduler so the ledger reflects the transport result', function () {
    Mail::fake();
    $request = schedRequest();
    schedApprover($request, schedChecklist('IT'), schedEmployee(['email' => 'it@example.test']));
    schedSchedule();

    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();

    Mail::assertSent(App\Mail\ChecklistSignatoryAnnouncementMail::class, fn ($mail) => $mail->hasTo('it@example.test'));
    Mail::assertNothingQueued();
});

// ---------------------------------------------------------------------
// Scheduler registration
// ---------------------------------------------------------------------

it('registers every time-driven notification command with overlap protection', function () {
    Artisan::call('schedule:list');
    $events = collect(app(Schedule::class)->events());

    foreach ([
        'app:send-email-template-schedules' => '*/15 * * * *',
        'app:send-scheduled-checklist-item-notifications' => '*/15 * * * *',
        'app:send-checklist-reminders' => '*/15 * * * *',
        'app:notify-overdue-checklists' => '*/5 * * * *',
        'app:notify-clearance-signing-due' => '*/5 * * * *',
    ] as $command => $expression) {
        $event = $events->first(fn ($e) => str_contains((string) $e->command, $command));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe($expression)
            ->and($event->withoutOverlapping)->toBeTrue("{$command} must not overlap")
            ->and($event->onOneServer)->toBeTrue("{$command} must only run once per tick");
    }
});

// ---------------------------------------------------------------------
// Schedule configuration validation (backend)
// ---------------------------------------------------------------------

function scheduleValidation(array $schedules, string $requestClass = StoreEmailTemplateRequest::class)
{
    return Validator::make(
        ['template_name' => 'Audit Template', 'subject' => 'S', 'schedules' => $schedules],
        (new $requestClass)->rules()
    );
}

it('accepts a valid timeline including a zero-day schedule', function () {
    expect(scheduleValidation([
        ['event' => 'last_working_day', 'timing' => 'before', 'days' => 7, 'channel' => 'email'],
        ['event' => 'creation', 'timing' => 'after', 'days' => 0, 'channel' => 'system'],
        ['event' => 'clearance', 'timing' => 'before', 'days' => 2, 'clearance_signing_deadline_days' => 19, 'channel' => 'email_and_system'],
        ['event' => 'final_pay', 'timing' => 'after', 'days' => 1, 'channel' => 'email'],
    ])->passes())->toBeTrue();
});

it('rejects missing, invalid and negative schedule fields', function (array $row, string $field) {
    $validator = scheduleValidation([$row]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain("schedules.0.{$field}");
})->with([
    'missing event' => [['timing' => 'before', 'days' => 1, 'channel' => 'email'], 'event'],
    'invalid event' => [['event' => 'birthday', 'timing' => 'before', 'days' => 1, 'channel' => 'email'], 'event'],
    'invalid timing' => [['event' => 'last_working_day', 'timing' => 'during', 'days' => 1, 'channel' => 'email'], 'timing'],
    'negative days' => [['event' => 'last_working_day', 'timing' => 'before', 'days' => -1, 'channel' => 'email'], 'days'],
    'fractional days' => [['event' => 'last_working_day', 'timing' => 'before', 'days' => 1.5, 'channel' => 'email'], 'days'],
    'missing days' => [['event' => 'last_working_day', 'timing' => 'before', 'channel' => 'email'], 'days'],
    'invalid channel' => [['event' => 'last_working_day', 'timing' => 'before', 'days' => 1, 'channel' => 'sms'], 'channel'],
    'negative clearance deadline' => [['event' => 'clearance', 'timing' => 'before', 'days' => 1, 'clearance_signing_deadline_days' => -3, 'channel' => 'email'], 'clearance_signing_deadline_days'],
]);

it('rejects "N days Before Creation" on both create and update', function (string $requestClass) {
    $validator = scheduleValidation([['event' => 'creation', 'timing' => 'before', 'days' => 3, 'channel' => 'email']], $requestClass);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('schedules.0.timing');
})->with(['store' => [StoreEmailTemplateRequest::class], 'update' => [UpdateEmailTemplateRequest::class]]);

it('rejects duplicate schedules on the same template, but not a removed or differently-targeted one', function (string $requestClass) {
    $row = ['event' => 'last_working_day', 'timing' => 'before', 'days' => 7, 'channel' => 'email'];

    expect(scheduleValidation([$row, $row], $requestClass)->errors()->keys())->toContain('schedules.1.days')
        // "0 days before" and "0 days after" are the same occurrence.
        ->and(scheduleValidation([
            ['event' => 'last_working_day', 'timing' => 'before', 'days' => 0, 'channel' => 'email'],
            ['event' => 'last_working_day', 'timing' => 'after', 'days' => 0, 'channel' => 'email'],
        ], $requestClass)->fails())->toBeTrue()
        ->and(scheduleValidation([$row, $row + ['_destroy' => true]], $requestClass)->passes())->toBeTrue()
        ->and(scheduleValidation([$row, ['days' => 8] + $row], $requestClass)->passes())->toBeTrue()
        ->and(scheduleValidation([$row, ['channel' => 'system'] + $row], $requestClass)->passes())->toBeTrue();
})->with(['store' => [StoreEmailTemplateRequest::class], 'update' => [UpdateEmailTemplateRequest::class]]);

// ---------------------------------------------------------------------
// Saving the timeline (HTTP) and the eager at-creation job
// ---------------------------------------------------------------------

function templateEditor(): User
{
    Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'email-templates.edit', 'guard_name' => 'web']);
    Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'email-templates.view', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->givePermissionTo(['email-templates.edit', 'email-templates.view']);

    return $user;
}

it('deletes a saved schedule submitted with _destroy and keeps the others untouched', function () {
    $template = schedTemplate();
    $keep = schedSchedule(['days' => 7], $template);
    $remove = schedSchedule(['days' => 3, 'channel' => 'system'], $template);

    $this->actingAs(templateEditor())->put(route('email-templates.update', $template), [
        'template_name' => $template->template_name,
        'subject' => $template->subject,
        'html_content' => $template->html_content,
        'schedules' => [
            $keep->id => ['id' => $keep->id, 'event' => 'last_working_day', 'timing' => 'before', 'days' => 7, 'channel' => 'email'],
            $remove->id => ['id' => $remove->id, '_destroy' => 1, 'event' => 'last_working_day', 'timing' => 'before', 'days' => 3, 'channel' => 'system'],
        ],
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect(EmailTemplateSchedule::pluck('id')->all())->toBe([$keep->id])
        ->and($keep->fresh()->days)->toBe(7);
});

it('submits removed saved schedules with _destroy from the timeline editor', function () {
    $template = schedTemplate();
    schedSchedule([], $template);

    $this->actingAs(templateEditor())->get(route('email-templates.edit', $template))
        ->assertOk()
        ->assertSee('removedSchedules', false)
        ->assertSee("'][_destroy]'", false);
});

it('rejects a duplicate schedule over HTTP and leaves the saved timeline unchanged', function () {
    $template = schedTemplate();
    $row = ['event' => 'last_working_day', 'timing' => 'before', 'days' => 7, 'channel' => 'email'];

    $this->actingAs(templateEditor())->put(route('email-templates.update', $template), [
        'template_name' => $template->template_name,
        'subject' => $template->subject,
        'schedules' => ['-1' => $row, '-2' => $row],
    ])->assertSessionHasErrors('schedules.-2.days');

    expect(EmailTemplateSchedule::count())->toBe(0);
});

it('the queued at-creation job sends "On Creation" schedules once and leaves other events to the sweep', function () {
    $request = schedRequest(['created_at' => now()]);
    schedApprover($request, schedChecklist('IT'), schedEmployee(['email' => 'it@example.test']));
    $onCreation = schedSchedule(['event' => 'creation', 'timing' => 'after', 'days' => 0]);
    $lwd = schedSchedule(['event' => 'last_working_day', 'timing' => 'before', 'days' => 60]); // already due, but not a creation schedule

    App\Jobs\ProcessCreationEmailTemplateSchedules::dispatchSync($request->id);
    App\Jobs\ProcessCreationEmailTemplateSchedules::dispatchSync($request->id);
    runScheduler();

    expect(sendsFor($onCreation))->toHaveCount(1)
        ->and(sendsFor($lwd))->toHaveCount(1)
        ->and(RecordingTransport::toAddresses())->toBe(['it@example.test', 'it@example.test'])
        ->and(collect(RecordingTransport::$messages)->pluck('subject')->unique()->count())->toBe(1);
});

it('shows Not Applicable (never Scheduled/Due) on the request tab once a request is cancelled or completed', function (string $status) {
    $request = schedRequest(['status' => $status, 'completed_at' => $status === 'completed' ? now() : null]);
    schedApprover($request, schedChecklist('IT'), schedEmployee(['email' => 'it@example.test']));
    schedSchedule(['timing' => 'before', 'days' => 7]);
    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));

    $rows = app(App\Services\OffboardingRequestScheduledNotificationsService::class)->forRequest($request);

    expect($rows[0]['status'])->toBe('not_applicable');
})->with(['cancelled', 'completed']);

it('renders the timeline editor\'s Alpine x-data as one intact attribute (no stray quotes breaking the HTML)', function () {
    $template = schedTemplate();
    schedSchedule([], $template);

    $html = $this->actingAs(templateEditor())->get(route('email-templates.edit', $template))->assertOk()->getContent();

    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $timeline = collect(iterator_to_array((new DOMXPath($dom))->query('//*[@x-data]')))
        ->map(fn (DOMElement $el) => $el->getAttribute('x-data'))
        ->first(fn (string $xData) => str_contains($xData, 'timelineExpanded'));

    expect($timeline)->not->toBeNull()
        ->and($timeline)->toContain('validateDraft()')
        ->and($timeline)->toContain('scheduleSignature(s)')
        ->and($timeline)->toContain('removeSchedule(id)')
        ->and($timeline)->toContain('scheduleRecipientSummary(s)')
        ->and(trim($timeline))->toEndWith('}');
});

it('no longer shows the Active Checklist / Department Signatory Selection pickers in the schedule editor', function () {
    $template = schedTemplate();
    schedSchedule([], $template);

    $this->actingAs(templateEditor())->get(route('email-templates.edit', $template))
        ->assertOk()
        ->assertDontSee('Active Checklist Selection')
        ->assertDontSee('Active Department Signatory Selection')
        ->assertSee('Number of Days')
        ->assertSee('Save Schedule');
});

it('keeps an existing schedule\'s saved selection when the template is saved, and new schedules target everyone applicable', function () {
    $template = schedTemplate();
    $checklist = schedChecklist('IT');
    $existing = schedSchedule(['days' => 7], $template);
    $existing->checklistTemplates()->sync([$checklist->id]);

    // Exactly what the editor posts: the existing row carries its saved
    // selection via hidden inputs; a newly added row has none.
    $this->actingAs(templateEditor())->put(route('email-templates.update', $template), [
        'template_name' => $template->template_name,
        'subject' => $template->subject,
        'schedules' => [
            $existing->id => ['id' => $existing->id, 'event' => 'last_working_day', 'timing' => 'before', 'days' => 10, 'channel' => 'email', 'checklist_template_ids' => [$checklist->id]],
            '-1' => ['event' => 'final_pay', 'timing' => 'before', 'days' => 2, 'channel' => 'email_and_system'],
        ],
    ])->assertSessionHasNoErrors();

    $existing->refresh();
    $new = EmailTemplateSchedule::where('email_template_id', $template->id)->where('id', '!=', $existing->id)->first();

    expect($existing->days)->toBe(10)
        ->and($existing->checklistTemplates->pluck('id')->all())->toBe([$checklist->id])
        ->and($new->event)->toBe('final_pay')
        ->and($new->channel)->toBe('email_and_system')
        ->and($new->checklistTemplates)->toHaveCount(0)
        ->and($new->generalSignatories)->toHaveCount(0);
});

it('saves picker recipients once each (even if an id is submitted twice) and CCs them when the template sends', function () {
    $template = schedTemplate();
    $hrOne = schedEmployee(['department' => 'Human Resources', 'email' => 'hr.one@example.test']);
    $hrTwo = schedEmployee(['department' => 'Human Resources', 'email' => 'hr.two@example.test']);

    $this->actingAs(templateEditor())->put(route('email-templates.update', $template), [
        'template_name' => $template->template_name,
        'subject' => $template->subject,
        'recipient_employee_ids' => [$hrOne->id, $hrTwo->id, $hrOne->id],
    ])->assertSessionHasNoErrors();

    expect($template->fresh()->recipientEmployees->pluck('id')->sort()->values()->all())->toBe(collect([$hrOne->id, $hrTwo->id])->sort()->values()->all());

    $request = schedRequest();
    schedApprover($request, schedChecklist('IT'), schedEmployee(['email' => 'it@example.test']));
    schedSchedule([], $template);
    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();

    expect(RecordingTransport::$messages)->toHaveCount(1)
        ->and(RecordingTransport::$messages[0]['to'])->toBe(['it@example.test'])
        ->and(RecordingTransport::$messages[0]['cc'])->toEqualCanonicalizing(['hr.one@example.test', 'hr.two@example.test']);
});

it('gives the recipient picker every searchable field (name, code, email, department)', function () {
    $template = schedTemplate();
    schedEmployee(['name' => 'Searchable Person', 'employee_code' => 'EMP77777', 'email' => 'search.me@example.test', 'department' => 'Information Services']);

    $this->actingAs(templateEditor())->get(route('email-templates.edit', $template))
        ->assertOk()
        ->assertSee('Search by name, employee code, email, or department', false)
        ->assertSee('search.me@example.test', false)
        ->assertSee('EMP77777', false)
        ->assertSee('selectAllMatching()', false);
});

// ---------------------------------------------------------------------
// Repeat
// ---------------------------------------------------------------------

/** Runs the scheduler once (or `$timesPerDay` times) every day from $from to $to; returns the dates an email actually went out. */
function runDaily(string $from, string $to, int $timesPerDay = 1, ?Closure $beforeDay = null): array
{
    $sentOn = [];
    for ($day = Carbon::parse($from); $day->lte(Carbon::parse($to)); $day->addDay()) {
        $beforeDay?->__invoke($day->copy());
        test()->travelTo($day->copy()->setTime(8, 0));
        $before = count(RecordingTransport::$messages);
        foreach (range(1, $timesPerDay) as $i) {
            runScheduler();
        }
        if (count(RecordingTransport::$messages) > $before) {
            $sentOn[$day->toDateString()] = count(RecordingTransport::$messages) - $before;
        }
    }

    return $sentOn;
}

function repeatFixture(array $schedule, array $request = []): array
{
    $r = schedRequest(array_merge(['last_working_day' => '2026-10-31'], $request));
    schedApprover($r, schedChecklist('IT'), schedEmployee(['email' => 'it@example.test']));

    return [$r, schedSchedule($schedule)];
}

it('Example 1 — 7 days Before LWD, repeating every 2 days: sends 7, 5, 3, 1 days before, never on or after the LWD', function () {
    [, $schedule] = repeatFixture(['timing' => 'before', 'days' => 7, 'repeat_enabled' => true, 'repeat_interval_days' => 2]);

    $sent = runDaily('2026-10-20', '2026-11-06', timesPerDay: 2);

    expect($sent)->toBe(['2026-10-24' => 1, '2026-10-26' => 1, '2026-10-28' => 1, '2026-10-30' => 1])
        ->and(sendsFor($schedule)->pluck('scheduled_for')->map->toDateString()->sort()->values()->all())
        ->toBe(['2026-10-24', '2026-10-26', '2026-10-28', '2026-10-30']);
});

it('Example 2 — 7 days After LWD, repeating every 2 days: 7, 9, 11, 13… after, until the request is completed', function () {
    [$request] = repeatFixture(['timing' => 'after', 'days' => 7, 'repeat_enabled' => true, 'repeat_interval_days' => 2]);

    $sent = runDaily('2026-11-01', '2026-11-20', beforeDay: function (Carbon $day) use ($request) {
        if ($day->toDateString() === '2026-11-14') {
            $request->update(['status' => 'completed', 'completed_at' => $day]);
        }
    });

    expect(array_keys($sent))->toBe(['2026-11-07', '2026-11-09', '2026-11-11', '2026-11-13']);
});

it('repeats daily and at longer intervals, always computed from the saved configuration', function (array $config, array $expected) {
    repeatFixture($config + ['repeat_enabled' => true]);

    expect(array_keys(runDaily('2026-10-15', '2026-11-12')))->toBe($expected);
})->with([
    'every day, 4 before LWD' => [['timing' => 'before', 'days' => 4, 'repeat_interval_days' => 1], ['2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30']],
    'every 5 days, 12 before LWD' => [['timing' => 'before', 'days' => 12, 'repeat_interval_days' => 5], ['2026-10-19', '2026-10-24', '2026-10-29']],
    'every 4 days, 3 after LWD' => [['timing' => 'after', 'days' => 3, 'repeat_interval_days' => 4], ['2026-11-03', '2026-11-07', '2026-11-11']],
    'every 3 days, On LWD (0 days) keeps going after' => [['timing' => 'before', 'days' => 0, 'repeat_interval_days' => 3], ['2026-10-31', '2026-11-03', '2026-11-06', '2026-11-09', '2026-11-12']],
    'every 7 days, 9 before Clearance (19 after LWD)' => [['event' => 'clearance', 'clearance_signing_deadline_days' => 19, 'timing' => 'before', 'days' => 9, 'repeat_interval_days' => 7], ['2026-11-10']],
]);

it('with Repeat off sends only the original one-time notification', function () {
    [, $schedule] = repeatFixture(['timing' => 'before', 'days' => 7, 'repeat_enabled' => false, 'repeat_interval_days' => null]);

    expect(array_keys(runDaily('2026-10-20', '2026-11-10', timesPerDay: 3)))->toBe(['2026-10-24'])
        ->and(sendsFor($schedule))->toHaveCount(1);
});

it('recalculates future repeats when the Last Working Day is extended', function () {
    [$request] = repeatFixture(['timing' => 'before', 'days' => 7, 'repeat_enabled' => true, 'repeat_interval_days' => 2]);

    $sent = runDaily('2026-10-20', '2026-11-16', beforeDay: function (Carbon $day) use ($request) {
        if ($day->toDateString() === '2026-10-27') {
            $request->update(['last_working_day' => '2026-11-15']);
        }
    });

    // 24th and 26th from the old LWD; then 7 before the new LWD (Nov 8), every 2 days, stopping before Nov 15.
    expect(array_keys($sent))->toBe(['2026-10-24', '2026-10-26', '2026-11-08', '2026-11-10', '2026-11-12', '2026-11-14']);
});

it('stops repeating once the request is cancelled/retracted', function () {
    [$request, $schedule] = repeatFixture(['timing' => 'after', 'days' => 0, 'repeat_enabled' => true, 'repeat_interval_days' => 1]);

    $sent = runDaily('2026-10-31', '2026-11-08', beforeDay: function (Carbon $day) use ($request) {
        if ($day->toDateString() === '2026-11-03') {
            $request->update(['status' => 'cancelled']);
        }
    });

    expect(array_keys($sent))->toBe(['2026-10-31', '2026-11-01', '2026-11-02'])
        ->and(sendsFor($schedule)->where('status', 'cancelled'))->toHaveCount(1);
});

it('after scheduler downtime sends only the latest due repeat, not a burst of missed ones', function () {
    [, $schedule] = repeatFixture(['timing' => 'before', 'days' => 7, 'repeat_enabled' => true, 'repeat_interval_days' => 2]);

    $this->travelTo(Carbon::parse('2026-10-29 08:00'));
    runScheduler();
    runScheduler();

    expect(RecordingTransport::$messages)->toHaveCount(1)
        ->and(sendsFor($schedule)->first()->scheduled_for->toDateString())->toBe('2026-10-28');
});

it('sends nothing for a Before repeat once its event date is reached, even if a repeat was missed', function () {
    repeatFixture(['timing' => 'before', 'days' => 7, 'repeat_enabled' => true, 'repeat_interval_days' => 2]);

    $this->travelTo(Carbon::parse('2026-10-31 08:00'));
    runScheduler();
    $this->travelTo(Carbon::parse('2026-11-02 08:00'));
    runScheduler();

    expect(RecordingTransport::$messages)->toBeEmpty();
});

it('sends each repeat on every configured channel exactly once', function () {
    $schedule = channelFixture('email_and_system');
    $schedule->update(['repeat_enabled' => true, 'repeat_interval_days' => 2]);

    runDaily('2026-10-24', '2026-10-27', timesPerDay: 2);

    expect(RecordingTransport::$messages)->toHaveCount(2)
        ->and(DB::table('notifications')->count())->toBe(2)
        ->and(sendsFor($schedule)->pluck('status')->unique()->values()->all())->toBe(['sent']);
});

it('labels repeating schedules and reports the next repeat on the request tab', function () {
    [$request, $schedule] = repeatFixture(['timing' => 'before', 'days' => 7, 'repeat_enabled' => true, 'repeat_interval_days' => 2]);

    expect($schedule->label())->toBe('7 days Before Last Working Day, repeating every 2 days')
        ->and(schedSchedule(['days' => 3, 'repeat_enabled' => true, 'repeat_interval_days' => 1])->label())->toBe('3 days Before Last Working Day, repeating every day');

    $this->travelTo(Carbon::parse('2026-10-24 09:00'));
    runScheduler();
    $row = collect(app(App\Services\OffboardingRequestScheduledNotificationsService::class)->forRequest($request))->firstWhere('label', $schedule->label());

    expect($row['status'])->toBe('sent')
        ->and($row['scheduled_date'])->toBe('Oct 24, 2026')
        ->and($row['note'])->toContain('next on Oct 26, 2026');
});

it('saves and restores the Repeat setting, and clears the interval when Repeat is off', function () {
    $template = schedTemplate();

    $this->actingAs(templateEditor())->put(route('email-templates.update', $template), [
        'template_name' => $template->template_name,
        'subject' => $template->subject,
        'schedules' => [
            '-1' => ['event' => 'last_working_day', 'timing' => 'before', 'days' => 7, 'channel' => 'email', 'repeat_enabled' => '1', 'repeat_interval_days' => '2'],
            '-2' => ['event' => 'last_working_day', 'timing' => 'after', 'days' => 3, 'channel' => 'email', 'repeat_enabled' => '0', 'repeat_interval_days' => '5'],
        ],
    ])->assertSessionHasNoErrors();

    $saved = EmailTemplateSchedule::where('email_template_id', $template->id)->orderBy('id')->get();
    expect($saved->map(fn ($s) => [$s->repeat_enabled, $s->repeat_interval_days])->all())->toBe([[true, 2], [false, null]]);

    $this->get(route('email-templates.edit', $template))->assertOk()
        ->assertSee('Repeat Every (Days)')
        ->assertSee('repeatEnabled', false);
});

it('validates the repeat interval only when Repeat is on', function (array $row, bool $passes) {
    $validator = scheduleValidation([['event' => 'last_working_day', 'timing' => 'before', 'days' => 7, 'channel' => 'email'] + $row]);

    expect($validator->passes())->toBe($passes);
    if (! $passes) {
        expect($validator->errors()->keys())->toContain('schedules.0.repeat_interval_days');
    }
})->with([
    'repeat on, interval 1' => [['repeat_enabled' => '1', 'repeat_interval_days' => 1], true],
    'repeat on, interval 30' => [['repeat_enabled' => '1', 'repeat_interval_days' => 30], true],
    'repeat on, no interval' => [['repeat_enabled' => '1'], false],
    'repeat on, interval 0' => [['repeat_enabled' => '1', 'repeat_interval_days' => 0], false],
    'repeat on, negative' => [['repeat_enabled' => '1', 'repeat_interval_days' => -2], false],
    'repeat on, fractional' => [['repeat_enabled' => '1', 'repeat_interval_days' => 1.5], false],
    'repeat off, no interval' => [['repeat_enabled' => '0'], true],
    'repeat absent (existing forms)' => [[], true],
]);

// ---------------------------------------------------------------------
// Clearance Due Date (each checklist's own Clearance Signing Deadline)
// ---------------------------------------------------------------------

/** Runs the scheduler daily (twice a day) and returns [date => sorted To addresses] for every day something was sent. */
function clearanceRun(string $from, string $to, ?Closure $beforeDay = null): array
{
    $sent = [];
    for ($day = Carbon::parse($from); $day->lte(Carbon::parse($to)); $day->addDay()) {
        $beforeDay?->__invoke($day->copy());
        test()->travelTo($day->copy()->setTime(8, 0));
        $before = count(RecordingTransport::$messages);
        runScheduler();
        runScheduler();
        $new = array_slice(RecordingTransport::$messages, $before);
        if ($new) {
            $addresses = array_merge(...array_map(fn ($m) => $m['to'], $new));
            sort($addresses);
            $sent[$day->toDateString()] = $addresses;
        }
    }

    return $sent;
}

function clearanceRequest(array $checklists): OffboardingRequest
{
    $request = schedRequest(['last_working_day' => '2026-10-31']);
    foreach ($checklists as $title => [$deadline, $email]) {
        schedApprover($request, schedChecklist($title, false, $deadline), schedEmployee(['email' => $email]));
    }

    return $request;
}

it('Example A — 7 days Before Clearance Due Date (deadline 19) sends 12 days after LWD', function () {
    clearanceRequest(['IT' => [19, 'it@example.test']]);
    schedSchedule(['event' => 'clearance', 'timing' => 'before', 'days' => 7]);

    expect(clearanceRun('2026-11-01', '2026-11-30'))->toBe(['2026-11-12' => ['it@example.test']]);
});

it('Example B — 7 days After Clearance Due Date (deadline 19) sends 26 days after LWD', function () {
    clearanceRequest(['IT' => [19, 'it@example.test']]);
    schedSchedule(['event' => 'clearance', 'timing' => 'after', 'days' => 7]);

    expect(clearanceRun('2026-11-01', '2026-11-30'))->toBe(['2026-11-26' => ['it@example.test']]);
});

it('follows a changed Clearance Signing Deadline (19 → 25) instead of a fixed 19 days', function () {
    clearanceRequest(['IT' => [19, 'it@example.test']]);
    schedSchedule(['event' => 'clearance', 'timing' => 'before', 'days' => 7]);
    ChecklistTemplate::where('title', 'IT')->update(['clearance_signing_deadline_days' => 25]);

    // Clearance Due Date = Oct 31 + 25 = Nov 25; 7 days before = Nov 18.
    expect(clearanceRun('2026-11-01', '2026-11-30'))->toBe(['2026-11-18' => ['it@example.test']]);
});

it('handles different deadlines per checklist, emailing each signatory on their own checklist\'s date', function () {
    clearanceRequest([
        'IT' => [19, 'it@example.test'],
        'HR' => [15, 'hr@example.test'],
        'Finance' => [21, 'finance@example.test'],
        'Admin' => [19, 'admin@example.test'],
    ]);
    schedSchedule(['event' => 'clearance', 'timing' => 'before', 'days' => 7]);

    expect(clearanceRun('2026-11-01', '2026-11-30'))->toBe([
        '2026-11-08' => ['hr@example.test'],                          // 15 - 7 = LWD + 8
        '2026-11-12' => ['admin@example.test', 'it@example.test'],    // 19 - 7 = LWD + 12 (same deadline → one send)
        '2026-11-14' => ['finance@example.test'],                     // 21 - 7 = LWD + 14
    ]);
});

it('repeats from each checklist\'s Clearance Due Date and stops before it (Before)', function () {
    clearanceRequest(['IT' => [19, 'it@example.test']]);
    schedSchedule(['event' => 'clearance', 'timing' => 'before', 'days' => 7, 'repeat_enabled' => true, 'repeat_interval_days' => 2]);

    // Due Nov 19 → Nov 12, 14, 16, 18; nothing on/after Nov 19.
    expect(array_keys(clearanceRun('2026-11-01', '2026-11-30')))->toBe(['2026-11-12', '2026-11-14', '2026-11-16', '2026-11-18']);
});

it('stops Clearance Due Date repeats when the request is completed', function () {
    $request = clearanceRequest(['IT' => [19, 'it@example.test']]);
    schedSchedule(['event' => 'clearance', 'timing' => 'after', 'days' => 0, 'repeat_enabled' => true, 'repeat_interval_days' => 3]);

    $sent = clearanceRun('2026-11-15', '2026-12-05', function (Carbon $day) use ($request) {
        if ($day->toDateString() === '2026-11-26') {
            $request->update(['status' => 'completed', 'completed_at' => $day]);
        }
    });

    expect(array_keys($sent))->toBe(['2026-11-19', '2026-11-22', '2026-11-25']);
});

it('sends nothing for checklists with no deadline configured or that are inactive, and never to General Signatories', function () {
    $request = clearanceRequest(['No Deadline' => [null, 'none@example.test'], 'Retired' => [10, 'retired@example.test'], 'IT' => [19, 'it@example.test']]);
    ChecklistTemplate::where('title', 'Retired')->update(['is_active' => false]);
    schedGeneralSignatory($request, schedEmployee(['email' => 'general@example.test']));
    schedSchedule(['event' => 'clearance', 'timing' => 'before', 'days' => 0]);

    expect(clearanceRun('2026-11-01', '2026-11-30'))->toBe(['2026-11-19' => ['it@example.test']]);
});

it('respects a saved checklist selection on a Clearance Due Date schedule', function () {
    clearanceRequest(['IT' => [19, 'it@example.test'], 'HR' => [15, 'hr@example.test']]);
    $schedule = schedSchedule(['event' => 'clearance', 'timing' => 'before', 'days' => 7]);
    $schedule->checklistTemplates()->sync([ChecklistTemplate::where('title', 'HR')->value('id')]);

    expect(clearanceRun('2026-11-01', '2026-11-30'))->toBe(['2026-11-08' => ['hr@example.test']]);
});

it('labels the event "Clearance Due Date" and lists one row per deadline on the request tab', function () {
    $request = clearanceRequest(['IT' => [19, 'it@example.test'], 'HR' => [15, 'hr@example.test']]);
    $schedule = schedSchedule(['event' => 'clearance', 'timing' => 'before', 'days' => 7]);

    expect($schedule->label())->toBe('7 days Before Clearance Due Date');

    $rows = collect(app(App\Services\OffboardingRequestScheduledNotificationsService::class)->forRequest($request))
        ->where('event', 'clearance')->values();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('scheduled_date')->all())->toBe(['Nov 08, 2026', 'Nov 12, 2026'])
        ->and($rows[0]['label'])->toBe('7 days Before Clearance Due Date — HR (due 15 days after Last Working Day)')
        ->and($rows[0]['recipients'][0]['email'])->toBe('hr@example.test')
        ->and($rows[1]['recipients'][0]['email'])->toBe('it@example.test');
});

it('shows "Clearance Due Date" in the editor and keeps the event when an existing schedule is saved again', function () {
    $template = schedTemplate();
    $schedule = schedSchedule(['event' => 'clearance', 'timing' => 'after', 'days' => 7], $template);

    $this->actingAs(templateEditor())->get(route('email-templates.edit', $template))
        ->assertOk()
        ->assertSee('<option value="clearance">Clearance Due Date</option>', false)
        ->assertDontSee('<option value="clearance">Clearance</option>', false);

    $this->put(route('email-templates.update', $template), [
        'template_name' => $template->template_name,
        'subject' => $template->subject,
        'schedules' => [$schedule->id => ['id' => $schedule->id, 'event' => 'clearance', 'timing' => 'after', 'days' => 9, 'channel' => 'email', 'repeat_enabled' => '0']],
    ])->assertSessionHasNoErrors();

    $schedule->refresh();
    expect([$schedule->event, $schedule->timing, $schedule->days, $schedule->clearance_signing_deadline_days])->toBe(['clearance', 'after', 9, null]);
});

// ---------------------------------------------------------------------
// {{checklist_name}} / checklist context per recipient
// ---------------------------------------------------------------------

/** The rendered HTML of the (first) message sent to $address. */
function sentHtmlTo(string $address): ?string
{
    foreach (RecordingTransport::$messages as $message) {
        if (in_array(strtolower($address), $message['to'], true)) {
            return $message['html'];
        }
    }

    return null;
}

/** Same wording as the real "Checklist Completion Daily Reminder" template. */
function dailyReminderTemplate(array $attributes = []): EmailTemplate
{
    return schedTemplate(array_merge([
        'subject' => 'Checklist Completion Daily Reminder',
        'html_content' => '<p>Good day Mrs/Mr.&nbsp;{{approver_name}},</p><p>This is your CIM Offboarding Assistant giving you a friendly reminder that your assigned&nbsp;{{checklist_name}} will be Due this coming&nbsp;{{due_date}}. We would like to&nbsp;ask your small cooperation to complete your assigned checklist</p>{{offboarding_link}}',
    ], $attributes));
}

it('fills {{checklist_name}} and {{due_date}} with each Clearance Signatory\'s own checklist', function () {
    $request = schedRequest();
    schedApprover($request, schedChecklist('IT Clearance Checklist'), schedEmployee(['name' => 'Ian It', 'email' => 'it@example.test']), ['due_at' => '2026-11-02 23:59:59']);
    schedApprover($request, schedChecklist('HR Checklist'), schedEmployee(['name' => 'Hana Hr', 'email' => 'hr@example.test']), ['due_at' => '2026-11-05 23:59:59']);
    schedSchedule(['days' => 7], dailyReminderTemplate());

    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();

    $itHtml = sentHtmlTo('it@example.test');
    $hrHtml = sentHtmlTo('hr@example.test');

    expect($itHtml)->toContain('Ian It')
        ->toContain('your assigned&nbsp;IT Clearance Checklist will be Due this coming&nbsp;Nov 02, 2026')
        ->not->toContain('HR Checklist')
        ->and($hrHtml)->toContain('your assigned&nbsp;HR Checklist will be Due this coming&nbsp;Nov 05, 2026')
        ->not->toContain('IT Clearance Checklist')
        ->and($itHtml.$hrHtml)->not->toContain('{{');
});

it('names only the still-active checklist, or every active one in order, when a signatory has several', function () {
    $request = schedRequest();
    $both = schedEmployee(['email' => 'both@example.test']);
    $mixed = schedEmployee(['email' => 'mixed@example.test']);

    schedApprover($request, schedChecklist('Admin Checklist'), $both, ['due_at' => '2026-11-03 23:59:59']);
    schedApprover($request, schedChecklist('Accounting Checklist'), $both, ['due_at' => '2026-11-01 23:59:59']);
    schedApprover($request, schedChecklist('Done Checklist'), $mixed, ['status' => 'approved', 'approved_at' => now()]);
    schedApprover($request, schedChecklist('Open Checklist'), $mixed, ['due_at' => '2026-11-04 23:59:59']);
    schedSchedule(['days' => 7], dailyReminderTemplate());

    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();

    // One email per address (the ledger is per recipient), names and due dates in matching order.
    expect(collect(RecordingTransport::toAddresses())->filter(fn ($a) => $a === 'both@example.test'))->toHaveCount(1)
        ->and(sentHtmlTo('both@example.test'))->toContain('Accounting Checklist, Admin Checklist will be Due this coming&nbsp;Nov 01, 2026, Nov 03, 2026')
        ->and(sentHtmlTo('mixed@example.test'))->toContain('your assigned&nbsp;Open Checklist will be Due')
        ->not->toContain('Done Checklist');
});

it('resolves the checklist for each request separately when one signatory serves several requests', function () {
    $signatory = schedEmployee(['email' => 'shared@example.test']);
    $requestA = schedRequest();
    $requestB = schedRequest(['last_working_day' => '2026-11-07']);
    schedApprover($requestA, schedChecklist('IT Clearance Checklist'), $signatory);
    schedApprover($requestB, schedChecklist('Finance Checklist'), $signatory, ['due_at' => '2026-11-07 23:59:59']);
    schedSchedule(['days' => 7], dailyReminderTemplate());

    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();
    expect(sentHtmlTo('shared@example.test'))->toContain('IT Clearance Checklist')->not->toContain('Finance Checklist');

    RecordingTransport::reset();
    $this->travelTo(Carbon::parse('2026-10-31 08:00:00'));
    runScheduler();
    expect(sentHtmlTo('shared@example.test'))->toContain('Finance Checklist')->not->toContain('IT Clearance Checklist');
});

it('uses only the schedule\'s selected checklists, the occurrence\'s Clearance Due Date group, and labels General Signatories', function () {
    $request = schedRequest();
    $signatory = schedEmployee(['email' => 'sig@example.test']);
    $selected = schedChecklist('IT Clearance Checklist');
    schedApprover($request, $selected, $signatory);
    schedApprover($request, schedChecklist('HR Checklist'), $signatory);

    $schedule = schedSchedule(['days' => 7], dailyReminderTemplate());
    $schedule->checklistTemplates()->sync([$selected->id]);

    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();
    expect(sentHtmlTo('sig@example.test'))->toContain('your assigned&nbsp;IT Clearance Checklist will be Due')->not->toContain('HR Checklist');

    // Clearance Due Date: LWD + each checklist's own deadline; only that group's checklists.
    RecordingTransport::reset();
    $clearanceRequest = schedRequest();
    $clearanceSignatory = schedEmployee(['email' => 'clear@example.test']);
    schedApprover($clearanceRequest, schedChecklist('Short Deadline', false, 5), $clearanceSignatory);
    schedApprover($clearanceRequest, schedChecklist('Long Deadline', false, 30), $clearanceSignatory);
    schedSchedule(['event' => 'clearance', 'timing' => 'before', 'days' => 1], dailyReminderTemplate());

    $this->travelTo(Carbon::parse('2026-11-04 08:00:00')); // Oct 31 + 5 days - 1
    runScheduler();
    expect(sentHtmlTo('clear@example.test'))->toContain('Short Deadline')->not->toContain('Long Deadline');

    // A General Signatory recipient gets the existing "General Signatory Clearance" label.
    RecordingTransport::reset();
    $gsRequest = schedRequest();
    schedGeneralSignatory($gsRequest, schedEmployee(['email' => 'gs@example.test']));
    schedSchedule(['days' => 7], dailyReminderTemplate());
    $this->travelTo(Carbon::parse('2026-10-24 09:00:00'));
    runScheduler();
    expect(sentHtmlTo('gs@example.test'))->toContain('your assigned&nbsp;General Signatory Clearance will be Due');
});

it('also fills the other checklist/request placeholders and the in-app notification subject', function () {
    $request = schedRequest(['reason' => 'resignation']);
    $signatory = schedEmployee(['name' => 'Ian It', 'email' => 'it@example.test']);
    $user = User::factory()->create(['username' => $signatory->employee_code_digits]);
    schedApprover($request, schedChecklist('IT Clearance Checklist'), $signatory, ['due_at' => '2026-11-02 23:59:59']);

    schedSchedule(['days' => 7, 'channel' => 'email_and_system'], schedTemplate([
        'subject' => 'Reminder: {{checklist_name}} for {{employee_name}}',
        'html_content' => '<p>[{{checklist_name}}|{{due_date}}|{{checklist_status}}|{{clearance_signatory_name}}|{{reason}}|{{request_date}}|{{offboarding_request_id}}|{{department}}]</p>{{pending_items}}',
    ]));

    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();

    $message = collect(RecordingTransport::$messages)->first(fn ($m) => in_array('it@example.test', $m['to'], true));
    expect($message['subject'])->toBe('Reminder: IT Clearance Checklist for Olivia Offboardee')
        ->and($message['html'])->toContain('[IT Clearance Checklist|Nov 02, 2026|')
        ->toContain('|Ian It|Resignation|Oct 01, 2026|'.$request->id.'|')
        ->not->toContain('{{');

    // In-app notification: same subject, placeholders filled for that user.
    expect($user->fresh()->notifications()->first()?->data['message'])->toBe('Reminder: IT Clearance Checklist for Olivia Offboardee');
});

it('CCs a template\'s manually entered recipient addresses on scheduled sends, alongside its employee recipients', function () {
    $request = schedRequest();
    schedApprover($request, schedChecklist('IT Clearance Checklist'), schedEmployee(['email' => 'it@example.test']));
    $ccEmployee = schedEmployee(['email' => 'cc.employee@example.test']);

    $template = schedTemplate(['recipient_emails' => ['payroll@company.test', 'personal.address@gmail.test']]);
    $template->recipientEmployees()->sync([$ccEmployee->id]);
    schedSchedule(['days' => 7], $template);

    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();

    $message = collect(RecordingTransport::$messages)->first(fn ($m) => in_array('it@example.test', $m['to'], true));
    expect($message)->not->toBeNull()
        ->and($message['cc'])->toEqualCanonicalizing(['cc.employee@example.test', 'payroll@company.test', 'personal.address@gmail.test'])
        // Every CC address really reached the transport's envelope.
        ->and($message['envelope'])->toContain('payroll@company.test')->toContain('personal.address@gmail.test');
});

it('records each scheduled email in the email notification log as Scheduled, with the schedule name and intended date', function () {
    $request = schedRequest(['last_working_day' => '2026-10-31']);
    schedApprover($request, schedChecklist('IT'), schedEmployee(['email' => 'it@example.test']));
    $schedule = schedSchedule(['timing' => 'before', 'days' => 7], schedTemplate(['template_name' => 'Audit Reminder']));

    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();

    $log = \App\Models\EmailLog::sole();
    expect($log->status)->toBe('sent')
        ->and($log->notification_type)->toBe('scheduled')
        ->and($log->template_name)->toBe('Audit Reminder')
        ->and($log->schedule_name)->toBe('Audit Reminder — 7 days Before Last Working Day')
        ->and($log->scheduled_for->toDateString())->toBe('2026-10-24')
        ->and($log->to_recipients)->toBe(['it@example.test'])
        ->and($log->offboarding_request_id)->toBe($request->id)
        ->and($log->offboardee_name)->toBe('Olivia Offboardee')
        ->and($log->trigger_source)->toBe('console')
        ->and($log->triggered_by_user_id)->toBeNull();
});

it('records a scheduled email that the mail server rejects as Failed, with the reason', function () {
    $request = schedRequest(['last_working_day' => '2026-10-31']);
    schedApprover($request, schedChecklist('IT'), schedEmployee(['email' => 'it@example.test']));
    schedSchedule(['timing' => 'before', 'days' => 7]);
    RecordingTransport::$rejecting = ['it@example.test'];

    $this->travelTo(Carbon::parse('2026-10-24 08:00:00'));
    runScheduler();

    $log = \App\Models\EmailLog::sole();
    expect($log->status)->toBe('failed')
        ->and($log->notification_type)->toBe('scheduled')
        ->and($log->error_message)->toContain('Recipient address rejected');
});
