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

function schedChecklist(string $title, bool $finalPay = false): ChecklistTemplate
{
    return ChecklistTemplate::forceCreate(['title' => $title, 'is_final_pay_checklist' => $finalPay]);
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

it('uses the schedule\'s own configured clearance signing deadline, not a hardcoded one', function () {
    $request = schedRequest(['last_working_day' => '2026-10-31']);
    $dispatcher = app(EmailTemplateScheduleDispatcher::class);

    expect($dispatcher->computeScheduledDate(schedSchedule(['event' => 'clearance', 'timing' => 'before', 'days' => 2, 'clearance_signing_deadline_days' => 10]), $request)->toDateString())->toBe('2026-11-08')
        ->and($dispatcher->computeScheduledDate(schedSchedule(['event' => 'clearance', 'timing' => 'before', 'days' => 2, 'clearance_signing_deadline_days' => 19]), $request)->toDateString())->toBe('2026-11-17');
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
        'app:send-email-template-schedules' => '0 8 * * *',
        'app:send-scheduled-checklist-item-notifications' => '10 8 * * *',
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
