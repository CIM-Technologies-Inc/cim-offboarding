<?php

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * "Login As User" impersonation — server-side session handling plus the
 * layout's identity marker that forces Turbo to rebuild the permanent
 * header/sidebar whenever the signed-in identity changes (see the
 * `app-identity` meta in layouts/app.blade.php).
 *
 *   DB_CONNECTION=mysql DB_DATABASE=cim_db_test vendor/bin/pest tests/Feature/ImpersonationTest.php
 */

uses(RefreshDatabase::class);

function impersonationAdmin(): User
{
    $employee = Employee::factory()->create(['name' => 'Audit Admin']);
    $admin = User::factory()->create(['name' => 'Audit Admin', 'username' => $employee->employee_code_digits]);
    $admin->forceFill(['must_change_password' => false])->save();
    $admin->assignRole(User::ROLE_ADMIN);

    return $admin;
}

function impersonationTarget(): array
{
    $employee = Employee::factory()->create(['name' => 'Target Employee']);
    $user = User::factory()->create(['name' => 'Target Employee', 'username' => $employee->employee_code_digits]);
    $user->forceFill(['must_change_password' => false])->save();
    $user->assignRole('employee');

    return [$employee, $user];
}

function identityMarker(string $html): ?string
{
    return preg_match('/<meta name="app-identity" data-turbo-track="reload"\s+content="([^"]+)"/', $html, $m) ? $m[1] : null;
}

it('switches to the user, then Return restores the original admin and ends the impersonation', function () {
    $admin = impersonationAdmin();
    [$employee, $user] = impersonationTarget();

    $this->actingAs($admin)->post(route('impersonate.start', $employee))
        ->assertRedirect(route($user->defaultHomeRouteName()));

    $this->assertAuthenticatedAs($user);
    expect(session('impersonator_id'))->toBe($admin->id);

    $this->post(route('impersonate.stop'))->assertRedirect(route($admin->defaultHomeRouteName()));

    $this->assertAuthenticatedAs($admin);
    expect(session()->has('impersonator_id'))->toBeFalse()
        ->and(session()->has('impersonator_name'))->toBeFalse();

    $this->get(route('users.index'))->assertOk()->assertDontSee('Return to My Account');
    $this->post(route('impersonate.stop'))->assertForbidden();
});

it('can be used repeatedly without leaking the previous impersonation', function () {
    $admin = impersonationAdmin();
    [$employee, $user] = impersonationTarget();
    $this->actingAs($admin);

    foreach (range(1, 3) as $round) {
        $this->post(route('impersonate.start', $employee));
        $this->assertAuthenticatedAs($user);
        $this->post(route('impersonate.stop'));
        $this->assertAuthenticatedAs($admin);
        expect(session()->has('impersonator_id'))->toBeFalse();
    }
});

it('refuses to chain a second impersonation from inside one', function () {
    $admin = impersonationAdmin();
    [$employee, $user] = impersonationTarget();
    [$second] = impersonationTarget();

    $this->actingAs($admin)->post(route('impersonate.start', $employee));
    $this->post(route('impersonate.start', $second));

    $this->assertAuthenticatedAs($user);
    expect(session('impersonator_id'))->toBe($admin->id);
});

it('marks every page with an identity value that changes on Login As and on Return, so Turbo reloads the header and sidebar', function () {
    $admin = impersonationAdmin();
    [$employee, $user] = impersonationTarget();

    $asAdmin = identityMarker($this->actingAs($admin)->get(route('users.index'))->assertOk()->getContent());

    $this->post(route('impersonate.start', $employee));
    $asUser = identityMarker($this->get(route($user->defaultHomeRouteName()))->assertOk()->getContent());

    $this->post(route('impersonate.stop'));
    $afterReturn = identityMarker($this->get(route('users.index'))->assertOk()->getContent());

    expect($asAdmin)->not->toBeNull()
        ->and($asUser)->not->toBeNull()
        ->and($asUser)->not->toBe($asAdmin)
        ->and($afterReturn)->toBe($asAdmin)
        ->and($asAdmin)->not->toContain((string) $admin->id);
});
