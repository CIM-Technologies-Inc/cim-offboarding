<?php

use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Email and Notification page layout: Template List | Create/Edit Form |
 * Available Variables (the old Employees list is gone).
 *
 *   DB_CONNECTION=mysql DB_DATABASE=cim_db_test vendor/bin/pest tests/Feature/EmailTemplateWorkspaceLayoutTest.php
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $employee = Employee::factory()->create(['name' => 'Layout Admin']);
    $this->admin = User::factory()->create(['username' => $employee->employee_code_digits]);
    $this->admin->forceFill(['must_change_password' => false])->save();
    $this->admin->assignRole(User::ROLE_ADMIN);
});

/** The rendered Available Variables section (third column). */
function variablesSection(string $html): string
{
    $start = strpos($html, 'RIGHT (25%): Available Variables');
    $end = strpos($html, 'VIEW TEMPLATE MODAL', $start);

    return substr($html, $start, $end - $start);
}

it('shows Template List, the form, then Available Variables — and no Employees list', function () {
    Employee::factory()->create(['name' => 'Zed Recipient', 'email' => 'zed@company.com']);
    $template = EmailTemplate::forceCreate(['template_name' => 'Custom One', 'subject' => 'S', 'html_content' => '<p>x</p>', 'is_active' => true]);

    foreach ([route('email-templates.create'), route('email-templates.edit', $template)] as $url) {
        $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();

        expect($html)
            // Employees panel and its drag/insert-a-name controls are gone…
            ->not->toContain('Drag a name onto the email body')
            ->not->toContain('insertEmployeeName(')
            ->not->toContain('undoDraggedNames()')
            ->not->toContain('handleEmployeeDrop(')
            // …Available Variables exists exactly once, after the form's submit button.
            ->and(substr_count($html, '>Available Variables</h4>'))->toBe(1)
            ->and(strpos($html, '>Available Variables</h4>'))->toBeGreaterThan(strpos($html, 'type="submit" data-turbo-submits-with="Saving..."'))
            ->and(substr_count($html, 'variablesExpanded'))->toBe(0)
            // The recipient picker (which still uses employee data) is intact.
            ->and($html)->toContain('zed@company.com')
            ->toContain('name="recipient_emails[]"');
    }
});

it('lists only variables that the email renderer actually replaces', function () {
    $html = $this->actingAs($this->admin)->get(route('email-templates.create'))->assertOk()->getContent();

    preg_match_all('/<code[^>]*>\{\{([a-z_]+)\}\}<\/code>/', variablesSection($html), $matches);
    $variables = array_unique($matches[1]);

    expect(count($variables))->toBeGreaterThan(20)
        ->and($variables)->toContain('checklist_name')->toContain('escalation_reason');

    foreach ($variables as $variable) {
        $template = new EmailTemplate(['subject' => "[{{{$variable}}}]", 'html_content' => "<p>{{{$variable}}}</p>"]);
        [$subject, $body] = $template->render(approverName: 'A', offboardeeName: 'B');

        expect($subject.$body)->not->toContain('{{', "{{{$variable}}} is listed but not replaced by EmailTemplate::render()");
    }
});

it('still creates and edits templates with recipients after the layout change', function () {
    $recipient = Employee::factory()->create(['email' => 'cc.person@company.com']);

    $this->actingAs($this->admin)->post(route('email-templates.store'), [
        'template_name' => 'Layout Check', 'subject' => 'Hello {{employee_name}}', 'html_content' => '<p>{{checklist_name}}</p>',
        'recipient_employee_ids' => [$recipient->id], 'recipient_emails' => ['outside@gmail.com'],
    ])->assertSessionHasNoErrors();

    $template = EmailTemplate::where('template_name', 'Layout Check')->sole();
    expect($template->recipient_emails)->toBe(['outside@gmail.com'])
        ->and($template->recipientEmployees()->pluck('employees.id')->all())->toBe([$recipient->id]);

    $this->actingAs($this->admin)->put(route('email-templates.update', $template), [
        'template_name' => 'Layout Check', 'subject' => 'Updated', 'html_content' => '<p>x</p>',
    ])->assertSessionHasNoErrors();
    expect($template->fresh()->subject)->toBe('Updated');
});

// ---------------------------------------------------------------------
// Customized templates: body preview in the template list
// ---------------------------------------------------------------------

it('builds a clean plain-text preview of the email body, keeping variables and truncating long bodies', function () {
    $preview = fn (?string $html, int $limit = 150) => (new EmailTemplate(['html_content' => $html]))->bodyPreview($limit);

    expect($preview('Plain reminder text.'))->toBe('Plain reminder text.')
        ->and($preview('<p>Hello&nbsp;<strong>{{approver_name}}</strong>,</p><p>Your <em>{{checklist_name}}</em> for {{employee_name}} is due.</p>'))
        ->toBe('Hello {{approver_name}}, Your {{checklist_name}} for {{employee_name}} is due.')
        ->and($preview('<style>p{color:red}</style><script>alert(1)</script><p>Safe &amp; sound</p>'))->toBe('Safe & sound')
        ->and($preview('<p>Line one<br>Line two</p><table><tr><td>Cell</td></tr></table>'))->toBe('Line one Line two Cell')
        ->and($preview(str_repeat('word ', 60), 50))->toEndWith('...')
        ->and(mb_strlen($preview(str_repeat("word ", 60), 50)))->toBeLessThanOrEqual(53)
        ->and($preview(''))->toBeNull()
        ->and($preview(null))->toBeNull()
        ->and($preview('<p>&nbsp;</p><p><br></p>'))->toBeNull();
});

