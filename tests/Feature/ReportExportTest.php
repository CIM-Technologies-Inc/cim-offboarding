<?php

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Reports Excel/CSV export: the links must bypass Turbo Drive — a Turbo visit
 * to a file download never renders a page, which left the Reports page stuck
 * on its loading skeleton after the file downloaded.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['username' => 'reports.admin']);
    $this->admin->forceFill(['must_change_password' => false])->save();
    $this->admin->assignRole(User::ROLE_ADMIN);
});

it('renders the Excel and CSV export links outside Turbo so the page never hangs on download', function () {
    $html = $this->actingAs($this->admin)->get(route('reports.index'))->assertOk()->getContent();

    foreach (['xlsx', 'csv'] as $format) {
        expect(preg_match('/<a href="[^"]*format='.$format.'[^"]*" data-turbo="false"/', $html))->toBe(1, "{$format} export link must have data-turbo=\"false\"");
    }
});

it('still downloads the Excel and CSV exports', function () {
    foreach (['xlsx' => '.xlsx', 'csv' => '.csv'] as $format => $extension) {
        $response = $this->actingAs($this->admin)->get(route('reports.export', ['format' => $format]));
        $response->assertOk();
        expect($response->headers->get('content-disposition'))->toContain('attachment')->toContain($extension);
    }
});
