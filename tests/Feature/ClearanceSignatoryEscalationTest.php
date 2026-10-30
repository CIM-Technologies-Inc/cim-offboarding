<?php

use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\ActivityLog;
use App\Models\ChecklistItemAssignment;
use App\Models\ChecklistItemProgress;
use App\Models\ChecklistSignatoryAssignment;
use App\Models\ChecklistTemplate;
use App\Models\EmailTemplateSchedule;
use App\Models\Employee;
use App\Models\EmployeeGroup;
use App\Models\FinalApprover;
use App\Models\GeneralSignatory;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use App\Models\SeparationType;
use App\Models\User;
use App\Services\ClearanceSignatoryEscalationService;
use App\Services\EmailTemplateScheduleDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\Support\RecordingTransport;

/*
 * Secondary Clearance Signatory, automatic escalation, and HR/Admin
 * reassignment — end to end through the real request-creation flow, the
 * `app:escalate-clearance-signatories` command, and the HR/Admin routes.
 * Time is controlled with travelTo(); nothing waits for real deadlines.
 *
 *   DB_CONNECTION=mysql DB_DATABASE=cim_db_test vendor/bin/pest tests/Feature/ClearanceSignatoryEscalationTest.php
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    $this->travelTo(Carbon::parse('2026-10-01 09:00:00'));

    ChecklistTemplate::query()->update(['is_active' => false]);
    GeneralSignatory::query()->update(['is_active' => false]);
    FinalApprover::query()->update(['is_active' => false]);
    FinalApprover::forceCreate(['employee_id' => Employee::factory()->create()->id, 'is_active' => true]);

    $this->admin = escUser(Employee::factory()->create(['name' => 'Hera HrAdmin']), User::ROLE_ADMIN, 'hr.admin@example.com');

    $this->primary = Employee::factory()->create(['name' => 'Paula Primary', 'email' => 'primary@example.com']);
    $this->secondary = Employee::factory()->create(['name' => 'Sam Secondary', 'email' => 'secondary@example.com']);
    $this->other = Employee::factory()->create(['name' => 'Olga Other', 'email' => 'other@example.com']);
    $this->taskAssignee = Employee::factory()->create(['name' => 'Tom Task', 'email' => 'task@example.com']);

    $this->template = ChecklistTemplate::forceCreate([
        'title' => 'IT Clearance Checklist',
        'department_head_id' => $this->primary->id,
        'is_active' => true, 'is_final_pay_checklist' => false, 'use_task_assignee_as_signatory' => false,
        'sequence_type' => 'primary', 'due_in_days' => 30,
        'secondary_approver_enabled' => true,
        'secondary_signatory_employee_id' => $this->secondary->id,
        'escalation_enabled' => true, 'escalation_days' => 10, 'escalation_day_type' => 'calendar', 'escalation_notify_hr' => true,
    ]);
    $this->laptop = $this->template->items()->create(['title' => 'Laptop Return', 'signatory_id' => $this->taskAssignee->id, 'sort_order' => 1]);
    $this->access = $this->template->items()->create(['title' => 'System Access Revocation', 'sort_order' => 2]);

    $this->offboardee = Employee::factory()->create(['name' => 'Olivia Offboardee']);
});

function escUser(Employee $employee, string $role, ?string $email = null): User
{
    $user = User::factory()->create(['name' => $employee->name, 'username' => $employee->employee_code_digits, 'email' => $email ?? $employee->email]);
    $user->forceFill(['must_change_password' => false])->save();
    $user->assignRole($role);

    return $user;
}

function escSubmit($test, ?Employee $offboardee = null): OffboardingRequestApprover
{
    $offboardee ??= $test->offboardee;

    $test->actingAs($test->admin)->post(route('offboarding-requests.store'), [
        'employee_id' => $offboardee->id,
        'notice_date' => now()->toDateString(),
        'last_working_day' => now()->addDays(14)->toDateString(),
        'separation_type_id' => SeparationType::value('id'),
    ])->assertSessionHasNoErrors();

    $request = OffboardingRequest::where('employee_id', $offboardee->id)->latest('id')->firstOrFail();

    return OffboardingRequestApprover::where('offboarding_request_id', $request->id)->where('checklist_template_id', $test->template->id)->firstOrFail();
}

function escRun(): void
{
    Artisan::call('app:escalate-clearance-signatories');
}

function escMailsTo(string $email, ?string $subjectContains = null)
{
    return Mail::queued(ChecklistSignatoryAnnouncementMail::class, fn ($mail) => $mail->hasTo($email)
        && ($subjectContains === null || str_contains($mail->emailSubject, $subjectContains)));
}

function escInApp(User $user)
{
    return $user->fresh()->notifications()->where('type', 'clearance_signatory_escalation')->get();
}

function escReassign($test, OffboardingRequestApprover $approver, Employee $employee, array $overrides = [])
{
    $approver->refresh();

    return $test->actingAs($test->admin)->postJson(route('clearance-signatory-escalations.reassign', $approver), array_merge([
        'employee_id' => $employee->id,
        'reason' => 'Secondary on leave.',
        'expected_employee_id' => $approver->employee_id,
        'expected_escalation_status' => $approver->escalation_status,
    ], $overrides));
}

// ---------------------------------------------------------------------
// Initial assignment
// ---------------------------------------------------------------------

it('assigns the primary first, snapshots the settings, and starts the deadline from the actual assignment time', function () {
    $approver = escSubmit($this);

    expect($approver->employee_id)->toBe($this->primary->id)
        ->and($approver->primary_signatory_employee_id)->toBe($this->primary->id)
        ->and($approver->secondary_signatory_employee_id)->toBe($this->secondary->id)
        ->and($approver->escalation_status)->toBe('assigned_to_primary')
        ->and($approver->signatory_assigned_at->toDateTimeString())->toBe('2026-10-01 09:00:00')
        ->and($approver->signatory_deadline_at->toDateTimeString())->toBe('2026-10-11 09:00:00')
        ->and($approver->primary_deadline_at->toDateTimeString())->toBe('2026-10-11 09:00:00')
        // The checklist's own due date is separate and unchanged.
        ->and($approver->due_at->toDateString())->toBe('2026-11-14')
        ->and($approver->signatoryAssignments()->sole()->event)->toBe('initial_assignment');
});

// ---------------------------------------------------------------------
// 1 / 14. Completed before (or right at) the deadline — no escalation
// ---------------------------------------------------------------------

it('does not escalate before the deadline, nor a checklist completed at the deadline', function () {
    $approver = escSubmit($this);

    $this->travelTo(Carbon::parse('2026-10-11 08:59:00'));
    escRun();
    expect($approver->fresh()->employee_id)->toBe($this->primary->id);

    // Completed right at the deadline moment, before the scheduler's next run.
    $this->travelTo(Carbon::parse('2026-10-11 09:00:00'));
    $approver->update(['status' => 'approved', 'approved_at' => now()]);
    escRun();

    expect($approver->fresh()->employee_id)->toBe($this->primary->id)
        ->and($approver->fresh()->assignmentState())->toBe('completed')
        ->and(ChecklistSignatoryAssignment::where('event', 'escalated_to_secondary')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------
// 2 / 3 / 17. First-level escalation
// ---------------------------------------------------------------------

it('escalates to the secondary when the primary misses the deadline, with a new period, notifications and history', function () {
    $approver = escSubmit($this);
    $taskAssignmentsBefore = ChecklistItemAssignment::where('offboarding_request_approver_id', $approver->id)->get(['id', 'checklist_item_id', 'assigned_employee_id', 'status'])->toArray();

    $this->travelTo(Carbon::parse('2026-10-11 09:03:00'));
    escRun();
    $approver->refresh();

    expect($approver->employee_id)->toBe($this->secondary->id)
        ->and($approver->escalation_status)->toBe('escalated_to_secondary')
        ->and($approver->escalated_at->toDateTimeString())->toBe('2026-10-11 09:03:00')
        ->and($approver->secondary_assigned_at->toDateTimeString())->toBe('2026-10-11 09:03:00')
        ->and($approver->secondary_deadline_at->toDateTimeString())->toBe('2026-10-21 09:03:00')
        ->and($approver->signatory_deadline_at->toDateTimeString())->toBe('2026-10-21 09:03:00')
        // Original primary deadline and overall due date preserved.
        ->and($approver->primary_deadline_at->toDateTimeString())->toBe('2026-10-11 09:00:00')
        ->and($approver->due_at->toDateString())->toBe('2026-11-14')
        // Individual task assignments untouched.
        ->and(ChecklistItemAssignment::where('offboarding_request_approver_id', $approver->id)->get(['id', 'checklist_item_id', 'assigned_employee_id', 'status'])->toArray())->toBe($taskAssignmentsBefore);

    $event = $approver->signatoryAssignments()->where('event', 'escalated_to_secondary')->sole();
    expect($event->previous_employee_id)->toBe($this->primary->id)
        ->and($event->new_employee_id)->toBe($this->secondary->id)
        ->and($event->trigger)->toBe('scheduler')
        ->and($event->reason)->toContain('did not complete the checklist')
        ->and($event->notification_status)->toBe('sent')
        ->and(ActivityLog::where('action', 'clearance_signatory_escalated')->where('subject_id', $approver->id)->exists())->toBeTrue();

    // Secondary: email (queued) explaining the transfer + in-app.
    $secondaryMail = escMailsTo('secondary@example.com', 'now the Clearance Signatory')->sole();
    expect($secondaryMail->emailBody)->toContain('Paula Primary')
        ->toContain('IT Clearance Checklist')
        ->toContain('Olivia Offboardee')
        ->toContain('Oct 21, 2026 9:03 AM')
        ->toContain('did not complete the checklist within the configured period')
        ->toContain('Laptop Return')
        ->not->toContain('{{');
    expect(escInApp(User::firstWhere('username', $this->secondary->employee_code_digits)))->toHaveCount(1);

    // Previous primary told; HR/Admin notified (setting on).
    expect(escMailsTo('primary@example.com', 'transferred'))->toHaveCount(1)
        ->and(escMailsTo('hr.admin@example.com', 'escalated'))->toHaveCount(1)
        ->and(escInApp($this->admin))->toHaveCount(1);
});

it('sends HR/Admin straight to manual action if the secondary is no longer eligible at escalation time', function () {
    $approver = escSubmit($this);
    $this->secondary->update(['status' => 'offboarded']);

    $this->travelTo(Carbon::parse('2026-10-12 09:00:00'));
    escRun();

    expect($approver->fresh()->employee_id)->toBe($this->primary->id)
        ->and($approver->fresh()->escalation_status)->toBe('hr_action_required')
        ->and($approver->fresh()->escalation_note)->toContain('not an active employee')
        ->and(escMailsTo('hr.admin@example.com', 'HR/Admin action required'))->toHaveCount(1);
});

// ---------------------------------------------------------------------
// 12. Idempotency
// ---------------------------------------------------------------------

it('never escalates or alerts twice when the scheduler runs repeatedly', function () {
    $approver = escSubmit($this);

    $this->travelTo(Carbon::parse('2026-10-11 09:05:00'));
    escRun();
    escRun();
    escRun();

    expect($approver->signatoryAssignments()->where('event', 'escalated_to_secondary')->count())->toBe(1)
        ->and(escMailsTo('secondary@example.com'))->toHaveCount(1);

    $this->travelTo(Carbon::parse('2026-10-22 09:05:00'));
    escRun();
    escRun();

    expect($approver->signatoryAssignments()->where('event', 'hr_action_required')->count())->toBe(1)
        ->and(escMailsTo('hr.admin@example.com', 'HR/Admin action required'))->toHaveCount(1)
        ->and(escInApp($this->admin)->count())->toBe(2); // first-level notice + one action-required alert
});

// ---------------------------------------------------------------------
// 4 / 5 / 6. Secondary completes, or misses → HR/Admin
// ---------------------------------------------------------------------

it('does not alert HR/Admin when the secondary completes in time', function () {
    $approver = escSubmit($this);
    $this->travelTo(Carbon::parse('2026-10-11 09:05:00'));
    escRun();

    $approver->refresh()->update(['status' => 'approved', 'approved_at' => now()]);
    $this->travelTo(Carbon::parse('2026-10-25 09:00:00'));
    escRun();

    expect($approver->fresh()->escalation_status)->toBe('escalated_to_secondary')
        ->and($approver->fresh()->assignmentState())->toBe('completed')
        ->and(escMailsTo('hr.admin@example.com', 'HR/Admin action required'))->toHaveCount(0);
});

it('requires HR/Admin when the secondary also misses the deadline, and stops treating them as the signatory', function () {
    $approver = escSubmit($this);
    $this->travelTo(Carbon::parse('2026-10-11 09:05:00'));
    escRun();
    $this->travelTo(Carbon::parse('2026-10-21 09:05:00'));
    escRun();
    $approver->refresh();

    expect($approver->escalation_status)->toBe('hr_action_required')
        ->and($approver->secondary_failed_at->toDateTimeString())->toBe('2026-10-21 09:05:00')
        ->and($approver->signatory_deadline_at)->toBeNull()
        ->and($approver->employee_id)->toBe($this->secondary->id) // kept for history, not active
        ->and($approver->activeClearanceSignatory())->toBeNull()
        ->and($approver->assignmentStateLabel())->toBe('Escalated to HR/Admin — Reassignment Required');

    $hrMail = escMailsTo('hr.admin@example.com', 'HR/Admin action required')->sole();
    expect($hrMail->emailBody)->toContain('Paula Primary')->toContain('Sam Secondary')
        ->toContain('Oct 11, 2026 9:00 AM')->toContain('Oct 21, 2026 9:05 AM')
        ->toContain('Assign New Clearance Signatory')->not->toContain('{{');

    // No automatic reassignment, ever, while waiting.
    $this->travelTo(Carbon::parse('2026-11-30 09:00:00'));
    escRun();
    expect($approver->fresh()->employee_id)->toBe($this->secondary->id)
        ->and($approver->fresh()->escalation_status)->toBe('hr_action_required');

    // The expired secondary can't act; reminders no longer go to them.
    $secondaryUser = User::firstWhere('username', $this->secondary->employee_code_digits);
    $secondaryUser->forceFill(['must_change_password' => false])->save();
    $this->actingAs($secondaryUser)->post(route('approvals.approve', $approver))->assertForbidden();
    expect($approver->fresh()->reminderRecipients()->pluck('id')->all())->not->toContain($this->secondary->id);

    // HR/Admin sees it in the escalation panel data.
    $this->actingAs($this->admin)->getJson(route('clearance-signatory-escalations.index'))->assertOk()
        ->assertJsonPath('escalations.0.id', $approver->id)
        ->assertJsonPath('escalations.0.awaitingHr', true)
        ->assertJsonPath('escalations.0.canReassign', true);
});

// ---------------------------------------------------------------------
// 7 / 8 / 9 / 10 / 17 / 19. HR/Admin reassignment
// ---------------------------------------------------------------------

function escToHrAction($test): OffboardingRequestApprover
{
    $approver = escSubmit($test);
    $test->travelTo(Carbon::parse('2026-10-11 09:05:00'));
    escRun();
    $test->travelTo(Carbon::parse('2026-10-21 09:05:00'));
    escRun();

    return $approver->fresh();
}

it('lets HR/Admin assign the checklist back to the original primary as a NEW assignment with a new deadline', function () {
    $approver = escToHrAction($this);
    // Work already done is preserved through reassignment.
    ChecklistItemProgress::create(['offboarding_request_approver_id' => $approver->id, 'checklist_item_id' => $this->laptop->id, 'is_checked' => true, 'remark' => 'Returned', 'checked_at' => now()]);
    $escalationHistory = $approver->signatoryAssignments()->where('event', 'escalated_to_secondary')->sole()->only(['previous_employee_id', 'new_employee_id', 'notifications']);

    $this->travelTo(Carbon::parse('2026-10-23 14:00:00'));
    escReassign($this, $approver, $this->primary)->assertOk()->assertJsonPath('escalation.state', 'reassigned_by_hr');
    $approver->refresh();

    expect($approver->employee_id)->toBe($this->primary->id)
        ->and($approver->escalation_status)->toBe('reassigned_by_hr')
        ->and($approver->signatory_assigned_at->toDateTimeString())->toBe('2026-10-23 14:00:00')
        ->and($approver->signatory_deadline_at->toDateTimeString())->toBe('2026-11-02 14:00:00')
        ->and($approver->primary_deadline_at->toDateTimeString())->toBe('2026-10-11 09:00:00')
        ->and($approver->hr_reassigned_by_user_id)->toBe($this->admin->id)
        ->and($approver->hr_reassignment_reason)->toBe('Secondary on leave.')
        ->and($approver->due_at->toDateString())->toBe('2026-11-14')
        ->and($approver->itemProgress()->where('checklist_item_id', $this->laptop->id)->value('remark'))->toBe('Returned')
        ->and(ChecklistItemAssignment::where('offboarding_request_approver_id', $approver->id)->where('checklist_item_id', $this->laptop->id)->where('status', 'active')->value('assigned_employee_id'))->toBe($this->taskAssignee->id);

    // Earlier history is never rewritten.
    expect($approver->signatoryAssignments()->where('event', 'escalated_to_secondary')->sole()->only(['previous_employee_id', 'new_employee_id', 'notifications']))->toBe($escalationHistory)
        ->and($approver->signatoryAssignments()->pluck('event')->all())->toBe(['initial_assignment', 'escalated_to_secondary', 'hr_action_required', 'reassigned_by_hr']);

    $mail = escMailsTo('primary@example.com', 'You have been assigned as Clearance Signatory')->sole();
    expect($mail->emailBody)->toContain('Hera HrAdmin')->toContain('Secondary on leave.')->toContain('Nov 02, 2026 2:00 PM')->not->toContain('{{');
    expect(ActivityLog::where('action', 'clearance_signatory_reassigned_by_hr')->exists())->toBeTrue();

    // Monitoring resumes: if this assignment also lapses, HR/Admin is asked again — never auto-reassigned.
    $this->travelTo(Carbon::parse('2026-11-02 14:05:00'));
    escRun();
    expect($approver->fresh()->escalation_status)->toBe('hr_action_required')
        ->and($approver->fresh()->employee_id)->toBe($this->primary->id);
});

it('lets HR/Admin assign a different eligible employee, who becomes the active signatory for future notifications', function () {
    $approver = escToHrAction($this);

    escReassign($this, $approver, $this->other)->assertOk();
    $approver->refresh();

    expect($approver->employee_id)->toBe($this->other->id)
        ->and($approver->activeClearanceSignatory()->id)->toBe($this->other->id)
        ->and($approver->reminderRecipients()->pluck('id')->all())->toContain($this->other->id)
        ->and(escMailsTo('other@example.com', 'You have been assigned as Clearance Signatory'))->toHaveCount(1)
        ->and(escInApp(User::firstWhere('username', $this->other->employee_code_digits)))->toHaveCount(1);

    // Scheduled Email & Notification templates now resolve the new signatory.
    $schedule = EmailTemplateSchedule::forceCreate(['email_template_id' => \App\Models\EmailTemplate::value('id'), 'event' => 'last_working_day', 'timing' => 'before', 'days' => 1, 'channel' => 'email', 'is_active' => true]);
    $request = $approver->offboardingRequest()->with(['approvers.employee', 'generalSignatoryApprovals'])->first();
    expect(app(EmailTemplateScheduleDispatcher::class)->resolveRecipients($schedule, $request)->pluck('id')->all())->toContain($this->other->id)
        ->not->toContain($this->secondary->id);

    // The new signatory can act; the previous one can't.
    $otherUser = User::firstWhere('username', $this->other->employee_code_digits);
    $otherUser->forceFill(['must_change_password' => false])->save();
    $this->actingAs($otherUser)->postJson(route('approvals.save-progress', $approver), ['items' => []])->assertSuccessful();
});

it('validates the replacement employee and protects the action with permissions', function () {
    $approver = escToHrAction($this);

    $inactive = Employee::factory()->create(['status' => 'offboarded']);
    escReassign($this, $approver, $inactive)->assertStatus(422)->assertJsonPath('errors.employee_id.0', fn ($m) => str_contains($m, 'not an active employee'));

    escReassign($this, $approver, $approver->offboardingRequest->employee)->assertStatus(422);

    $noAccess = Employee::factory()->create();
    escUser($noAccess, 'employee');
    escReassign($this, $approver, $noAccess)->assertStatus(422)->assertJsonPath('errors.employee_id.0', fn ($m) => str_contains($m, 'Approvals access'));

    escReassign($this, $approver, $this->other, ['reason' => ''])->assertStatus(422)->assertJsonValidationErrors(['reason']);

    $approverUser = escUser(Employee::factory()->create(), User::ROLE_APPROVER);
    $this->actingAs($approverUser)->postJson(route('clearance-signatory-escalations.reassign', $approver), ['employee_id' => $this->other->id, 'reason' => 'x'])->assertForbidden();
    $this->actingAs($approverUser)->getJson(route('clearance-signatory-escalations.index'))->assertForbidden();

    expect($approver->fresh()->escalation_status)->toBe('hr_action_required');
});

// ---------------------------------------------------------------------
// 11. Completed / cancelled never escalate
// ---------------------------------------------------------------------

it('never escalates a checklist on a cancelled request', function () {
    $approver = escSubmit($this);
    $approver->offboardingRequest->update(['status' => 'cancelled']);

    $this->travelTo(Carbon::parse('2026-10-15 09:00:00'));
    escRun();

    expect($approver->fresh()->employee_id)->toBe($this->primary->id)
        ->and($approver->fresh()->assignmentState())->toBe('cancelled');
});

// ---------------------------------------------------------------------
// 13. Scheduler vs. manual reassignment
// ---------------------------------------------------------------------

it('lets only one of a manual reassignment and a scheduler escalation win', function () {
    $approver = escSubmit($this);
    $second = escSubmit($this, Employee::factory()->create());
    // A reassignment form opened now (before any escalation)…
    $stale = ['expected_employee_id' => $second->employee_id, 'expected_escalation_status' => $second->escalation_status];

    // HR/Admin reassigns (from a page loaded before the deadline) just
    // after the deadline passed but before the scheduler ran…
    $this->travelTo(Carbon::parse('2026-10-11 09:01:00'));
    escReassign($this, $approver, $this->other)->assertOk();

    // …so the scheduler's pending escalation is a no-op.
    expect(app(ClearanceSignatoryEscalationService::class)->processOne($approver->id))->toBeNull();
    escRun();
    expect($approver->fresh()->employee_id)->toBe($this->other->id)
        ->and($approver->signatoryAssignments()->where('event', 'escalated_to_secondary')->exists())->toBeFalse();

    // …and the reverse: the scheduler escalated the second checklist, so
    // the form opened before that is refused instead of overwriting it.
    $this->actingAs($this->admin)->postJson(route('clearance-signatory-escalations.reassign', $second), ['employee_id' => $this->other->id, 'reason' => 'x'] + $stale)
        ->assertStatus(409);
    expect($second->fresh()->employee_id)->toBe($this->secondary->id);
});

// ---------------------------------------------------------------------
// 16. Template changes don't affect existing requests
// ---------------------------------------------------------------------

it('keeps the snapshot when the template is edited while a request is active', function () {
    $approver = escSubmit($this);

    $this->template->update(['secondary_signatory_employee_id' => $this->other->id, 'escalation_days' => 2, 'escalation_enabled' => false]);

    $this->travelTo(Carbon::parse('2026-10-11 09:05:00'));
    escRun();

    expect($approver->fresh()->employee_id)->toBe($this->secondary->id)
        ->and($approver->fresh()->secondary_deadline_at->toDateTimeString())->toBe('2026-10-21 09:05:00');
});

// ---------------------------------------------------------------------
// 18. Email failure is retried without repeating the transfer
// ---------------------------------------------------------------------

it('retries failed escalation emails without repeating the transfer', function () {
    $approver = escSubmit($this);

    // Real mail pipeline (undo beforeEach's Mail::fake()) with a transport that is down.
    app()->forgetInstance('mail.manager');
    Mail::clearResolvedInstance('mail.manager');
    RecordingTransport::reset();
    Mail::extend('recording', fn () => new RecordingTransport);
    config(['mail.mailers.recording' => ['transport' => 'recording'], 'mail.default' => 'recording']);
    Mail::forgetMailers();
    RecordingTransport::$down = true;

    $this->travelTo(Carbon::parse('2026-10-11 09:05:00'));
    escRun();

    $event = $approver->signatoryAssignments()->where('event', 'escalated_to_secondary')->sole();
    expect($approver->fresh()->employee_id)->toBe($this->secondary->id)
        ->and($event->notification_status)->toBe('failed')
        ->and(collect($event->notifications)->pluck('email_status')->unique()->all())->toBe(['failed']);

    RecordingTransport::$down = false;
    escRun();

    $event->refresh();
    expect($event->notification_status)->toBe('sent')
        ->and($approver->signatoryAssignments()->where('event', 'escalated_to_secondary')->count())->toBe(1)
        ->and(RecordingTransport::toAddresses())->toContain('secondary@example.com')
        // In-app notifications were created once, not again on retry.
        ->and(escInApp(User::firstWhere('username', $this->secondary->employee_code_digits)))->toHaveCount(1);
});

// ---------------------------------------------------------------------
// 22. Working days
// ---------------------------------------------------------------------

it('counts working days skipping weekends and configured holidays', function () {
    $service = app(ClearanceSignatoryEscalationService::class);
    $friday = Carbon::parse('2026-10-02 09:00:00');

    expect($service->computeDeadline($friday, 2, 'working')->toDateTimeString())->toBe('2026-10-06 09:00:00')
        ->and($service->computeDeadline($friday, 2, 'calendar')->toDateTimeString())->toBe('2026-10-04 09:00:00');

    config(['offboarding.escalation_holidays' => ['2026-10-05']]);
    expect($service->computeDeadline($friday, 2, 'working')->toDateTimeString())->toBe('2026-10-07 09:00:00');
});

// ---------------------------------------------------------------------
// Configuration (section 2) + 15. invalid secondary
// ---------------------------------------------------------------------

it('validates the Secondary Clearance Signatory & Escalation Settings on the checklist form', function () {
    $group = EmployeeGroup::create(['name' => 'IT', 'group_head_employee_id' => $this->primary->id, 'is_active' => true]);
    $base = ['title' => 'New IT', 'employee_group_id' => $group->id, 'checklist_classification' => 'primary', 'due_in_days' => '', 'clearance_signing_deadline_days' => 19, 'items' => [['title' => 'Task']], 'secondary_approver_enabled' => 1];
    $post = fn (array $data) => $this->actingAs($this->admin)->post(route('checklist-templates.store'), $base + $data);

    $this->actingAs($this->admin)->get(route('checklist-templates.create'))->assertOk()->assertSee('Secondary Clearance Signatory &amp; Escalation Settings', false);

    $post(['secondary_signatory_employee_id' => $this->primary->id, 'escalation_enabled' => 1, 'escalation_days' => 10])->assertSessionHasErrors('secondary_signatory_employee_id');
    $post(['escalation_enabled' => 1, 'escalation_days' => 10])->assertSessionHasErrors('secondary_signatory_employee_id');
    $post(['secondary_signatory_employee_id' => Employee::factory()->create(['status' => 'offboarded'])->id, 'escalation_enabled' => 1, 'escalation_days' => 10])->assertSessionHasErrors('secondary_signatory_employee_id');
    $post(['secondary_signatory_employee_id' => 999999, 'escalation_enabled' => 1, 'escalation_days' => 10])->assertSessionHasErrors('secondary_signatory_employee_id');
    $post(['secondary_signatory_employee_id' => $this->secondary->id, 'escalation_enabled' => 1, 'escalation_days' => 0])->assertSessionHasErrors('escalation_days');
    $post(['secondary_signatory_employee_id' => $this->secondary->id, 'escalation_enabled' => 1, 'escalation_days' => 400])->assertSessionHasErrors('escalation_days');
    $post(['secondary_signatory_employee_id' => $this->secondary->id, 'escalation_enabled' => 1])->assertSessionHasErrors('escalation_days');
    $this->actingAs($this->admin)->post(route('checklist-templates.store'), ['title' => 'Final Pay', 'checklist_classification' => 'final_pay', 'due_in_days' => '', 'clearance_signing_deadline_days' => '', 'items' => [['title' => 'x']], 'secondary_approver_enabled' => 1, 'secondary_signatory_employee_id' => $this->secondary->id, 'escalation_enabled' => 1, 'escalation_days' => 5])
        ->assertSessionHasErrors('secondary_approver_enabled');

    $post(['secondary_signatory_employee_id' => $this->secondary->id, 'escalation_enabled' => 1, 'escalation_days' => 7, 'escalation_day_type' => 'working', 'escalation_notify_hr' => 0])
        ->assertSessionHasNoErrors()->assertRedirect(route('checklist-templates.index'));

    $saved = ChecklistTemplate::where('title', 'New IT')->sole();
    expect($saved->only(['secondary_approver_enabled', 'secondary_signatory_employee_id', 'escalation_enabled', 'escalation_days', 'escalation_day_type', 'escalation_notify_hr']))
        ->toBe(['secondary_approver_enabled' => true, 'secondary_signatory_employee_id' => $this->secondary->id, 'escalation_enabled' => true, 'escalation_days' => 7, 'escalation_day_type' => 'working', 'escalation_notify_hr' => false]);

    // Disabled escalation: the existing workflow continues, nothing is monitored.
    $this->template->update(['escalation_enabled' => false]);
    $approver = escSubmit($this);
    expect($approver->escalation_enabled)->toBeFalse()
        ->and($approver->signatory_deadline_at)->toBeNull();
    $this->travelTo(Carbon::parse('2026-12-01 09:00:00'));
    escRun();
    expect($approver->fresh()->employee_id)->toBe($this->primary->id);
});

// ---------------------------------------------------------------------
// 20 / 21. Unrelated workflows untouched
// ---------------------------------------------------------------------

it('leaves headless (Task Assignee) checklists and pre-existing requests untracked', function () {
    $headless = ChecklistTemplate::forceCreate(['title' => 'Final Pay Checklist', 'is_active' => true, 'is_final_pay_checklist' => true, 'use_task_assignee_as_signatory' => true]);
    $request = escSubmit($this)->offboardingRequest;

    // A row created before this feature (no snapshot) is never escalated.
    $legacy = OffboardingRequestApprover::forceCreate(['offboarding_request_id' => $request->id, 'checklist_template_id' => $headless->id, 'employee_id' => $this->other->id, 'status' => 'pending', 'assigned_at' => now()]);

    $this->travelTo(Carbon::parse('2026-12-01 09:00:00'));
    escRun();

    expect($legacy->fresh()->employee_id)->toBe($this->other->id)
        ->and($legacy->fresh()->escalationSummary())->toBeNull();
});

// ---------------------------------------------------------------------
// 23. Approvals page UI (panel + card badge) and its permission gate
// ---------------------------------------------------------------------

it('shows the escalation panel to HR/Admin only and the escalation badge on the card', function () {
    $approver = escSubmit($this);
    $this->travelTo(Carbon::parse('2026-10-11 09:05:00'));
    escRun();

    $this->actingAs($this->admin)->get(route('approvals.index'))->assertOk()
        ->assertSee('clearanceEscalations()', false)
        ->assertSee('Escalated to Secondary')
        ->assertSee('Active signatory: Sam Secondary');

    $secondaryUser = User::firstWhere('username', $this->secondary->employee_code_digits);
    $secondaryUser->forceFill(['must_change_password' => false])->save();

    $this->actingAs($secondaryUser)->get(route('approvals.index'))->assertOk()
        ->assertDontSee('clearanceEscalations()', false)
        ->assertSee('IT Clearance Checklist');
});

// ---------------------------------------------------------------------
// The five escalation email templates are System Default templates
// ---------------------------------------------------------------------

it('treats every escalation email template as a protected System Default template', function () {
    $names = [
        ClearanceSignatoryEscalationService::TEMPLATE_ESCALATED_TO_YOU,
        ClearanceSignatoryEscalationService::TEMPLATE_TRANSFERRED_AWAY,
        ClearanceSignatoryEscalationService::TEMPLATE_HR_NOTICE,
        ClearanceSignatoryEscalationService::TEMPLATE_HR_ACTION_REQUIRED,
        ClearanceSignatoryEscalationService::TEMPLATE_HR_ASSIGNED,
    ];

    foreach ($names as $name) {
        $template = \App\Models\EmailTemplate::where('template_name', $name)->sole();

        expect(\App\Models\EmailTemplate::SYSTEM_TEMPLATE_NAMES)->toContain($name)
            ->and($template->isSystemDefault())->toBeTrue()
            ->and(\App\Http\Controllers\EmailTemplateController::templateMetadataFor($template)['trigger'])->not->toContain('wherever an admin selects it');

        // Can't be deleted…
        $this->actingAs($this->admin)->delete(route('email-templates.destroy', $template))->assertRedirect();
        expect(\App\Models\EmailTemplate::whereKey($template->id)->exists())->toBeTrue();

        // …or renamed (the subject/body stay editable).
        $this->actingAs($this->admin)->put(route('email-templates.update', $template), [
            'template_name' => 'Renamed '.$template->id,
            'subject' => 'Edited subject {{checklist_name}}',
            'html_content' => $template->html_content,
            'is_active' => 1,
        ]);
        expect($template->fresh()->template_name)->toBe($name)
            ->and($template->fresh()->subject)->toBe('Edited subject {{checklist_name}}');
    }

    // Listed under the System Default tab, and a custom template can't take one of the names.
    $this->actingAs($this->admin)->get(route('email-templates.index'))->assertOk()->assertSee($names[0]);
    $this->actingAs($this->admin)->post(route('email-templates.store'), ['template_name' => $names[3], 'subject' => 'x', 'html_content' => '<p>x</p>'])
        ->assertSessionHasErrors('template_name');
});

// ---------------------------------------------------------------------
// "Enable Secondary Approver" master switch
// ---------------------------------------------------------------------

it('saves a new template with Enable Secondary Approver unchecked, ignoring the hidden settings', function () {
    $this->actingAs($this->admin)->get(route('checklist-templates.create'))->assertOk()
        ->assertSee('Enable Secondary Approver')
        ->assertSee('name="secondary_approver_enabled"', false);

    // Hidden-section values (even invalid ones) are neither validated nor saved while unchecked.
    $this->actingAs($this->admin)->post(route('checklist-templates.store'), [
        'title' => 'No Backup', 'employee_group_id' => '', 'checklist_classification' => 'primary', 'due_in_days' => '', 'clearance_signing_deadline_days' => 19,
        'items' => [['title' => 'Task']],
        'secondary_approver_enabled' => 0,
        'secondary_signatory_employee_id' => $this->secondary->id, 'escalation_enabled' => 1, 'escalation_days' => 0,
    ])->assertSessionHasNoErrors()->assertRedirect(route('checklist-templates.index'));

    expect(ChecklistTemplate::where('title', 'No Backup')->sole()->only(['secondary_approver_enabled', 'secondary_signatory_employee_id', 'escalation_enabled', 'escalation_days']))
        ->toBe(['secondary_approver_enabled' => false, 'secondary_signatory_employee_id' => null, 'escalation_enabled' => false, 'escalation_days' => null]);
});

it('keeps saved settings when the switch is turned off, disables the feature, and restores them when turned back on', function () {
    // The form derives the primary (Department Head) from the selected group.
    $group = EmployeeGroup::create(['name' => 'IT Group', 'group_head_employee_id' => $this->primary->id, 'is_active' => true]);
    $this->template->update(['employee_group_id' => $group->id]);

    $payload = fn (array $extra) => array_merge([
        'title' => $this->template->title, 'employee_group_id' => $group->id, 'checklist_classification' => 'primary', 'due_in_days' => 30, 'clearance_signing_deadline_days' => '',
        'items' => [['id' => $this->laptop->id, 'title' => 'Laptop Return', 'signatory_id' => $this->taskAssignee->id], ['id' => $this->access->id, 'title' => 'System Access Revocation']],
    ], $extra);

    // Edit page loads with the switch on (saved value).
    $this->actingAs($this->admin)->get(route('checklist-templates.edit', $this->template))->assertOk()->assertSee('Enable Secondary Approver');

    $this->actingAs($this->admin)->put(route('checklist-templates.update', $this->template), $payload(['secondary_approver_enabled' => 0]))
        ->assertSessionHasNoErrors();

    $this->template->refresh();
    expect($this->template->secondary_approver_enabled)->toBeFalse()
        // Previously saved settings are preserved...
        ->and($this->template->secondary_signatory_employee_id)->toBe($this->secondary->id)
        ->and($this->template->escalation_days)->toBe(10);

    // ...but ignored: a new request's checklist gets no secondary and no escalation.
    $approver = escSubmit($this);
    expect($approver->secondary_signatory_employee_id)->toBeNull()
        ->and($approver->escalation_enabled)->toBeFalse()
        ->and($approver->signatory_deadline_at)->toBeNull();
    $this->travelTo(Carbon::parse('2026-11-30 09:00:00'));
    escRun();
    expect($approver->fresh()->employee_id)->toBe($this->primary->id);

    // Turning it back on restores the saved settings (resubmitted by the form).
    $this->actingAs($this->admin)->put(route('checklist-templates.update', $this->template), $payload([
        'secondary_approver_enabled' => 1, 'secondary_signatory_employee_id' => $this->template->secondary_signatory_employee_id,
        'escalation_enabled' => 1, 'escalation_days' => $this->template->escalation_days, 'escalation_day_type' => 'calendar', 'escalation_notify_hr' => 1,
    ]))->assertSessionHasNoErrors();
    expect($this->template->fresh()->only(['secondary_approver_enabled', 'secondary_signatory_employee_id', 'escalation_days']))
        ->toBe(['secondary_approver_enabled' => true, 'secondary_signatory_employee_id' => $this->secondary->id, 'escalation_days' => 10]);

    // On, but no secondary chosen -> clear validation error.
    $this->actingAs($this->admin)->put(route('checklist-templates.update', $this->template), $payload(['secondary_approver_enabled' => 1, 'escalation_days' => 10]))
        ->assertSessionHasErrors('secondary_signatory_employee_id');

    // On, but no period -> clear validation error (escalation is automatic, so it's required).
    $this->actingAs($this->admin)->put(route('checklist-templates.update', $this->template), $payload(['secondary_approver_enabled' => 1, 'secondary_signatory_employee_id' => $this->secondary->id]))
        ->assertSessionHasErrors('escalation_days');
});

it('escalates automatically whenever Enable Secondary Approver is on — no separate checkbox', function () {
    $group = EmployeeGroup::create(['name' => 'Ops', 'group_head_employee_id' => $this->primary->id, 'is_active' => true]);

    $this->actingAs($this->admin)->get(route('checklist-templates.create'))->assertOk()
        ->assertDontSee('Enable Automatic Escalation')
        ->assertDontSee('name="escalation_enabled"', false);

    // Saved without any escalation_enabled field at all.
    $this->actingAs($this->admin)->post(route('checklist-templates.store'), [
        'title' => 'Ops Checklist', 'employee_group_id' => $group->id, 'checklist_classification' => 'primary', 'due_in_days' => '', 'clearance_signing_deadline_days' => 19,
        'items' => [['title' => 'Task']],
        'secondary_approver_enabled' => 1, 'secondary_signatory_employee_id' => $this->secondary->id, 'escalation_days' => 3, 'escalation_day_type' => 'calendar',
    ])->assertSessionHasNoErrors();

    $template = ChecklistTemplate::where('title', 'Ops Checklist')->sole();
    expect($template->secondary_approver_enabled)->toBeTrue()
        ->and($template->escalation_enabled)->toBeTrue()
        ->and($template->escalation_days)->toBe(3);

    // A request's checklist from it escalates after 3 days.
    $this->template->update(['is_active' => false]);
    $this->actingAs($this->admin)->post(route('offboarding-requests.store'), [
        'employee_id' => $this->offboardee->id, 'notice_date' => now()->toDateString(),
        'last_working_day' => now()->addDays(14)->toDateString(), 'separation_type_id' => SeparationType::value('id'),
    ])->assertSessionHasNoErrors();
    $approver = OffboardingRequestApprover::where('checklist_template_id', $template->id)->sole();
    expect($approver->signatory_deadline_at->toDateTimeString())->toBe('2026-10-04 09:00:00');

    $this->travelTo(Carbon::parse('2026-10-04 09:05:00'));
    escRun();
    expect($approver->fresh()->employee_id)->toBe($this->secondary->id);
});

// ---------------------------------------------------------------------
// New Offboarding Request review: secondary approver per checklist
// ---------------------------------------------------------------------

function escPreview(Employee $offboardee): \Illuminate\Support\Collection
{
    $preview = app(\App\Services\OffboardingRequestPreviewService::class)->preview($offboardee, [
        'separation_type_id' => SeparationType::value('id'),
        'last_working_day' => now()->addDays(14)->toDateString(),
        'notice_date' => now()->toDateString(),
        'approval_mode' => 'async',
    ], [], null);

    return collect($preview['checklists'])->keyBy('title');
}

it('shows each checklist\'s secondary approver and escalation period on the review screen, matching what submission applies', function () {
    $noBackupHead = Employee::factory()->create();
    ChecklistTemplate::forceCreate(['title' => 'HR Checklist', 'department_head_id' => $noBackupHead->id, 'is_active' => true, 'is_final_pay_checklist' => false, 'use_task_assignee_as_signatory' => false, 'sequence_type' => 'primary', 'secondary_approver_enabled' => false]);
    $working = ChecklistTemplate::forceCreate(['title' => 'Admin Checklist', 'department_head_id' => $noBackupHead->id, 'is_active' => true, 'is_final_pay_checklist' => false, 'use_task_assignee_as_signatory' => false, 'sequence_type' => 'primary',
        'secondary_approver_enabled' => true, 'secondary_signatory_employee_id' => $this->other->id, 'escalation_enabled' => true, 'escalation_days' => 1, 'escalation_day_type' => 'working']);

    $checklists = escPreview($this->offboardee);

    expect($checklists['IT Clearance Checklist']['secondary_approver'])->toBe([
        'applicable' => true, 'assigned' => true, 'name' => 'Sam Secondary', 'code' => $this->secondary->employee_code,
        'escalates' => true, 'escalation_label' => 'Escalates after 10 calendar days', 'note' => null,
    ])
        ->and($checklists['Admin Checklist']['secondary_approver']['escalation_label'])->toBe('Escalates after 1 working day')
        ->and($checklists['HR Checklist']['secondary_approver']['assigned'])->toBeFalse()
        ->and($checklists['HR Checklist']['secondary_approver']['applicable'])->toBeTrue()
        ->and($checklists['HR Checklist']['secondary_approver']['name'])->toBeNull();

    // What was previewed is what gets snapshotted.
    $approver = escSubmit($this);
    expect($approver->secondary_signatory_employee_id)->toBe($this->secondary->id)
        ->and($approver->escalation_days)->toBe(10)
        ->and(OffboardingRequestApprover::where('offboarding_request_id', $approver->offboarding_request_id)->where('checklist_template_id', $working->id)->value('secondary_signatory_employee_id'))->toBe($this->other->id);

    // The review markup renders the line in each card header.
    $this->actingAs($this->admin)->get(route('offboardees.index'))->assertOk()
        ->assertSee('No Secondary Approver Assigned')
        ->assertSee('checklist.secondary_approver.escalation_label', false);
});

it('flags a configured secondary that cannot be used on this request, and Task Assignee checklists', function () {
    ChecklistTemplate::forceCreate(['title' => 'Final Pay Checklist', 'is_active' => true, 'is_final_pay_checklist' => false, 'use_task_assignee_as_signatory' => true, 'sequence_type' => 'primary']);

    // The secondary is the offboardee themselves -> not applied, with the reason.
    $checklists = escPreview($this->secondary);

    expect($checklists['IT Clearance Checklist']['secondary_approver']['assigned'])->toBeFalse()
        ->and($checklists['IT Clearance Checklist']['secondary_approver']['note'])->toContain('cannot sign their own checklist')
        ->and($checklists['Final Pay Checklist']['secondary_approver']['applicable'])->toBeFalse();

    // Submission applies the same outcome — no secondary/escalation. (Its
    // recorded reason reads "not an active employee": the offboardee's own
    // status flips to "offboarding" before the checklist is snapshotted.)
    $approver = escSubmit($this, $this->secondary);
    expect($approver->escalation_enabled)->toBeFalse()
        ->and($approver->signatory_deadline_at)->toBeNull()
        ->and($approver->escalation_note)->toContain('Automatic escalation not applied');
});

// ---------------------------------------------------------------------
// Checklist template details: Primary / Secondary Approver panel
// ---------------------------------------------------------------------

it('shows the Primary Approver and Secondary Approver on the checklist template details page', function () {
    $group = EmployeeGroup::create(['name' => 'IT Group', 'group_head_employee_id' => $this->primary->id, 'is_active' => true]);
    $this->template->update(['employee_group_id' => $group->id, 'escalation_day_type' => 'working']);

    // Configured: both approvers, with the escalation period.
    $this->actingAs($this->admin)->get(route('checklist-templates.show', $this->template))->assertOk()
        ->assertSeeInOrder(['Primary Approver (Clearance Signatory)', 'Paula Primary', 'Group Head of IT Group', 'Secondary Approver', 'Sam Secondary', '10 working days'])
        ->assertDontSee('No Secondary Approver Assigned');

    // Secondary approver turned off -> "No Secondary Approver Assigned" (the saved secondary isn't shown as active config).
    $this->template->update(['secondary_approver_enabled' => false]);
    $this->actingAs($this->admin)->get(route('checklist-templates.show', $this->template))->assertOk()
        ->assertSee('Paula Primary')
        ->assertSee('No Secondary Approver Assigned')
        ->assertDontSee('Sam Secondary');

    // Reflects the current configuration: a changed group head shows immediately.
    $group->update(['group_head_employee_id' => $this->other->id]);
    $this->template->update(['department_head_id' => $this->other->id]);
    $this->actingAs($this->admin)->get(route('checklist-templates.show', $this->template))->assertOk()
        ->assertSee('Olga Other')->assertDontSee('Paula Primary');
});

it('labels Immediate Head and Task Assignee checklists on the details page', function () {
    $immediate = ChecklistTemplate::forceCreate(['title' => 'Immediate Head Checklist', 'is_active' => true, 'is_immediate_head_checklist' => true, 'use_task_assignee_as_signatory' => false]);
    $taskAssignee = ChecklistTemplate::forceCreate(['title' => 'Final Pay', 'is_active' => true, 'is_final_pay_checklist' => true, 'use_task_assignee_as_signatory' => true]);

    $this->actingAs($this->admin)->get(route('checklist-templates.show', $immediate))->assertOk()
        ->assertSee("Offboardee's Immediate Head", false)
        ->assertSee('No Secondary Approver Assigned');

    $this->actingAs($this->admin)->get(route('checklist-templates.show', $taskAssignee))->assertOk()
        ->assertSee('Task Assignees')
        ->assertSee('No Secondary Approver Assigned')
        ->assertSee('Not applicable');
});

// ---------------------------------------------------------------------
// "Assign Secondary Approver" shortcut from the template details page
// ---------------------------------------------------------------------

it('offers Assign Secondary Approver only when none is assigned, and opens that template\'s edit form pre-enabled', function () {
    $group = EmployeeGroup::create(['name' => 'IT Group', 'group_head_employee_id' => $this->primary->id, 'is_active' => true]);
    $this->template->update(['employee_group_id' => $group->id, 'secondary_approver_enabled' => false]);
    $editUrl = route('checklist-templates.edit', ['checklistTemplate' => $this->template, 'assign_secondary' => 1]);

    // No secondary -> message + button linking to THIS template's edit form.
    $this->actingAs($this->admin)->get(route('checklist-templates.show', $this->template))->assertOk()
        ->assertSeeInOrder(['No Secondary Approver Assigned', 'Assign Secondary Approver'])
        ->assertSee(e($editUrl), false);

    // The edit form opens with Enable Secondary Approver pre-ticked and focused…
    $html = $this->actingAs($this->admin)->get($editUrl)->assertOk()->getContent();
    expect($html)->toContain('id="secondary-approver-settings"')
        ->toContain('\\u0022focus\\u0022:true')
        ->toContain('\\u0022secondary_approver_enabled\\u0022:true');

    // …but nothing is saved until Update is clicked.
    expect($this->template->fresh()->secondary_approver_enabled)->toBeFalse();

    // A normal edit visit keeps the saved (unticked) state.
    expect($this->actingAs($this->admin)->get(route('checklist-templates.edit', $this->template))->getContent())
        ->toContain('\\u0022focus\\u0022:false')
        ->toContain('\\u0022secondary_approver_enabled\\u0022:false');

    // HR/Admin completes and saves via the existing Update.
    $this->actingAs($this->admin)->put(route('checklist-templates.update', $this->template), [
        'title' => $this->template->title, 'employee_group_id' => $group->id, 'checklist_classification' => 'primary', 'due_in_days' => 30, 'clearance_signing_deadline_days' => '',
        'items' => [['id' => $this->laptop->id, 'title' => 'Laptop Return', 'signatory_id' => $this->taskAssignee->id], ['id' => $this->access->id, 'title' => 'System Access Revocation']],
        'secondary_approver_enabled' => 1, 'secondary_signatory_employee_id' => $this->other->id, 'escalation_days' => 5, 'escalation_day_type' => 'calendar',
    ])->assertSessionHasNoErrors();

    // Viewing again: the name shows and the button is gone.
    $this->actingAs($this->admin)->get(route('checklist-templates.show', $this->template))->assertOk()
        ->assertSee('Olga Other')
        ->assertDontSee('Assign Secondary Approver')
        ->assertDontSee('No Secondary Approver Assigned');
});

it('hides Assign Secondary Approver from view-only users and for Task Assignee checklists', function () {
    $this->template->update(['secondary_approver_enabled' => false]);

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('offboarding-checklists.view');
    $this->actingAs($viewer)->get(route('checklist-templates.show', $this->template))->assertOk()
        ->assertSee('No Secondary Approver Assigned')
        ->assertDontSee('Assign Secondary Approver');
    // Even reaching the edit form directly, a view-only user can't save.
    $this->actingAs($viewer)->put(route('checklist-templates.update', $this->template), ['title' => 'x'])->assertForbidden();

    $taskAssignee = ChecklistTemplate::forceCreate(['title' => 'Final Pay', 'is_active' => true, 'is_final_pay_checklist' => true, 'use_task_assignee_as_signatory' => true]);
    $this->actingAs($this->admin)->get(route('checklist-templates.show', $taskAssignee))->assertOk()
        ->assertSee('Not applicable')
        ->assertDontSee('Assign Secondary Approver');
});

// ---------------------------------------------------------------------
// Audit trail: checklist history, Logs module, Offboarding Status
// ---------------------------------------------------------------------

it('records every re-routing exactly once in the checklist history, the request activity, and the Logs module', function () {
    $approver = escToHrAction($this);
    $this->travelTo(Carbon::parse('2026-10-23 14:00:00'));
    escReassign($this, $approver, $this->other, ['reason' => 'Secondary on extended leave.'])->assertOk();

    $events = ['escalated_to_secondary' => 'clearance_signatory_escalated', 'hr_action_required' => 'clearance_signatory_hr_action_required', 'reassigned_by_hr' => 'clearance_signatory_reassigned_by_hr'];
    foreach ($events as $event => $logAction) {
        expect($approver->signatoryAssignments()->where('event', $event)->count())->toBe(1)
            ->and(\App\Models\OffboardingActivity::where('offboarding_request_approver_id', $approver->id)->where('action', 'clearance_signatory_'.$event)->count())->toBe(1)
            ->and(ActivityLog::where('action', $logAction)->where('subject_id', $approver->id)->count())->toBe(1);
    }

    // Automatic escalation: attributed to System, with checklist, request, from/to, date and reason.
    $escalated = ActivityLog::where('action', 'clearance_signatory_escalated')->where('subject_id', $approver->id)->sole();
    expect($escalated->module)->toBe('Checklist')
        ->and($escalated->user_id)->toBeNull()
        ->and($escalated->username)->toBe('System')
        ->and($escalated->description)->toContain('"IT Clearance Checklist"')
        ->toContain("Offboarding Request #{$approver->offboarding_request_id} — Olivia Offboardee")
        ->toContain('From: Paula Primary.')
        ->toContain('To: Sam Secondary.')
        ->toContain('Initiated by: System (automatic escalation).')
        ->toContain('Date: Oct 11, 2026 9:05 AM.')
        ->toContain('Reason: The primary Clearance Signatory (Paula Primary) did not complete')
        ->and($escalated->old_values['clearance_signatory'])->toBe('Paula Primary')
        ->and($escalated->new_values['clearance_signatory'])->toBe('Sam Secondary')
        ->and($escalated->new_values['initiated_by'])->toBe('System (automatic escalation)');

    // HR/Admin reassignment: attributed to the HR/Admin user, with their reason.
    $reassigned = ActivityLog::where('action', 'clearance_signatory_reassigned_by_hr')->sole();
    expect($reassigned->user_id)->toBe($this->admin->id)
        ->and($reassigned->description)->toContain('From: Sam Secondary.')
        ->toContain('To: Olga Other.')
        ->toContain('Initiated by: Hera HrAdmin.')
        ->toContain('Date: Oct 23, 2026 2:00 PM.')
        ->toContain('Reason: Secondary on extended leave.')
        ->and($reassigned->new_values['history_id'])->toBe($approver->signatoryAssignments()->where('event', 'reassigned_by_hr')->value('id'));

    // Request activity rows read as proper labels, not raw action names.
    $labels = \App\Models\OffboardingActivity::where('offboarding_request_approver_id', $approver->id)->where('action', 'like', 'clearance_signatory_%')->get()->map->label()->all();
    expect($labels[0])->toBe('Re-routed to Secondary Approver: "IT Clearance Checklist" by System (automatic escalation)')
        ->and($labels[1])->toBe('HR/Admin Action Required: "IT Clearance Checklist": Clearance Signatory deadline missed')
        // Followed by the HR/Admin's department, like every other actor label.
        ->and($labels[2])->toStartWith('Clearance Signatory Reassigned: "IT Clearance Checklist" by Hera HrAdmin');
});

it('shows the complete reassignment history on the checklist in the Offboarding Status view', function () {
    $approver = escToHrAction($this);
    $this->travelTo(Carbon::parse('2026-10-23 14:00:00'));
    escReassign($this, $approver, $this->other)->assertOk();

    $step = collect($approver->offboardingRequest->fresh()->approverActivityTimeline())
        ->first(fn ($step) => ($step['rich'] ?? false) && $step['department'] === 'IT Clearance Checklist');

    expect(collect($step['signatoryHistory'])->map(fn ($m) => [$m['label'], $m['from'], $m['to'], $m['initiatedBy']])->all())->toBe([
        ['Assigned to Primary', null, 'Paula Primary', 'System'],
        ['Escalated to Secondary', 'Paula Primary', 'Sam Secondary', 'System (automatic escalation)'],
        ['HR/Admin Action Required', 'Sam Secondary', null, 'System (automatic escalation)'],
        ['Reassigned by HR/Admin', 'Sam Secondary', 'Olga Other', 'Hera HrAdmin'],
    ])
        ->and($step['signatoryHistory'][1]['occurredAt'])->toBe('Oct 11, 2026 9:05 AM')
        ->and($step['signatoryHistory'][3]['reason'])->toBe('Secondary on leave.');

    // A checklist never moved shows no history block at all.
    $untouched = escSubmit($this, Employee::factory()->create());
    $untouchedStep = collect($untouched->offboardingRequest->approverActivityTimeline())->first(fn ($step) => $step['rich'] ?? false);
    expect($untouchedStep['signatoryHistory'])->toBe([]);
});

it('opens the Approvals page when HR/Admin clicks the "HR/Admin action required" notification, whatever address the app is browsed at', function () {
    // Escalations are created by the scheduler, so the stored link is built
    // from APP_URL — not the address HR/Admin actually uses.
    config(['app.url' => 'http://localhost']);
    \Illuminate\Support\Facades\URL::useOrigin('http://localhost');
    escToHrAction($this);
    \Illuminate\Support\Facades\URL::useOrigin(null);

    $notification = $this->admin->fresh()->notifications()->where('type', 'clearance_signatory_escalation')->get()
        ->first(fn ($n) => ($n->data['event'] ?? null) === 'hr_action_required');
    expect($notification->data['message'])->toStartWith('HR/Admin action required')
        ->and($notification->data['url'])->toBe('http://localhost/approvals');

    // Browsing the app at http://127.0.0.1:8000: the click lands on THIS address's Approvals page.
    $this->actingAs($this->admin)
        ->post('http://127.0.0.1:8000/notifications/'.$notification->id.'/read')
        ->assertRedirect('http://127.0.0.1:8000/approvals');

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('writes each Approvals card payload once and has the card buttons look it up, instead of inlining it per button', function () {
    $approver = escSubmit($this);
    $signatory = User::firstWhere('username', $this->primary->employee_code_digits) ?? escUser($this->primary, User::ROLE_APPROVER);
    $signatory->forceFill(['must_change_password' => false])->save();

    $html = $this->actingAs($signatory)->get(route('approvals.index'))->assertOk()->getContent();
    $cardId = $approver->offboarding_request_id.'-'.$this->primary->id;

    expect($html)->toContain('id="approval-cards-data"')
        ->toContain("approvalCard('{$cardId}')")
        ->and($html)->not->toContain("open-checklist-modal', Object.assign({}, JSON.parse(");

    preg_match('/<script type="application\/json" id="approval-cards-data">(.*?)<\/script>/s', $html, $m);
    // The payload (e.g. its checklist item titles) lives only in the data
    // block — never repeated inside each button, in each layout.
    expect(substr_count(str_replace($m[0], '', $html), 'System Access Revocation'))->toBe(0);
    $cards = json_decode($m[1], true);
    expect($cards)->toHaveKey($cardId)
        ->and($cards[$cardId]['name'])->toBe('Olivia Offboardee');
});
