<?php

use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\SeparationType;
use App\Services\OffboardingRequestPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * New Offboarding Request review step — per-checklist Original / Extended
 * Due Date.
 *
 *   DB_CONNECTION=mysql DB_DATABASE=cim_db_test vendor/bin/pest tests/Feature/OffboardingRequestPreviewDueDatesTest.php
 */

uses(RefreshDatabase::class);

it('shows the Last Working Day as the Original Due Date and LWD + the checklist extension as the Extended Due Date', function () {
    ChecklistTemplate::query()->update(['is_active' => false]);
    ChecklistTemplate::forceCreate(['title' => 'IT Checklist', 'is_active' => true, 'is_final_pay_checklist' => false, 'use_task_assignee_as_signatory' => true, 'due_in_days' => 5]);
    ChecklistTemplate::forceCreate(['title' => 'HR Checklist', 'is_active' => true, 'is_final_pay_checklist' => false, 'use_task_assignee_as_signatory' => true, 'due_in_days' => null]);
    ChecklistTemplate::forceCreate(['title' => 'Admin Checklist', 'is_active' => true, 'is_final_pay_checklist' => false, 'use_task_assignee_as_signatory' => true, 'due_in_days' => 1]);

    $employee = Employee::factory()->create(['department' => 'Technical Solutions']);

    $preview = app(OffboardingRequestPreviewService::class)->preview($employee, [
        'separation_type_id' => SeparationType::value('id'),
        'last_working_day' => '2026-11-01',
        'notice_date' => '2026-10-01',
        'approval_mode' => 'async',
    ], [], null);

    $checklists = collect($preview['checklists'])->keyBy('title');

    expect($checklists['IT Checklist']['original_due_at'])->toBe('Nov 01, 2026 11:59 PM')
        ->and($checklists['IT Checklist']['extended_due_at'])->toBe('Nov 06, 2026 11:59 PM')
        ->and($checklists['IT Checklist']['due_date_extension_days'])->toBe(5)
        // No extension configured -> Original is still the LWD, Extended is empty ("No extension applies").
        ->and($checklists['HR Checklist']['original_due_at'])->toBe('Nov 01, 2026 11:59 PM')
        ->and($checklists['HR Checklist']['extended_due_at'])->toBeNull()
        ->and($checklists['Admin Checklist']['extended_due_at'])->toBe('Nov 02, 2026 11:59 PM')
        // The actual due date the request will be created with is unchanged: LWD + extension.
        ->and($checklists['IT Checklist']['due_at'])->toBe('Nov 06, 2026 11:59 PM')
        ->and($checklists['HR Checklist']['due_at'])->toBe('Nov 01, 2026 11:59 PM');
});
