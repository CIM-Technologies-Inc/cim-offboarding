<?php

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
 * Creating a new offboarding request for an employee whose previous request
 * was retracted (`status = cancelled`).
 *
 *   DB_CONNECTION=mysql DB_DATABASE=cim_db_test vendor/bin/pest tests/Feature/RetractedEmployeeNewRequestTest.php
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();

    ChecklistTemplate::query()->update(['is_active' => false]);
    GeneralSignatory::query()->update(['is_active' => false]);
    FinalApprover::query()->update(['is_active' => false]);
    FinalApprover::forceCreate(['employee_id' => Employee::factory()->create()->id, 'is_active' => true]);

    $adminEmployee = Employee::factory()->create(['name' => 'Retract Admin']);
    $this->admin = User::factory()->create(['username' => $adminEmployee->employee_code_digits]);
    $this->admin->forceFill(['must_change_password' => false])->save();
    $this->admin->assignRole(User::ROLE_ADMIN);

    $this->head = Employee::factory()->create(['name' => 'Hana OriginalHead']);
    $this->newHead = Employee::factory()->create(['name' => 'Nico NewHead']);
    $this->deptHead = Employee::factory()->create(['name' => 'Dora DeptHead']);

    $this->template = ChecklistTemplate::forceCreate(['title' => 'IT Checklist', 'department_head_id' => $this->deptHead->id, 'is_active' => true, 'is_final_pay_checklist' => false, 'use_task_assignee_as_signatory' => false, 'sequence_type' => 'primary']);
    $this->item = $this->template->items()->create(['title' => 'Laptop Return', 'sort_order' => 1]);
    $this->immediateHeadTemplate = ChecklistTemplate::forceCreate(['title' => 'Immediate Head Checklist', 'is_active' => true, 'is_immediate_head_checklist' => true, 'is_final_pay_checklist' => false, 'use_task_assignee_as_signatory' => false, 'sequence_type' => 'primary']);

    $this->employee = Employee::factory()->create(['name' => 'Rita Retracted', 'head_employee_id' => $this->head->id]);
});

function rnrPayload(Employee $employee, array $extra = []): array
{
    return array_merge([
        'employee_id' => $employee->id,
        'notice_date' => now()->toDateString(),
        'last_working_day' => now()->addDays(14)->toDateString(),
        'separation_type_id' => SeparationType::value('id'),
    ], $extra);
}

function rnrRetract($test, OffboardingRequest $request)
{
    return $test->actingAs($test->admin)->postJson(route('offboarding-requests.cancel', $request), ['reason' => 'Employee stayed']);
}

function rnrImmediateHeadApprover(OffboardingRequest $request): ?OffboardingRequestApprover
{
    return OffboardingRequestApprover::where('offboarding_request_id', $request->id)
        ->whereHas('checklistTemplate', fn ($q) => $q->where('is_immediate_head_checklist', true))
        ->first();
}

