<?php

use App\Models\ChecklistItemAssignment;
use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\FinalApprover;
use App\Models\GeneralSignatory;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use App\Models\SeparationType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

/*
 * New Offboarding Request is blocked while the chosen offboardee is still the
 * Task Assignee of outstanding checklist tasks on other active requests.
 *
 *   DB_CONNECTION=mysql DB_DATABASE=cim_db_test vendor/bin/pest tests/Feature/TaskAssigneeConflictTest.php
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();

    ChecklistTemplate::query()->update(['is_active' => false]);
    GeneralSignatory::query()->update(['is_active' => false]);
    FinalApprover::query()->update(['is_active' => false]);
    FinalApprover::forceCreate(['employee_id' => Employee::factory()->create()->id, 'is_active' => true]);

    $adminEmployee = Employee::factory()->create(['name' => 'Conflict Admin']);
    $this->admin = User::factory()->create(['username' => $adminEmployee->employee_code_digits]);
    $this->admin->forceFill(['must_change_password' => false])->save();
    $this->admin->assignRole(User::ROLE_ADMIN);

    // The employee HR now wants to offboard — still a Task Assignee elsewhere.
    $this->tasker = Employee::factory()->create(['name' => 'Tess Tasker']);

    $this->itHead = Employee::factory()->create(['name' => 'Ivan ItHead']);
    // Both checklists share one Clearance Signatory, so one group's Task
    // Assignees are eligible for "Assign To" on either (eligiblePoolAssigneeIds()).
    $this->hrHead = $this->itHead;
    $group = \App\Models\EmployeeGroup::create(['name' => 'Ops', 'group_head_employee_id' => $this->itHead->id, 'is_active' => true]);
    $this->replacement = Employee::factory()->create(['name' => 'Ray Replacement', 'employee_group_id' => $group->id, 'is_task_assignee' => true]);

    $this->it = ChecklistTemplate::forceCreate(['title' => 'IT Clearance Checklist', 'department_head_id' => $this->itHead->id, 'is_active' => true, 'is_final_pay_checklist' => false, 'use_task_assignee_as_signatory' => false, 'sequence_type' => 'primary']);
    $this->laptop = $this->it->items()->create(['title' => 'Laptop Return', 'signatory_id' => $this->tasker->id, 'sort_order' => 1]);
    $this->access = $this->it->items()->create(['title' => 'System Access Revocation', 'signatory_id' => $this->tasker->id, 'sort_order' => 2]);
    $this->badge = $this->it->items()->create(['title' => 'Badge Return', 'sort_order' => 3]);

    $this->hr = ChecklistTemplate::forceCreate(['title' => 'HR Checklist', 'department_head_id' => $this->hrHead->id, 'is_active' => true, 'is_final_pay_checklist' => false, 'use_task_assignee_as_signatory' => false, 'sequence_type' => 'primary']);
    $this->exitInterview = $this->hr->items()->create(['title' => 'Exit Interview', 'signatory_id' => $this->tasker->id, 'sort_order' => 1]);

    $this->otherOffboardee = Employee::factory()->create(['name' => 'Omar Other']);
});

function tacPayload(Employee $employee): array
{
    return [
        'employee_id' => $employee->id,
        'notice_date' => now()->toDateString(),
        'last_working_day' => now()->addDays(14)->toDateString(),
        'separation_type_id' => SeparationType::value('id'),
    ];
}

function tacSubmit($test, Employee $employee)
{
    return $test->actingAs($test->admin)->post(route('offboarding-requests.store'), tacPayload($employee));
}

function tacApprover(OffboardingRequest $request, ChecklistTemplate $template): OffboardingRequestApprover
{
    return OffboardingRequestApprover::where('offboarding_request_id', $request->id)->where('checklist_template_id', $template->id)->sole();
}

function tacConflicts($test, Employee $employee)
{
    return $test->actingAs($test->admin)->getJson(route('offboarding-requests.task-assignee-conflicts', $employee))->assertOk();
}

function tacOutstanding(Employee $employee)
{
    return OffboardingRequestApprover::outstandingTaskAssignmentsForEmployee($employee->id);
}

it('lets HR proceed for an employee with no outstanding task assignments', function () {
    $free = Employee::factory()->create();

    tacConflicts($this, $free)->assertExactJson(['blocked' => false, 'message' => null]);

    $this->actingAs($this->admin)->postJson(route('offboarding-requests.preview'), tacPayload($free))->assertOk();
    tacSubmit($this, $free)->assertSessionHasNoErrors();
    expect(OffboardingRequest::where('employee_id', $free->id)->exists())->toBeTrue();
});

it('blocks with the warning only — never listing the affected checklists or tasks', function () {
    // Tess holds tasks on two active requests (IT x2 + HR x1 each).
    tacSubmit($this, $this->otherOffboardee)->assertSessionHasNoErrors();
    tacSubmit($this, Employee::factory()->create(['name' => 'Sara Second']))->assertSessionHasNoErrors();
    expect(tacOutstanding($this->tasker))->toHaveCount(6);

    $message = \App\Http\Controllers\OffboardingRequestController::TASK_ASSIGNEE_CONFLICT_MESSAGE;
    expect($message)->toBe('The selected employee is currently assigned as a task assignee in one or more active offboarding checklists. Before creating a new offboarding request, you must assign these tasks to another eligible employee.');

    // Selection-time check: yes/no + message, nothing else.
    tacConflicts($this, $this->tasker)->assertExactJson(['blocked' => true, 'message' => $message]);

    // Review Request: blocked, no task data in the response.
    $preview = $this->actingAs($this->admin)->postJson(route('offboarding-requests.preview'), tacPayload($this->tasker))
        ->assertStatus(422)
        ->assertJsonPath('message', $message)
        ->assertJsonMissingPath('taskAssigneeConflicts');
    expect($preview->getContent())->not->toContain('Laptop Return')->not->toContain('IT Clearance Checklist');

    // Submit: blocked with just the message.
    tacSubmit($this, $this->tasker)->assertSessionHasErrors(['employee_id' => $message]);
    expect(OffboardingRequest::where('employee_id', $this->tasker->id)->exists())->toBeFalse()
        ->and($this->tasker->fresh()->status)->toBe('active');
});

it('keeps blocking until EVERY task is reassigned through the existing Assign To, then lets HR proceed', function () {
    tacSubmit($this, $this->otherOffboardee)->assertSessionHasNoErrors();
    $request = OffboardingRequest::where('employee_id', $this->otherOffboardee->id)->sole();
    $assign = fn ($template, $item) => $this->actingAs($this->admin)
        ->post(route('approvals.items.assign', [tacApprover($request, $template), $item]), ['employee_id' => $this->replacement->id])
        ->assertRedirect()->assertSessionHasNoErrors();

    $assign($this->it, $this->laptop);
    $assign($this->it, $this->access);
    // One task left -> still blocked.
    tacConflicts($this, $this->tasker)->assertJsonPath('blocked', true);
    tacSubmit($this, $this->tasker)->assertSessionHasErrors('employee_id');

    $assign($this->hr, $this->exitInterview);
    tacConflicts($this, $this->tasker)->assertJsonPath('blocked', false);
    tacSubmit($this, $this->tasker)->assertSessionHasNoErrors();
    expect(OffboardingRequest::where('employee_id', $this->tasker->id)->exists())->toBeTrue();

    // History preserved: Tess's original assignment rows remain (superseded), unrelated tasks untouched.
    $itApprover = tacApprover($request, $this->it);
    expect(ChecklistItemAssignment::where('offboarding_request_approver_id', $itApprover->id)->where('checklist_item_id', $this->laptop->id)->where('assigned_employee_id', $this->tasker->id)->where('status', 'superseded')->exists())->toBeTrue()
        ->and(ChecklistItemAssignment::where('offboarding_request_approver_id', $itApprover->id)->where('checklist_item_id', $this->badge->id)->where('status', 'active')->exists())->toBeTrue();
});

it('counts only outstanding tasks on active requests (not completed tasks, approved checklists, or retracted requests)', function () {
    tacSubmit($this, $this->otherOffboardee)->assertSessionHasNoErrors();
    $request = OffboardingRequest::where('employee_id', $this->otherOffboardee->id)->sole();

    tacApprover($request, $this->it)->itemProgress()->create(['checklist_item_id' => $this->laptop->id, 'is_checked' => true, 'checked_at' => now()]);
    tacApprover($request, $this->hr)->update(['status' => 'approved', 'approved_at' => now()]);
    expect(tacOutstanding($this->tasker)->map(fn ($row) => $row['item']->title)->all())->toBe(['System Access Revocation']);

    // A held task is still outstanding.
    tacApprover($request, $this->it)->itemProgress()->create(['checklist_item_id' => $this->access->id, 'is_checked' => false, 'status' => 'hold']);
    expect(tacOutstanding($this->tasker))->toHaveCount(1);

    // A second request adds its 3 tasks; retracting it removes exactly those again.
    $second = Employee::factory()->create(['name' => 'Sara Second']);
    tacSubmit($this, $second)->assertSessionHasNoErrors();
    expect(tacOutstanding($this->tasker))->toHaveCount(4);
    $this->actingAs($this->admin)->postJson(route('offboarding-requests.cancel', OffboardingRequest::where('employee_id', $second->id)->sole()), ['reason' => 'Stayed'])->assertOk();
    expect(tacOutstanding($this->tasker))->toHaveCount(1);
    tacConflicts($this, $this->tasker)->assertJsonPath('blocked', true);
});

it('does not block an employee whose only tasks are on retracted requests', function () {
    tacSubmit($this, $this->otherOffboardee)->assertSessionHasNoErrors();
    $request = OffboardingRequest::where('employee_id', $this->otherOffboardee->id)->sole();

    $this->actingAs($this->admin)->postJson(route('offboarding-requests.cancel', $request), ['reason' => 'Stayed'])->assertOk();

    tacConflicts($this, $this->tasker)->assertJsonPath('blocked', false);
    tacSubmit($this, $this->tasker)->assertSessionHasNoErrors();
    expect(OffboardingRequest::where('employee_id', $this->tasker->id)->where('status', 'pending')->exists())->toBeTrue();
});

it('blocks submission when a task is assigned after the employee was selected', function () {
    tacSubmit($this, $this->otherOffboardee)->assertSessionHasNoErrors();
    $request = OffboardingRequest::where('employee_id', $this->otherOffboardee->id)->sole();

    tacConflicts($this, $this->replacement)->assertJsonPath('blocked', false);
    $this->actingAs($this->admin)->postJson(route('offboarding-requests.preview'), tacPayload($this->replacement))->assertOk();

    $this->actingAs($this->admin)->post(route('approvals.items.assign', [tacApprover($request, $this->it), $this->badge]), ['employee_id' => $this->replacement->id])
        ->assertRedirect()->assertSessionHasNoErrors();

    tacSubmit($this, $this->replacement)->assertSessionHasErrors('employee_id');
    expect(OffboardingRequest::where('employee_id', $this->replacement->id)->exists())->toBeFalse()
        ->and(session('errors')->first('employee_id'))->not->toContain('Badge Return');
});

it('uses a warning notification on the New Offboarding Request page — no task-list interface', function () {
    $html = $this->actingAs($this->admin)->get(route('offboardees.index'))->assertOk()->getContent();

    expect($html)->toContain('window.offboardingTaskAssigneeGate')
        ->toContain('offboardingTaskAssigneeGate?.check(employee.id)')
        ->toContain("icon: 'warning'")
        ->not->toContain('Recheck Assignments')
        ->not->toContain('open-task-assignee-conflicts')
        ->not->toContain('View Checklist');
});

// ---------------------------------------------------------------------
// "Manage Checklist Templates" button on the warning
// ---------------------------------------------------------------------

it('offers Manage Checklist Templates (same-tab redirect) only to users who can open that page', function () {
    $html = $this->actingAs($this->admin)->get(route('offboardees.index'))->assertOk()->getContent();
    expect($html)->toContain("confirmButtonText: 'Manage Checklist Templates'")
        ->toContain('manageTemplatesUrl:')
        ->not->toContain('manageTemplatesUrl: null')
        ->toContain('window.location.assign(manageUrl)')
        ->not->toContain('window.open(');

    $this->actingAs($this->admin)->get(route('checklist-templates.index'))->assertOk();

    // Without offboarding-checklists.view the button isn't offered (and the page is refused).
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('offboardees.view');
    $viewerHtml = $this->actingAs($viewer)->get(route('offboardees.index'))->assertOk()->getContent();
    expect($viewerHtml)->toContain('manageTemplatesUrl: null');
    $this->actingAs($viewer)->get(route('checklist-templates.index'))->assertForbidden();
});

it('editing a checklist template changes FUTURE requests only — existing requests stay blocked until their tasks are reassigned', function () {
    tacSubmit($this, $this->otherOffboardee)->assertSessionHasNoErrors();
    $request = OffboardingRequest::where('employee_id', $this->otherOffboardee->id)->sole();
    $group = \App\Models\EmployeeGroup::where('name', 'Ops')->sole();

    // HR replaces Tess with Ray on the IT template, via the real form save.
    $this->actingAs($this->admin)->put(route('checklist-templates.update', $this->it), [
        'title' => 'IT Clearance Checklist', 'employee_group_id' => $group->id, 'checklist_classification' => 'primary', 'due_in_days' => '', 'clearance_signing_deadline_days' => '',
        'items' => [
            ['id' => $this->laptop->id, 'title' => 'Laptop Return', 'signatory_id' => $this->replacement->id],
            ['id' => $this->access->id, 'title' => 'System Access Revocation', 'signatory_id' => $this->replacement->id],
            ['id' => $this->badge->id, 'title' => 'Badge Return'],
        ],
    ])->assertSessionHasNoErrors();
    expect($this->laptop->fresh()->signatory_id)->toBe($this->replacement->id);

    // The existing request still has Tess on those tasks — still blocked.
    expect(tacOutstanding($this->tasker)->where(fn ($row) => $row['approver']->offboarding_request_id === $request->id)->count())->toBe(3);
    tacConflicts($this, $this->tasker)->assertJsonPath('blocked', true);

    // A request created AFTER the edit assigns Ray, not Tess.
    $later = Employee::factory()->create(['name' => 'Lara Later']);
    tacSubmit($this, $later)->assertSessionHasNoErrors();
    $laterIt = tacApprover(OffboardingRequest::where('employee_id', $later->id)->sole(), $this->it);
    expect($laterIt->effectiveSignatoryFor($this->laptop->fresh())->id)->toBe($this->replacement->id);
});
