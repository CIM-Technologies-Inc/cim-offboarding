<?php

use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\ChecklistItem;
use App\Models\ChecklistItemAssignment;
use App\Models\ChecklistItemScheduledSend;
use App\Models\ChecklistTemplate;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\FinalApprover;
use App\Models\GeneralSignatory;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use App\Models\SeparationType;
use App\Models\TaskExemption;
use App\Models\User;
use App\Notifications\ChecklistOverdueNotification;
use App\Services\OffboardingRequestPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/*
 * Task Exemption — rule management, matching, the per-request snapshot,
 * and exclusion of exempted tasks from the workflow and every notification.
 *
 *   DB_CONNECTION=mysql DB_DATABASE=cim_db_test vendor/bin/pest tests/Feature/TaskExemptionTest.php
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();

    // Isolate from whatever checklists/General Signatories the migrations seed.
    ChecklistTemplate::query()->update(['is_active' => false]);
    GeneralSignatory::query()->update(['is_active' => false]);

    FinalApprover::query()->update(['is_active' => false]);
    FinalApprover::forceCreate(['employee_id' => Employee::factory()->create()->id, 'is_active' => true]);

    $this->admin = teAdmin();

    $this->itHead = Employee::factory()->create(['name' => 'Ian ItHead', 'department' => 'Information Services', 'email' => 'it.head@example.com']);
    $this->assigneeA = Employee::factory()->create(['name' => 'Ana Assignee', 'department' => 'Information Services', 'email' => 'ana@example.com']);
    $this->assigneeB = Employee::factory()->create(['name' => 'Ben Assignee', 'department' => 'Information Services', 'email' => 'ben@example.com']);
    $this->adminHead = Employee::factory()->create(['name' => 'Ada AdminHead', 'department' => 'Admin and Operations', 'email' => 'admin.head@example.com']);

    $this->it = ChecklistTemplate::forceCreate(['title' => 'IT Clearance Checklist', 'department_head_id' => $this->itHead->id, 'is_active' => true, 'is_final_pay_checklist' => false, 'use_task_assignee_as_signatory' => false, 'sequence_type' => 'primary', 'due_in_days' => 2]);
    $this->laptop = $this->it->items()->create(['title' => 'Laptop Return', 'signatory_id' => $this->assigneeA->id, 'sort_order' => 1]);
    $this->companyId = $this->it->items()->create(['title' => 'Company ID Return', 'signatory_id' => $this->assigneeA->id, 'sort_order' => 2]);
    $this->systemAccess = $this->it->items()->create(['title' => 'System Access Revocation', 'signatory_id' => $this->assigneeB->id, 'sort_order' => 3]);

    $this->adminChecklist = ChecklistTemplate::forceCreate(['title' => 'Admin Checklist', 'department_head_id' => $this->adminHead->id, 'is_active' => true, 'is_final_pay_checklist' => false, 'use_task_assignee_as_signatory' => false, 'sequence_type' => 'primary']);
    $this->locker = $this->adminChecklist->items()->create(['title' => 'Locker Key Return', 'sort_order' => 1]);
    $this->parking = $this->adminChecklist->items()->create(['title' => 'Parking Sticker Return', 'sort_order' => 2]);

    $this->offboardee = Employee::factory()->create(['name' => 'Olivia Offboardee', 'department' => 'Field Operations', 'designation' => 'Driver', 'position' => 'Driver']);
});

function teAdmin(): User
{
    $employee = Employee::factory()->create(['name' => 'Exemption Admin']);
    $admin = User::factory()->create(['name' => 'Exemption Admin', 'username' => $employee->employee_code_digits]);
    $admin->forceFill(['must_change_password' => false])->save();
    $admin->assignRole(User::ROLE_ADMIN);

    return $admin;
}

function teRule(array $attributes, array $targets, array $items): TaskExemption
{
    $rule = TaskExemption::create($attributes + ['is_active' => true]);

    foreach ($targets as [$type, $value]) {
        $rule->targets()->create($type === 'employee'
            ? ['target_type' => 'employee', 'employee_id' => $value]
            : ['target_type' => $type, 'target_value' => $value]);
    }

    $rule->items()->sync(collect($items)->map(fn (ChecklistItem $item) => $item->id)->all());

    return $rule;
}

function tePayload(Employee $employee): array
{
    return [
        'employee_id' => $employee->id,
        'notice_date' => now()->toDateString(),
        'last_working_day' => now()->addDays(14)->toDateString(),
        'separation_type_id' => SeparationType::value('id'),
    ];
}

function teSubmit($test, Employee $employee): OffboardingRequest
{
    $test->actingAs($test->admin)->post(route('offboarding-requests.store'), tePayload($employee))
        ->assertSessionHasNoErrors();

    return OffboardingRequest::where('employee_id', $employee->id)->latest('id')->firstOrFail();
}

function teApprover(OffboardingRequest $request, ChecklistTemplate $template): ?OffboardingRequestApprover
{
    return OffboardingRequestApprover::where('offboarding_request_id', $request->id)
        ->where('checklist_template_id', $template->id)
        ->first();
}

function teMailsTo(string $email)
{
    return Mail::queued(ChecklistSignatoryAnnouncementMail::class, fn ($mail) => $mail->hasTo($email));
}

// ---- 1. Department --------------------------------------------------------

it('exempts tasks for a matching department and keeps them out of assignments and emails', function () {
    teRule(['name' => 'Field staff', 'reason' => 'No system account'], [['department', 'field operations ']], [$this->systemAccess]);

    $request = teSubmit($this, $this->offboardee);
    $approver = teApprover($request, $this->it);

    $snapshot = $request->exemptedTasks()->sole();
    expect($snapshot->item_title)->toBe('System Access Revocation')
        ->and($snapshot->checklist_title)->toBe('IT Clearance Checklist')
        ->and($snapshot->matched_rules[0]['name'])->toBe('Field staff')
        ->and($snapshot->matched_rules[0]['matched_by'])->toBe(['department'])
        ->and($request->task_exemption_context['department'])->toBe('Field Operations');

    expect($approver->applicableItems()->pluck('title')->all())->toBe(['Laptop Return', 'Company ID Return'])
        // Never assigned to anyone on this request.
        ->and(ChecklistItemAssignment::where('offboarding_request_approver_id', $approver->id)->where('checklist_item_id', $this->systemAccess->id)->exists())->toBeFalse();

    // Ben's only task is exempted — no "assigned to you" email at all.
    expect(teMailsTo('ben@example.com'))->toHaveCount(0);

    // Ana's email lists only her applicable tasks.
    $anaMail = teMailsTo('ana@example.com')->first();
    expect($anaMail)->not->toBeNull()
        ->and($anaMail->emailBody)->toContain('Laptop Return')
        ->and($anaMail->emailBody)->not->toContain('System Access Revocation');

    // The template itself is untouched.
    expect($this->it->items()->count())->toBe(3);
});

// ---- 2. Position -----------------------------------------------------------

it('exempts tasks for a matching position', function () {
    teRule(['name' => 'Drivers'], [['position', 'DRIVER']], [$this->companyId]);

    $request = teSubmit($this, $this->offboardee);

    expect($request->exemptedTasks()->sole()->matched_rules[0]['matched_by'])->toBe(['position'])
        ->and(teApprover($request, $this->it)->applicableItems()->pluck('id')->all())
        ->toBe([$this->laptop->id, $this->systemAccess->id]);
});

// ---- 3. Individual employee ------------------------------------------------

it('exempts tasks only for the targeted employee, not colleagues in the same department and position', function () {
    teRule(['name' => 'Olivia only'], [['employee', $this->offboardee->id]], [$this->systemAccess]);

    $colleague = Employee::factory()->create(['department' => 'Field Operations', 'designation' => 'Driver', 'position' => 'Driver']);

    $oliviaRequest = teSubmit($this, $this->offboardee);
    expect($oliviaRequest->exemptedItemIds()->all())->toBe([$this->systemAccess->id]);
    expect(teMailsTo('ben@example.com'))->toHaveCount(0);

    $colleagueRequest = teSubmit($this, $colleague);
    expect($colleagueRequest->exemptedTasks()->count())->toBe(0)
        ->and(teApprover($colleagueRequest, $this->it)->applicableItems())->toHaveCount(3);

    // Ben still gets his legitimate notification for the colleague's request.
    $benMail = teMailsTo('ben@example.com')->first();
    expect($benMail)->not->toBeNull()
        ->and($benMail->emailBody)->toContain('System Access Revocation');
});

// ---- 4. Multiple matching rules ---------------------------------------------

it('combines multiple matching rules and exempts a shared task only once', function () {
    teRule(['name' => 'Rule A', 'reason' => 'Dept'], [['department', 'Field Operations']], [$this->systemAccess, $this->laptop]);
    teRule(['name' => 'Rule B', 'reason' => 'Personal'], [['employee', $this->offboardee->id]], [$this->systemAccess]);

    $request = teSubmit($this, $this->offboardee);

    expect($request->exemptedTasks()->count())->toBe(2);

    $shared = $request->exemptedTasks()->where('checklist_item_id', $this->systemAccess->id)->sole();
    expect(collect($shared->matched_rules)->pluck('name')->all())->toBe(['Rule A', 'Rule B'])
        ->and(teApprover($request, $this->it)->applicableItems()->pluck('title')->all())->toBe(['Company ID Return']);
});

// ---- 5. Inactive rule & 6. no match -----------------------------------------

it('ignores inactive rules and leaves non-matching offboardees untouched', function () {
    teRule(['name' => 'Inactive', 'is_active' => false], [['department', 'Field Operations']], [$this->laptop]);
    teRule(['name' => 'Other dept'], [['department', 'Marketing']], [$this->companyId]);

    $request = teSubmit($this, $this->offboardee);

    expect($request->exemptedTasks()->count())->toBe(0)
        ->and(teApprover($request, $this->it)->applicableItems())->toHaveCount(3)
        ->and(teApprover($request, $this->adminChecklist))->not->toBeNull();
});

// ---- 7. Department change before (and after) submission --------------------

it('evaluates the offboardee as they are at submission, and freezes the result afterwards', function () {
    teRule(['name' => 'Field staff'], [['department', 'Field Operations']], [$this->systemAccess]);

    $preview = fn () => app(OffboardingRequestPreviewService::class)->preview($this->offboardee->fresh(), tePayload($this->offboardee) + ['approval_mode' => 'async'], [], null);

    expect($preview()['exempted_task_count'])->toBe(1);

    // HR moves them to another department before submitting — preview and
    // submission both recalculate.
    $this->offboardee->update(['department' => 'Marketing']);
    expect($preview()['exempted_task_count'])->toBe(0);

    $request = teSubmit($this, $this->offboardee->fresh());
    expect($request->exemptedTasks()->count())->toBe(0);

    // A later change never alters the submitted request.
    $this->offboardee->update(['department' => 'Field Operations']);
    expect(OffboardingRequest::find($request->id)->exemptedTasks()->count())->toBe(0)
        ->and(teApprover($request, $this->it)->applicableItems())->toHaveCount(3);
});

// ---- 8. Every task of a checklist exempted ----------------------------------

it('does not attach a checklist whose every task is exempted, and never notifies its head', function () {
    teRule(['name' => 'No admin clearance'], [['department', 'Field Operations']], [$this->locker, $this->parking]);

    $preview = app(OffboardingRequestPreviewService::class)->preview($this->offboardee, tePayload($this->offboardee) + ['approval_mode' => 'async'], [], null);
    expect(collect($preview['checklists'])->pluck('title')->all())->toBe(['IT Clearance Checklist'])
        ->and($preview['fully_exempted_checklists'][0]['title'])->toBe('Admin Checklist');

    $request = teSubmit($this, $this->offboardee);

    expect(teApprover($request, $this->adminChecklist))->toBeNull()
        ->and($request->checklistTemplates()->pluck('checklist_templates.id')->all())->toBe([$this->it->id])
        ->and(teMailsTo('admin.head@example.com'))->toHaveCount(0)
        // The IT checklist still attached and notified normally (the "Offboarding
        // Request Notification" head announcement isn't seeded by migrations, so
        // its Task Assignee's email is the reliable signal here).
        ->and(teMailsTo('ana@example.com'))->not->toHaveCount(0);
});

// ---- 9. Rule edited / deactivated / deleted after submission ----------------

it('never changes a submitted request when its rule is edited, deactivated, or deleted', function () {
    $rule = teRule(['name' => 'Field staff', 'reason' => 'Original reason'], [['department', 'Field Operations']], [$this->systemAccess]);
    $request = teSubmit($this, $this->offboardee);

    $this->actingAs($this->admin)->putJson(route('task-exemptions.update', $rule), [
        'departments' => ['Field Operations'], 'item_ids' => [$this->laptop->id, $this->companyId->id],
    ])->assertOk();

    // Editing keeps the rule's existing status/reason (no longer on the form)
    // and regenerates its name from the targets.
    expect($rule->fresh()->only(['name', 'reason', 'is_active']))
        ->toBe(['name' => 'Department: Field Operations', 'reason' => 'Original reason', 'is_active' => true]);

    $rule->update(['is_active' => false]);

    $snapshot = OffboardingRequest::find($request->id)->exemptedTasks()->sole();
    expect($snapshot->item_title)->toBe('System Access Revocation')
        ->and($snapshot->matched_rules[0]['name'])->toBe('Field staff')
        ->and($snapshot->matched_rules[0]['reason'])->toBe('Original reason');

    $this->actingAs($this->admin)->deleteJson(route('task-exemptions.destroy', $rule))->assertOk();

    expect(TaskExemption::count())->toBe(0)
        ->and(OffboardingRequest::find($request->id)->exemptedItemIds()->all())->toBe([$this->systemAccess->id]);
});

// ---- 10. No action/notification/approval requirement for exempted tasks -----

it('rejects any attempt to check an exempted task and completes the checklist without it', function () {
    teRule(['name' => 'Field staff'], [['department', 'Field Operations']], [$this->systemAccess]);
    $request = teSubmit($this, $this->offboardee);
    $approver = teApprover($request, $this->it);

    $this->actingAs($this->admin)->postJson(route('approvals.items.hold', [$approver, $this->systemAccess]), ['reason' => 'x'])
        ->assertStatus(422);

    // Checking only the applicable tasks is enough for the checklist to count as complete.
    foreach ([$this->laptop, $this->companyId] as $item) {
        $approver->itemProgress()->create(['checklist_item_id' => $item->id, 'is_checked' => true, 'checked_at' => now()]);
    }

    expect($approver->fresh()->allItemsCompleted())->toBeTrue();
});

it('keeps exempted tasks out of overdue reminders and scheduled task notifications', function () {
    Notification::fake();

    $template = EmailTemplate::where('template_name', 'Checklist Schedule Email/Notification')->first()
        ?? EmailTemplate::where('is_active', true)->firstOrFail();
    $template->update(['is_active' => true]);
    $this->systemAccess->update(['notify_enabled' => true, 'email_template_id' => $template->id, 'notify_timing' => 'before', 'notify_days' => 30]);
    $this->laptop->update(['notify_enabled' => true, 'email_template_id' => $template->id, 'notify_timing' => 'before', 'notify_days' => 30]);

    teRule(['name' => 'Field staff'], [['department', 'Field Operations']], [$this->systemAccess]);
    $request = teSubmit($this, $this->offboardee);
    $approver = teApprover($request, $this->it);

    // Scheduled per-task notifications: the applicable task sends, the exempted one never does.
    $this->artisan('app:send-scheduled-checklist-item-notifications')->assertSuccessful();
    expect(ChecklistItemScheduledSend::where('offboarding_request_id', $request->id)->pluck('checklist_item_id')->all())->toBe([$this->laptop->id]);

    // Overdue: the exempted task's assignee (Ben) is never pulled in, and the
    // Department Head's pending-items table doesn't list it.
    Mail::fake();
    $approver->update(['due_at' => now()->subDay()]);
    $this->artisan('app:notify-overdue-checklists')->assertSuccessful();

    $benUser = User::firstWhere('username', $this->assigneeB->employee_code_digits);
    if ($benUser) {
        Notification::assertNotSentTo($benUser, ChecklistOverdueNotification::class);
    }

    $headMail = teMailsTo('it.head@example.com')->first();
    if ($headMail) {
        expect($headMail->emailBody)->not->toContain('System Access Revocation');
    }
    expect($approver->fresh()->itemsStatusTableHtml())->not->toContain('System Access Revocation')
        ->and($approver->fresh()->itemsStatusTableHtml())->toContain('Laptop Return');
});

// ---- 11. Preview matches the submitted request ------------------------------

it('previews exactly the exemptions the submission freezes', function () {
    teRule(['name' => 'Field staff', 'reason' => 'No system account'], [['department', 'Field Operations']], [$this->systemAccess]);
    teRule(['name' => 'No admin clearance'], [['position', 'Driver']], [$this->locker, $this->parking]);

    $preview = app(OffboardingRequestPreviewService::class)->preview($this->offboardee, tePayload($this->offboardee) + ['approval_mode' => 'async'], [], null);
    $previewIt = collect($preview['checklists'])->firstWhere('title', 'IT Clearance Checklist');

    $request = teSubmit($this, $this->offboardee);

    $previewedTitles = collect($preview['checklists'])->flatMap(fn ($c) => collect($c['exempted_tasks'])->pluck('item_title'))
        ->concat(collect($preview['fully_exempted_checklists'])->flatMap(fn ($c) => collect($c['exempted_tasks'])->pluck('item_title')))
        ->sort()->values()->all();

    expect($previewedTitles)->toBe($request->exemptedTasks()->pluck('item_title')->sort()->values()->all())
        ->and(collect($previewIt['task_assignees'])->pluck('item_title')->all())->toBe(teApprover($request, $this->it)->applicableItems()->pluck('title')->all())
        ->and($previewIt['exempted_tasks'][0]['rules'][0])->toBe(['name' => 'Field staff', 'reason' => 'No system account', 'matched_by' => ['department']]);
});

// ---- 12. Existing requests unaffected ---------------------------------------

it('leaves requests created before a rule existed completely unchanged', function () {
    $request = teSubmit($this, $this->offboardee);

    teRule(['name' => 'Added later'], [['department', 'Field Operations']], [$this->systemAccess]);

    $approver = teApprover($request, $this->it);
    expect($request->fresh()->exemptedTasks()->count())->toBe(0)
        ->and($approver->applicableItems())->toHaveCount(3)
        ->and(ChecklistItemAssignment::where('offboarding_request_approver_id', $approver->id)->where('checklist_item_id', $this->systemAccess->id)->exists())->toBeTrue();
});

// ---- Management page: CRUD, validation, permissions -------------------------

it('creates, validates, and lists task exemptions', function () {
    $this->actingAs($this->admin)->get(route('task-exemptions.index'))->assertOk()->assertSee('Create Task Exemption');

    $this->actingAs($this->admin)->get(route('task-exemptions.index'))->assertOk()
        ->assertDontSee('Additional Information')
        ->assertDontSee('Exemption Name');

    $this->actingAs($this->admin)->postJson(route('task-exemptions.store'), ['item_ids' => []])
        ->assertStatus(422)->assertJsonValidationErrors(['item_ids']);

    $this->actingAs($this->admin)->postJson(route('task-exemptions.store'), ['item_ids' => [$this->laptop->id]])
        ->assertStatus(422)->assertJsonValidationErrors(['targets']);

    $this->actingAs($this->admin)->postJson(route('task-exemptions.store'), ['departments' => ['Nonexistent Dept'], 'item_ids' => [$this->laptop->id]])
        ->assertStatus(422)->assertJsonValidationErrors(['departments.0']);

    $inactive = ChecklistTemplate::forceCreate(['title' => 'Retired', 'is_active' => false, 'is_final_pay_checklist' => false]);
    $retiredItem = $inactive->items()->create(['title' => 'Old task', 'sort_order' => 1]);
    $this->actingAs($this->admin)->postJson(route('task-exemptions.store'), ['employee_ids' => [$this->offboardee->id], 'item_ids' => [$retiredItem->id]])
        ->assertStatus(422)->assertJsonValidationErrors(['item_ids']);

    $response = $this->actingAs($this->admin)->postJson(route('task-exemptions.store'), [
        'departments' => ['Field Operations'], 'positions' => ['Driver'], 'employee_ids' => [$this->offboardee->id],
        'item_ids' => [$this->systemAccess->id, $this->locker->id],
    ])->assertOk();

    expect($response->json('exemption.departments'))->toBe(['Field Operations'])
        ->and($response->json('exemption.employees.0.id'))->toBe($this->offboardee->id)
        ->and($response->json('exemption.is_active'))->toBeTrue()
        ->and($response->json('exemption.reason'))->toBeNull()
        ->and($response->json('exemption.name'))->toBe('Department: Field Operations · Position: Driver · Employee: Olivia Offboardee')
        ->and(collect($response->json('exemption.checklists'))->pluck('title')->all())->toBe(['Admin Checklist', 'IT Clearance Checklist']);

    // Same targets again is allowed now that names are generated, not unique.
    $this->actingAs($this->admin)->postJson(route('task-exemptions.store'), ['departments' => ['Field Operations'], 'item_ids' => [$this->laptop->id]])
        ->assertOk();
    $this->actingAs($this->admin)->postJson(route('task-exemptions.store'), ['departments' => ['Field Operations'], 'item_ids' => [$this->companyId->id]])
        ->assertOk();
});

it('activates and deactivates a rule from the list switch, affecting only new requests', function () {
    $rule = teRule(['name' => 'Field staff'], [['department', 'Field Operations']], [$this->systemAccess]);

    $this->actingAs($this->admin)->get(route('task-exemptions.index'))->assertOk()->assertSee('role="switch"', false);

    $earlier = teSubmit($this, $this->offboardee);
    expect($earlier->exemptedTasks()->count())->toBe(1);

    $response = $this->actingAs($this->admin)->patchJson(route('task-exemptions.toggle-status', $rule), ['is_active' => false])->assertOk();
    expect($response->json('exemption.is_active'))->toBeFalse()
        ->and($rule->fresh()->is_active)->toBeFalse();

    // Sending the same state again is a harmless no-op (double-click / stale page).
    $this->actingAs($this->admin)->patchJson(route('task-exemptions.toggle-status', $rule), ['is_active' => false])->assertOk();
    expect($rule->fresh()->is_active)->toBeFalse();

    // A deactivated rule no longer applies to a NEW request...
    $colleague = Employee::factory()->create(['department' => 'Field Operations']);
    expect(teSubmit($this, $colleague)->exemptedTasks()->count())->toBe(0)
        // ...and the request submitted while it was active keeps its exemption.
        ->and($earlier->fresh()->exemptedTasks()->count())->toBe(1);

    $this->actingAs($this->admin)->patchJson(route('task-exemptions.toggle-status', $rule), ['is_active' => true])->assertOk();
    $another = Employee::factory()->create(['department' => 'Field Operations']);
    expect(teSubmit($this, $another)->exemptedTasks()->count())->toBe(1);

    $this->actingAs($this->admin)->patchJson(route('task-exemptions.toggle-status', $rule), [])
        ->assertStatus(422)->assertJsonValidationErrors(['is_active']);
});

it('restricts the page and its actions to users with Task Exemption permissions', function () {
    $employee = Employee::factory()->create();
    $user = User::factory()->create(['username' => $employee->employee_code_digits]);
    $user->forceFill(['must_change_password' => false])->save();
    $user->assignRole('employee');

    $rule = teRule(['name' => 'Existing'], [['department', 'Field Operations']], [$this->laptop]);

    $this->actingAs($user)->get(route('task-exemptions.index'))->assertForbidden();
    $this->actingAs($user)->postJson(route('task-exemptions.store'), [])->assertForbidden();
    $this->actingAs($user)->putJson(route('task-exemptions.update', $rule), [])->assertForbidden();
    $this->actingAs($user)->deleteJson(route('task-exemptions.destroy', $rule))->assertForbidden();
    $this->actingAs($user)->patchJson(route('task-exemptions.toggle-status', $rule), ['is_active' => false])->assertForbidden();
    expect($rule->fresh()->is_active)->toBeTrue();
});

// ---- Offboarding Status modal: "Exempted Tasks" tab -------------------------

it('shows exempted tasks in their own Exempted Tasks tab on the HR/Admin Offboarding Status view', function () {
    teRule(['name' => 'Field staff', 'reason' => 'No system account'], [['department', 'Field Operations']], [$this->systemAccess, $this->companyId]);
    teSubmit($this, $this->offboardee);

    $plain = Employee::factory()->create(['name' => 'Nora NoExemption', 'department' => 'Marketing']);
    teSubmit($this, $plain);

    $html = $this->actingAs($this->admin)->get(route('offboardees.index'))->assertOk()->getContent();

    // New tab beside Timeline, with its own panel and empty state…
    expect($html)->toContain("@click=\"activeTab = 'exempted-tasks'\"")
        ->toContain("x-show=\"activeTab === 'exempted-tasks'\"")
        ->toContain('No exempted tasks found for this offboarding record.')
        // …and the section is no longer part of the Offboarding Status tab.
        ->not->toContain("activeTab === 'status' && (selected.exemptedTasks");

    // Each record carries its own exempted tasks (2 vs none) to the modal.
    $offboardees = collect(app(\App\Http\Controllers\OffboardeeController::class)->index(request())->getData()['offboardees'] ?? []);
    $forExempted = $offboardees->first(fn ($o) => ($o['name'] ?? null) === 'Olivia Offboardee');
    $forPlain = $offboardees->first(fn ($o) => ($o['name'] ?? null) === 'Nora NoExemption');

    expect(collect($forExempted['exemptedTasks'])->pluck('item_title')->sort()->values()->all())->toBe(['Company ID Return', 'System Access Revocation'])
        ->and($forExempted['exemptedTasks'][0]['rules'][0])->toBe(['name' => 'Field staff', 'reason' => 'No system account'])
        ->and($forPlain['exemptedTasks'])->toBe([]);
});
