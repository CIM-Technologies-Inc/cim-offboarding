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

// ---------------------------------------------------------------------
// New task items shown at the top, saved in their original order
// ---------------------------------------------------------------------

it('renders new task items at the top while submitting each under its save position', function () {
    $user = checklistEditor();
    $template = ChecklistTemplate::forceCreate(['title' => 'IT Checklist', 'clearance_signing_deadline_days' => 19]);

    foreach ([route('checklist-templates.create'), route('checklist-templates.edit', $template)] as $url) {
        $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

        expect($html)->toContain('this.items.unshift({')
            ->toContain(':key="item._uid"')
            ->toContain('items[${submitIndex(item)}][title]')
            ->toContain('items[${submitIndex(item)}][signatory_id]')
            ->not->toContain('items[${index}]')
            ->toContain('data-task-item');
    }
});

it('saves items in creation order even when the page submits the newest ones first', function () {
    $assigneeA = \App\Models\Employee::factory()->create();
    $assigneeC = \App\Models\Employee::factory()->create();

    // Page order (newest on top): key 2 "Third" first, then 0 "First", 1 "Second".
    $this->actingAs(checklistEditor())->post(route('checklist-templates.store'), [
        'title' => 'Ordered Checklist', 'employee_group_id' => '', 'checklist_classification' => 'primary',
        'due_in_days' => '', 'clearance_signing_deadline_days' => 19,
        'items' => [
            2 => ['title' => 'Third', 'signatory_id' => $assigneeC->id],
            0 => ['title' => 'First', 'signatory_id' => $assigneeA->id],
            1 => ['title' => 'Second'],
        ],
    ])->assertSessionHasNoErrors();

    $items = ChecklistTemplate::where('title', 'Ordered Checklist')->sole()->items()->get();
    expect($items->pluck('title')->all())->toBe(['First', 'Second', 'Third'])
        ->and($items->pluck('sort_order')->all())->toBe([0, 1, 2])
        ->and($items[0]->signatory_id)->toBe($assigneeA->id)
        ->and($items[1]->signatory_id)->toBeNull()
        ->and($items[2]->signatory_id)->toBe($assigneeC->id);
});

it('adds new items after the existing ones on edit, keeping existing items and their settings unchanged', function () {
    $template = ChecklistTemplate::forceCreate(['title' => 'HR Checklist', 'clearance_signing_deadline_days' => 19, 'sequence_type' => 'primary']);
    $exit = $template->items()->create(['title' => 'Exit interview', 'sort_order' => 0]);
    $id = $template->items()->create(['title' => 'Return ID', 'sort_order' => 1]);

    // Two new items shown on top (submitted first), existing ones below.
    $this->actingAs(checklistEditor())->put(route('checklist-templates.update', $template), [
        'title' => 'HR Checklist', 'employee_group_id' => '', 'checklist_classification' => 'primary',
        'due_in_days' => '', 'clearance_signing_deadline_days' => 19,
        'items' => [
            3 => ['title' => 'Newest'],
            2 => ['title' => 'Newer'],
            0 => ['id' => $exit->id, 'title' => 'Exit interview'],
            1 => ['id' => $id->id, 'title' => 'Return ID'],
        ],
    ])->assertSessionHasNoErrors();

    $items = $template->fresh()->items()->get();
    expect($items->pluck('title')->all())->toBe(['Exit interview', 'Return ID', 'Newer', 'Newest'])
        // Existing rows updated in place (same ids), not recreated.
        ->and($items[0]->id)->toBe($exit->id)
        ->and($items[1]->id)->toBe($id->id);
});

it('restores the saved order when the form is redisplayed after a validation error', function () {
    $user = checklistEditor();

    // Submitted newest-first, with a validation error (non-existent assignee on key 2).
    $this->actingAs($user)->from(route('checklist-templates.create'))->post(route('checklist-templates.store'), [
        'title' => 'Broken', 'employee_group_id' => '', 'checklist_classification' => 'primary', 'due_in_days' => '', 'clearance_signing_deadline_days' => 19,
        'items' => [2 => ['title' => 'Zeta', 'signatory_id' => 999999], 0 => ['title' => 'Alpha'], 1 => ['title' => 'Beta']],
    ])->assertSessionHasErrors('items.2.signatory_id');

    $html = $this->actingAs($user)->get(route('checklist-templates.create'))->assertOk()->getContent();
    // Alpha, Beta, Zeta — key (save) order, not the page order it was submitted in.
    expect(strpos($html, 'Alpha'))->toBeLessThan(strpos($html, 'Beta'))
        ->and(strpos($html, 'Beta'))->toBeLessThan(strpos($html, 'Zeta'));
});