it('shows each Customized template\'s body preview (and refreshes it after saving), leaving System templates\' descriptions', function () {
    $custom = EmailTemplate::forceCreate(['template_name' => 'Exit Interview', 'subject' => 'S', 'is_active' => true,
        'html_content' => '<p>Hi <b>{{employee_name}}</b>, please book your exit interview.</p><script>alert("x")</script>']);
    $empty = EmailTemplate::forceCreate(['template_name' => 'Empty Body', 'subject' => 'S', 'html_content' => '', 'is_active' => true]);

    $html = $this->actingAs($this->admin)->get(route('email-templates.create'))->assertOk()->getContent();

    expect($html)->toContain('Hi {{employee_name}}, please book your exit interview.')
        ->toContain('No email body available.')
        // The script in the body never reaches the list as markup.
        ->not->toContain('alert("x")')
        // System templates keep their own purpose text.
        ->toContain('Tells a Task Assignee which checklist item(s)');

    // Edit + save the body -> the list shows the new preview.
    $this->actingAs($this->admin)->put(route('email-templates.update', $custom), [
        'template_name' => 'Exit Interview', 'subject' => 'S', 'html_content' => '<p>Updated: your clearance for {{checklist_name}} is ready.</p>',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->get(route('email-templates.create'))->assertOk()
        ->assertSee('Updated: your clearance for {{checklist_name}} is ready.')
        ->assertDontSee('please book your exit interview');

    expect($custom->fresh()->html_content)->toBe('<p>Updated: your clearance for {{checklist_name}} is ready.</p>');
});

// ---------------------------------------------------------------------
// Drag-and-drop of Available Variables into the form
// ---------------------------------------------------------------------

it('makes every listed variable draggable with its exact token, and marks the right drop targets', function () {
    $html = $this->actingAs($this->admin)->get(route('email-templates.create'))->assertOk()->getContent();
    $section = variablesSection($html);

    preg_match_all('/<code[^>]*>\{\{([a-z_]+)\}\}<\/code>/', $section, $listed);
    preg_match_all("/setData\('application\/x-email-variable', '\{\{([a-z_]+)\}\}'\)/", $section, $draggable);

    expect(count($listed[1]))->toBeGreaterThan(20)
        // Every listed variable is draggable, carrying exactly its own token.
        ->and($draggable[1])->toBe($listed[1])
        ->and(substr_count($section, 'draggable="true" data-email-variable'))->toBe(count($listed[1]));

    // Drop targets: Template Name + Subject (browser inserts at the caret), Summernote body (handled), Recipients refused.
    expect($html)->toMatch('/name="template_name" required\s+data-variable-drop/')
        ->toContain('name="subject" id="templateSubject" required data-variable-drop')
        ->toContain("closest('[data-variable-drop], .note-editor')")
        ->toContain("\$note.summernote('insertText', variable)")
        ->toContain('x-model="recipientQuery" data-variable-no-drop')
        ->toContain('@variable-drop-refused.window=');
});

it('does not offer the read-only Template Name of a System template as a drop target', function () {
    $system = EmailTemplate::where('template_name', 'Offboarding Checklist Assigned to You')->first()
        ?? EmailTemplate::forceCreate(['template_name' => 'Offboarding Checklist Assigned to You', 'subject' => 'S', 'html_content' => '<p>x</p>', 'is_active' => true]);

    $html = $this->actingAs($this->admin)->get(route('email-templates.edit', $system))->assertOk()->getContent();

    expect($html)->not->toMatch('/name="template_name" required\s+data-variable-drop/')
        ->toContain('name="subject" id="templateSubject" required data-variable-drop');
});

it('keeps dropped variables exactly as typed at the start, middle and end, and resolves them only when the email is generated', function () {
    $this->actingAs($this->admin)->post(route('email-templates.store'), [
        'template_name' => '{{checklist_name}} Reminder',
        'subject' => '{{employee_name}} — action needed on {{checklist_name}} by {{due_date}}',
        'html_content' => '<p>{{approver_name}}, please <strong>review</strong> {{checklist_name}} for {{employee_name}}</p>',
    ])->assertSessionHasNoErrors();

    $template = EmailTemplate::where('template_name', '{{checklist_name}} Reminder')->sole();
    expect($template->subject)->toBe('{{employee_name}} — action needed on {{checklist_name}} by {{due_date}}')
        ->and($template->html_content)->toBe('<p>{{approver_name}}, please <strong>review</strong> {{checklist_name}} for {{employee_name}}</p>');

    [$subject, $body] = $template->render(approverName: 'Ana', offboardeeName: 'Ben Cruz', checklistName: 'IT Clearance', dueDate: 'Nov 02, 2026');
    expect($subject)->toBe('Ben Cruz — action needed on IT Clearance by Nov 02, 2026')
        ->and($body)->toBe('<p>Ana, please <strong>review</strong> IT Clearance for Ben Cruz</p>');
});
