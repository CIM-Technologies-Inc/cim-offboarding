<?php

use App\Models\Employee;
use App\Models\OffboardingRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/*
 * Dashboard Calendar card — offboarding schedule by Last Working Day, read
 * live from the existing offboarding request records.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 09:00:00'));

    $this->admin = User::factory()->create(['username' => 'calendar.admin']);
    $this->admin->forceFill(['must_change_password' => false])->save();
    $this->admin->assignRole(User::ROLE_ADMIN);
});

function calendarRequest(string $name, string $lastWorkingDay, string $status = 'pending', array $employee = []): OffboardingRequest
{
    return OffboardingRequest::factory()->create([
        'employee_id' => Employee::factory()->create(array_merge(['name' => $name, 'department' => 'Marketing'], $employee))->id,
        'last_working_day' => $lastWorkingDay,
        'original_last_working_day' => $lastWorkingDay,
        'status' => $status,
        'completed_at' => $status === 'completed' ? now() : null,
        'cancelled_at' => $status === 'cancelled' ? now() : null,
        'created_at' => now()->subDays(5),
    ]);
}

it('replaces the Offboarding Trend chart and Completion Rate card with the Calendar card on the Dashboard', function () {
    $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
        ->assertSee('Offboardees by Last Working Day')
        ->assertSee('offboardingCalendar(', false)
        ->assertSee(str_replace('/', '\/', route('dashboard.calendar')), false)
        ->assertDontSee('Offboarding Trend')
        ->assertDontSee("This Month's Completion Rate", false);
});

it('groups requests by Last Working Day, excludes cancelled/retracted ones, and flags completed ones as not active', function () {
    $alice = calendarRequest('Alice Active', '2026-10-20', 'pending', ['employee_code' => 'EMP10001', 'department' => 'Finance']);
    calendarRequest('Ben Busy', '2026-10-20', 'in_progress');
    calendarRequest('Cara Completed', '2026-10-20', 'completed');
    calendarRequest('Rita Retracted', '2026-10-20', 'cancelled');
    calendarRequest('Next Month', '2026-11-20');

    $data = $this->actingAs($this->admin)->getJson(route('dashboard.calendar', ['month' => '2026-10']))->assertOk()->json();

    expect($data['month'])->toBe('2026-10')
        ->and($data['monthLabel'])->toBe('October 2026')
        ->and($data['today'])->toBe('2026-10-15')
        // Sunday-first 6-week grid.
        ->and($data['gridStart'])->toBe('2026-09-27')
        ->and(array_keys($data['days']))->toBe(['2026-10-20']);

    $day = collect($data['days']['2026-10-20']);
    expect($day->pluck('name')->all())->toBe(['Alice Active', 'Ben Busy', 'Cara Completed'])
        ->and($day->firstWhere('name', 'Cara Completed'))->toMatchArray(['status' => 'completed', 'isActive' => false])
        ->and($day->firstWhere('name', 'Alice Active'))->toMatchArray([
            'id' => $alice->id,
            'employeeCode' => 'EMP10001',
            'department' => 'Finance',
            'separationType' => $alice->separationType?->title ?? $alice->reason,
            'isActive' => true,
        ]);

    // Upcoming = active only, from today.
    expect(collect($data['upcoming'])->pluck('name')->all())->toBe(['Alice Active', 'Ben Busy', 'Next Month']);
});

it('navigates between months and follows a changed Last Working Day automatically', function () {
    $request = calendarRequest('Moving Date', '2026-11-30');

    $december = $this->actingAs($this->admin)->getJson(route('dashboard.calendar', ['month' => '2026-12']))->json();
    // Nov 30 is a leading day of December's grid (starts Sun Nov 29).
    expect($december['gridStart'])->toBe('2026-11-29')
        ->and($december['days'])->toHaveKey('2026-11-30');

    // Extending the LWD (any write to last_working_day) moves the event — no separate calendar record.
    $request->update(['last_working_day' => '2027-01-08']);

    $january = $this->actingAs($this->admin)->getJson(route('dashboard.calendar', ['month' => '2027-01']))->json();
    $december = $this->actingAs($this->admin)->getJson(route('dashboard.calendar', ['month' => '2026-12']))->json();
    expect($january['days'])->toHaveKey('2027-01-08')
        ->and($december['days'])->not->toHaveKey('2026-11-30');
});

it('falls back to the current month for an invalid month and is limited to Dashboard users', function () {
    $this->actingAs($this->admin)->getJson(route('dashboard.calendar', ['month' => '2026-13']))
        ->assertOk()->assertJsonPath('month', '2026-10');

    $employee = User::factory()->create(['username' => 'plain.employee']);
    $employee->forceFill(['must_change_password' => false])->save();
    $employee->assignRole(User::ROLE_EMPLOYEE);

    $this->actingAs($employee)->getJson(route('dashboard.calendar'))->assertForbidden();
});
