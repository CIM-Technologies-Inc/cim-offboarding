<?php

use App\Models\ChecklistTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

/*
 * Checklist Template create/edit after the checklist-level "Schedule
 * Email/Notification" section was removed.
 *
 *   DB_CONNECTION=mysql DB_DATABASE=cim_db_test vendor/bin/pest tests/Feature/ChecklistTemplateFormTest.php
 */

uses(RefreshDatabase::class);

function checklistEditor(): User
{
    $permissions = ['offboarding-checklists.create', 'offboarding-checklists.view', 'offboarding-checklists.edit'];
    foreach ($permissions as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

it('no longer shows the Schedule Email/Notification section on create or edit, but keeps the other fields', function () {
    $user = checklistEditor();
    $template = ChecklistTemplate::forceCreate(['title' => 'IT Checklist', 'clearance_signing_deadline_days' => 19, 'due_in_days' => 3]);

    foreach ([route('checklist-templates.create'), route('checklist-templates.edit', $template)] as $url) {
        $this->actingAs($user)->get($url)->assertOk()
            ->assertDontSee('Schedule Email/Notification')
            ->assertDontSee('name="notification_enabled"', false)
            ->assertSee('name="clearance_signing_deadline_days"', false)
            ->assertSee('name="due_in_days"', false)
            ->assertSee('Enable Scheduled Notification');
    }
});

it('creates a checklist template without any notification schedule settings, keeping the Clearance Signing Deadline', function () {
    $this->actingAs(checklistEditor())->post(route('checklist-templates.store'), [
        'title' => 'Finance Checklist',
        'employee_group_id' => '',
        'checklist_classification' => 'primary',
        'due_in_days' => 5,
        'clearance_signing_deadline_days' => 21,
        'items' => [['title' => 'Return company card']],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $created = ChecklistTemplate::where('title', 'Finance Checklist')->firstOrFail();

    expect($created->clearance_signing_deadline_days)->toBe(21)
        ->and($created->due_in_days)->toBe(5)
        ->and((bool) $created->notification_enabled)->toBeFalse()
        ->and($created->items()->pluck('title')->all())->toBe(['Return company card']);
});

it('updates an existing checklist template without touching or requiring the removed settings', function () {
    $template = ChecklistTemplate::forceCreate(['title' => 'HR Checklist', 'clearance_signing_deadline_days' => 19, 'sequence_type' => 'primary']);
    $item = $template->items()->create(['title' => 'Exit interview', 'sort_order' => 0]);

    $this->actingAs(checklistEditor())->put(route('checklist-templates.update', $template), [
        'title' => 'HR Checklist',
        'employee_group_id' => '',
        'checklist_classification' => 'primary',
        'due_in_days' => '',
        'clearance_signing_deadline_days' => 15,
        'items' => [['id' => $item->id, 'title' => 'Exit interview']],
        // Stale fields from an old form are ignored, never saved.
        'notification_enabled' => '1',
        'notification_days_before' => '3',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $template->refresh();
    expect($template->clearance_signing_deadline_days)->toBe(15)
        ->and((bool) $template->notification_enabled)->toBeFalse()
        ->and($template->notification_days_before)->toBeNull();
});