it('allows a new request after retraction, keeps the retracted one as history, and starts the workflow fresh', function () {
    $this->actingAs($this->admin)->post(route('offboarding-requests.store'), rnrPayload($this->employee, ['immediate_head_id' => $this->head->id]))
        ->assertSessionHasNoErrors();
    $first = OffboardingRequest::where('employee_id', $this->employee->id)->sole();
    $firstApprover = OffboardingRequestApprover::where('offboarding_request_id', $first->id)->where('checklist_template_id', $this->template->id)->sole();
    $firstApprover->itemProgress()->create(['checklist_item_id' => $this->item->id, 'is_checked' => true, 'remark' => 'Returned early', 'checked_at' => now()]);
    $firstApprover->update(['status' => 'viewed']);

    // Still active -> a duplicate is refused.
    $this->actingAs($this->admin)->post(route('offboarding-requests.store'), rnrPayload($this->employee))
        ->assertSessionHasErrors('employee_id');
    expect(OffboardingRequest::where('employee_id', $this->employee->id)->count())->toBe(1);

    rnrRetract($this, $first)->assertOk()->assertJsonPath('headEmployeeId', $this->head->id);
    expect($this->employee->fresh()->status)->toBe('active');

    // New request for the same employee, Immediate Head left empty on the form.
    $this->actingAs($this->admin)->post(route('offboarding-requests.store'), rnrPayload($this->employee))
        ->assertSessionHasNoErrors();

    $second = OffboardingRequest::where('employee_id', $this->employee->id)->where('id', '!=', $first->id)->sole();
    $secondApprover = OffboardingRequestApprover::where('offboarding_request_id', $second->id)->where('checklist_template_id', $this->template->id)->sole();

    expect($second->status)->toBe('pending')
        // Immediate Head auto-detected from Employee Master.
        ->and($second->immediate_head_id)->toBe($this->head->id)
        ->and(rnrImmediateHeadApprover($second)?->employee_id)->toBe($this->head->id)
        // Fresh workflow: nothing carried over from the retracted request.
        ->and($secondApprover->id)->not->toBe($firstApprover->id)
        ->and($secondApprover->status)->toBe('pending')
        ->and($secondApprover->itemProgress()->count())->toBe(0)
        // The retracted request is preserved as history, untouched.
        ->and($first->fresh()->status)->toBe('cancelled')
        ->and($first->fresh()->cancellation_reason)->toBe('Employee stayed')
        ->and($firstApprover->fresh()->status)->toBe('viewed')
        ->and($firstApprover->itemProgress()->value('remark'))->toBe('Returned early')
        ->and($this->employee->fresh()->status)->toBe('offboarding');

    // And once more: the new request is active, so another duplicate is refused.
    $this->actingAs($this->admin)->post(route('offboarding-requests.store'), rnrPayload($this->employee))
        ->assertSessionHasErrors('employee_id');
});

it('uses the CURRENT Employee Master Immediate Head, not the one on the retracted request', function () {
    $this->actingAs($this->admin)->post(route('offboarding-requests.store'), rnrPayload($this->employee, ['immediate_head_id' => $this->head->id]))
        ->assertSessionHasNoErrors();
    $first = OffboardingRequest::where('employee_id', $this->employee->id)->sole();
    rnrRetract($this, $first)->assertOk();

    // HR changes the employee's Immediate Head in Employee Master.
    $this->employee->update(['head_employee_id' => $this->newHead->id]);

    // The modal's auto-fill map is on the page…
    expect($this->actingAs($this->admin)->get(route('offboardees.index'))->assertOk()->getContent())
        ->toContain('immediateHeadByEmployeeId');

    // …and the server resolves the same current head when the field is empty.
    $preview = $this->actingAs($this->admin)->postJson(route('offboarding-requests.preview'), rnrPayload($this->employee))->assertOk();
    expect($preview->json('employee.immediate_head.id'))->toBe($this->newHead->id);

    $this->actingAs($this->admin)->post(route('offboarding-requests.store'), rnrPayload($this->employee))->assertSessionHasNoErrors();
    $latest = OffboardingRequest::where('employee_id', $this->employee->id)->where('status', 'pending')->sole();

    expect($latest->immediate_head_id)->toBe($this->newHead->id)
        ->and(rnrImmediateHeadApprover($latest)?->employee_id)->toBe($this->newHead->id)
        ->and($first->fresh()->immediate_head_id)->toBe($this->head->id); // history unchanged
});

it('keeps a manually picked Immediate Head, and falls back to the Group Head when the Employee Master head is unusable', function () {
    // Manual pick wins.
    $this->actingAs($this->admin)->post(route('offboarding-requests.store'), rnrPayload($this->employee, ['immediate_head_id' => $this->newHead->id]))
        ->assertSessionHasNoErrors();
    expect(OffboardingRequest::where('employee_id', $this->employee->id)->sole()->immediate_head_id)->toBe($this->newHead->id);

    // Employee Master head no longer active -> Group Head fallback (previous behaviour).
    $groupHead = Employee::factory()->create(['name' => 'Gina GroupHead']);
    $group = \App\Models\EmployeeGroup::create(['name' => 'Ops', 'group_head_employee_id' => $groupHead->id, 'is_active' => true]);
    $other = Employee::factory()->create(['head_employee_id' => $this->head->id, 'employee_group_id' => $group->id]);
    $this->head->update(['status' => 'offboarded']);

    $this->actingAs($this->admin)->post(route('offboarding-requests.store'), rnrPayload($other))->assertSessionHasNoErrors();
    expect(OffboardingRequest::where('employee_id', $other->id)->sole()->immediate_head_id)->toBe($groupHead->id);
});
