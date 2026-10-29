<?php

use App\Models\Employee;
use App\Models\EmployeeGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Employee Master "Add New Employee" — the Immediate Head picker. The picked
 * employee is saved as the new hire's Immediate Head (`head_employee_id`)
 * AND becomes their Group Head, via membership in a group that head runs.
 *
 *   DB_CONNECTION=mysql DB_DATABASE=cim_db_test vendor/bin/pest tests/Feature/AddEmployeeImmediateHeadTest.php
 */

uses(RefreshDatabase::class);

function immediateHeadAdmin(): User
{
    $employee = Employee::factory()->create(['name' => 'Roster Admin']);
    $admin = User::factory()->create(['name' => 'Roster Admin', 'username' => $employee->employee_code_digits]);
    $admin->forceFill(['must_change_password' => false])->save();
    $admin->assignRole(User::ROLE_ADMIN);

    return $admin;
}

function newHirePayload(array $overrides = []): array
{
    return array_merge([
        'employee_code' => 'EMP' . fake()->unique()->numerify('#####'),
        'firstName' => 'Nina',
        'middleName' => 'Reyes',
        'lastName' => 'Valdez',
        'email' => 'nina.valdez@example.com',
        'personal_email' => '',
        'head_employee_id' => '',
        'department' => 'Finance and Accounting',
        'position' => 'Accounting Staff',
    ], $overrides);
}

it('saves the Immediate Head and places the new hire in the group that head already runs', function () {
    $admin = immediateHeadAdmin();
    $head = Employee::factory()->create(['name' => 'Group Head', 'department' => 'Finance and Accounting']);
    $group = EmployeeGroup::create(['name' => 'Finance', 'group_head_employee_id' => $head->id, 'is_active' => true]);

    $response = $this->actingAs($admin)
        ->postJson(route('employee-groups.employees.store'), newHirePayload(['head_employee_id' => $head->id]))
        ->assertOk();

    $newHire = Employee::findOrFail($response->json('employee.id'));

    expect($newHire->head_employee_id)->toBe($head->id)
        ->and($newHire->head)->toBe('Group Head')
        ->and($newHire->employee_group_id)->toBe($group->id)
        ->and($newHire->departmentHead()?->id)->toBe($head->id)
        ->and($newHire->approvalHead()?->id)->toBe($head->id)
        ->and($response->json('employee.head_employee.id'))->toBe($head->id)
        ->and(EmployeeGroup::count())->toBe(1);
});

it('creates a group for an Immediate Head who does not run one yet, without moving anyone else', function () {
    $admin = immediateHeadAdmin();
    $head = Employee::factory()->create(['name' => 'New Lead', 'department' => 'Finance and Accounting']);
    $colleague = Employee::factory()->create(['department' => 'Finance and Accounting', 'employee_group_id' => null]);

    $response = $this->actingAs($admin)
        ->postJson(route('employee-groups.employees.store'), newHirePayload(['head_employee_id' => $head->id]))
        ->assertOk();

    $group = EmployeeGroup::where('group_head_employee_id', $head->id)->sole();
    $newHire = Employee::findOrFail($response->json('employee.id'));

    expect($group->name)->toBe('Finance and Accounting')
        ->and($newHire->employee_group_id)->toBe($group->id)
        ->and($newHire->departmentHead()?->id)->toBe($head->id)
        ->and($colleague->fresh()->employee_group_id)->toBeNull()
        ->and($response->json('employee.employee_group.group_head.id'))->toBe($head->id);
});

it('still allows adding an employee with no Immediate Head', function () {
    $admin = immediateHeadAdmin();
    Employee::factory()->create(['department' => 'Finance and Accounting']);

    $response = $this->actingAs($admin)
        ->postJson(route('employee-groups.employees.store'), newHirePayload())
        ->assertOk();

    $newHire = Employee::findOrFail($response->json('employee.id'));

    expect($newHire->head_employee_id)->toBeNull()
        ->and($newHire->employee_group_id)->toBeNull();
});

it('rejects an Immediate Head that is not a valid, active employee', function () {
    $admin = immediateHeadAdmin();
    $offboarded = Employee::factory()->create(['department' => 'Finance and Accounting', 'status' => 'offboarded']);

    $this->actingAs($admin)
        ->postJson(route('employee-groups.employees.store'), newHirePayload(['head_employee_id' => 999999]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('head_employee_id');

    $this->actingAs($admin)
        ->postJson(route('employee-groups.employees.store'), newHirePayload(['head_employee_id' => $offboarded->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('head_employee_id');

    expect(Employee::where('lastName', 'Valdez')->exists())->toBeFalse();
});

it('renders the Immediate Head picker on the Employee Master page', function () {
    $this->actingAs(immediateHeadAdmin())
        ->get(route('employee-groups.index'))
        ->assertOk()
        ->assertSee('Immediate Head')
        ->assertSee("Also becomes this employee's Group Head.", false);
});
