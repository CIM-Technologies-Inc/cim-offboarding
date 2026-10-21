<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmailTemplateRequest;
use App\Http\Requests\UpdateEmailTemplateRequest;
use App\Models\Employee;
use App\Models\EmailTemplate;
use App\Models\EmployeeGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EmailTemplateController extends Controller
{
    /**
     * Short, factual purpose/trigger/recipient copy for the workspace list
     * — written once from the actual trigger code, not inferred at render
     * time. Every name in `EmailTemplate::SYSTEM_TEMPLATE_NAMES` has an
     * entry here; a Customizable template not listed here (including any
     * future admin-created one) falls back to the generic description in
     * `templateMetadataFor()`.
     */
    private const TEMPLATE_METADATA = [
        'Offboarding Request Notification' => [
            'purpose' => 'Notifies each Department/Immediate Head that a new offboarding checklist needs their attention.',
            'trigger' => 'Automatically sent when a new offboarding request is created (and again when later checklist stages attach).',
            'recipients' => 'Department Head / Immediate Head of each applicable checklist.',
        ],
        'Offboarding Details Notification – Employee' => [
            'purpose' => 'Tells the employee being offboarded that their offboarding has started, with their checklist summary and login details.',
            'trigger' => 'Automatically sent when a new offboarding request is created.',
            'recipients' => 'The offboardee.',
        ],
        'Employee Offboarding Follow-Up Notification' => [
            'purpose' => 'Lets HR know an employee followed up on a checklist that is still pending.',
            'trigger' => 'Sent when the offboardee clicks "Follow Up" on their dashboard (cooldown-limited).',
            'recipients' => 'The offboarding request\'s creator.',
        ],
        'General Signatory Offboarding Notification' => [
            'purpose' => 'Notifies a General Signatory\'s own Clearance Signatory that a General Signatory checklist tier has been attached.',
            'trigger' => 'Automatically sent at request creation and later checklist stages.',
            'recipients' => 'Each General Signatory\'s Clearance Signatory.',
        ],
        'Final Pay Checklist Approval' => [
            'purpose' => 'Tells Department Heads their Final Pay checklist is ready once every regular checklist is approved.',
            'trigger' => 'Automatically sent once every regular checklist and General Signatory tier is approved.',
            'recipients' => 'Department Head (or Immediate Head) of each attached Final Pay checklist.',
        ],
        'Final Pay Checklist Completed' => [
            'purpose' => 'Confirms the entire offboarding process is complete.',
            'trigger' => 'Automatically sent once the request transitions to "completed".',
            'recipients' => 'All Admins and the request\'s creator.',
        ],
        'Offboarding Overdue Notice' => [
            'purpose' => 'Flags a checklist that has passed its due date.',
            'trigger' => 'Scheduled (every 5 minutes) once overdue and not yet flagged; also sendable on demand via "Notify Approver".',
            'recipients' => 'The checklist\'s Department Head.',
        ],
        'Clearance Signing Due Reached' => [
            'purpose' => 'Reminds a signatory that their clearance signing deadline has arrived.',
            'trigger' => 'Scheduled (every 5 minutes) once the clearance signing due date passes.',
            'recipients' => 'The assigned Clearance Signatory or General Signatory only — never admins.',
        ],
        'Offboarding Reminder' => [
            'purpose' => 'A manual nudge for a still-pending, not-yet-overdue checklist.',
            'trigger' => 'Sent on demand when an Admin clicks "Notify Approver".',
            'recipients' => 'The checklist\'s Clearance Signatory (or its Task Assignees, if configured).',
        ],
        'Checklist Item Pending Head Approval' => [
            'purpose' => 'Tells a Department/Group Head that an item under their group now needs their sign-off.',
            'trigger' => 'Automatically sent the moment that item-level approval gate opens.',
            'recipients' => 'The Department/Group Head.',
        ],
        'Final Approval Request' => [
            'purpose' => 'Asks the Final Signatory to give final approval, with the Clearance Form attached.',
            'trigger' => 'Sent when an Admin triggers Final Approval once the request is fully processed.',
            'recipients' => 'The active Final Signatory.',
        ],
        'Checklist Due Date Extended' => [
            'purpose' => 'Tells a Clearance Signatory their checklist\'s due date has moved.',
            'trigger' => 'Automatically sent when an Admin uses the bulk "Extend Due" action.',
            'recipients' => 'Clearance Signatories (or Task Assignees) of the extended checklists.',
        ],
        'Offboarding Request Cancelled' => [
            'purpose' => 'Lets everyone involved know a request was retracted or cancelled.',
            'trigger' => 'Automatically sent once a request is cancelled.',
            'recipients' => 'The offboardee, immediate head, every checklist approver/item signatory, and every General Signatory\'s Clearance Signatory.',
        ],
        'Checklist Signatory Declined' => [
            'purpose' => 'Tells the request\'s creator that a signatory declined (placed On Hold) a checklist.',
            'trigger' => 'Automatically sent when a Clearance or General Signatory declines.',
            'recipients' => 'The offboarding request\'s creator.',
        ],
        'User Account Reactivation Notification' => [
            'purpose' => 'Gives a reactivated employee their new temporary login credentials.',
            'trigger' => 'Automatically sent when an Admin reactivates a blocked account.',
            'recipients' => 'The employee.',
        ],
        'Offboarding Announcement' => [
            'purpose' => 'A general-purpose announcement template, available as a manual per-request override.',
            'trigger' => 'Selected manually as a per-request override on the New Offboarding Request form, in place of the default lookup.',
            'recipients' => 'Depends on which override field it\'s assigned to (Approver or Offboardee).',
        ],
        'Checklist Ready for Department Head Approval' => [
            'purpose' => 'A manually-selectable notice that a checklist is ready for a Department Head\'s review.',
            'trigger' => 'Selected manually from the "Notify Approver" reminder picker.',
            'recipients' => 'Whoever the admin sends it to from that picker.',
        ],
        'Checklist Schedule Email/Notification' => [
            'purpose' => 'A repeating reminder for a specific checklist\'s own signatory and task assignees.',
            'trigger' => 'Assigned to a checklist\'s own "Schedule Email/Notification" setting; sent on the configured cycle.',
            'recipients' => 'That checklist\'s Clearance Signatory (or Immediate Head), Task Assignee(s), and monitoring Department Head.',
        ],
    ];

    /**
     * @return array{purpose: string, trigger: string, recipients: string}
     */
    public static function templateMetadataFor(EmailTemplate $template): array
    {
        return self::TEMPLATE_METADATA[$template->template_name] ?? [
            'purpose' => 'Created and managed directly by an administrator.',
            'trigger' => 'Used wherever an admin selects it (a per-request override, a checklist\'s schedule setting, or a reminder picker).',
            'recipients' => 'Depends on where it\'s selected.',
        ];
    }

    public function index(): View
    {
        return $this->workspace(null);
    }

    public function create(): View
    {
        return $this->workspace(null);
    }

    public function edit(EmailTemplate $emailTemplate): View
    {
        $emailTemplate->load('recipientEmployees:id', 'recipientGroups:id', 'schedules');

        return $this->workspace($emailTemplate);
    }

    private function workspace(?EmailTemplate $template): View
    {
        $templates = EmailTemplate::with('schedules')->latest('updated_at')->get();

        return view('pages.email-templates.workspace', [
            'title' => 'Email and Notification',
            'systemTemplates' => $templates->filter(fn (EmailTemplate $t) => $t->isSystemDefault())->values(),
            'customTemplates' => $templates->reject(fn (EmailTemplate $t) => $t->isSystemDefault())->values(),
            'template' => $template,
            // `employee_code`/`email` are only needed by the new Recipients
            // / CC picker below — the existing drag-into-body-text picker
            // on the right simply ignores the extra keys.
            'employees' => Employee::orderBy('name')->get(['id', 'name', 'department', 'employee_code', 'email']),
            'employeeGroups' => $this->recipientGroupOptions(),
        ]);
    }

    /**
     * Every active Employee Master group, each with its full member list —
     * what the Recipients / CC picker offers (and uses client-side to keep
     * someone from being added individually when they're already covered
     * by a selected group). Deliberately not `ChecklistTemplateController::employeeGroupsForPicker()`:
     * that one filters members down to Task-Assignee-flagged employees
     * only, which doesn't apply here — every member of a selected group
     * should be CC-able.
     */
    private function recipientGroupOptions()
    {
        return EmployeeGroup::where('is_active', true)
            ->with('employees:id,employee_group_id')
            ->orderBy('name')
            ->get()
            ->map(fn (EmployeeGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'employeeIds' => $group->employees->pluck('id'),
            ])
            ->values();
    }

    public function store(StoreEmailTemplateRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        // Relationship data, not columns on `email_templates` itself — pull
        // out before the Eloquent create() below, applied via sync()/syncSchedules() after.
        $recipientEmployeeIds = $validated['recipient_employee_ids'] ?? [];
        $recipientGroupIds = $validated['recipient_group_ids'] ?? [];
        $schedules = $validated['schedules'] ?? [];
        unset($validated['recipient_employee_ids'], $validated['recipient_group_ids'], $validated['schedules']);

        DB::transaction(function () use ($request, $validated, $recipientEmployeeIds, $recipientGroupIds, $schedules) {
            if ($validated['is_default_announcement']) {
                $this->clearOtherDefaultAnnouncements();
            }

            if ($validated['is_default_reactivation']) {
                $this->clearOtherDefaultReactivations();
            }

            $template = EmailTemplate::create($validated + [
                'created_by' => $request->user()->id,
            ]);

            $template->recipientEmployees()->sync($recipientEmployeeIds);
            $template->recipientGroups()->sync($recipientGroupIds);
            $this->syncSchedules($template, $schedules, $request->user()->id);
        });

        return redirect()->route('email-templates.create')
            ->with('success', 'Template saved successfully.');
    }

    public function update(UpdateEmailTemplateRequest $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        $validated = $request->validated();
        $recipientEmployeeIds = $validated['recipient_employee_ids'] ?? [];
        $recipientGroupIds = $validated['recipient_group_ids'] ?? [];
        $schedules = $validated['schedules'] ?? [];
        unset($validated['recipient_employee_ids'], $validated['recipient_group_ids'], $validated['schedules']);

        // A system template's `template_name` (and, for the one template
        // whose trigger IS the `is_default_reactivation` flag rather than
        // its name, that flag) is locked server-side regardless of what's
        // submitted — the form already disables these fields, so this only
        // matters against a direct/adversarial request. Subject and body
        // stay fully editable either way; only the identity/trigger-bearing
        // fields are protected.
        if ($emailTemplate->isSystemDefault()) {
            $validated['template_name'] = $emailTemplate->template_name;

            if ($emailTemplate->is_default_reactivation) {
                $validated['is_default_reactivation'] = true;
            }
        }

        DB::transaction(function () use ($request, $validated, $emailTemplate, $recipientEmployeeIds, $recipientGroupIds, $schedules) {
            if ($validated['is_default_announcement']) {
                $this->clearOtherDefaultAnnouncements($emailTemplate->id);
            }

            if ($validated['is_default_reactivation']) {
                $this->clearOtherDefaultReactivations($emailTemplate->id);
            }

            $emailTemplate->update($validated);
            $emailTemplate->recipientEmployees()->sync($recipientEmployeeIds);
            $emailTemplate->recipientGroups()->sync($recipientGroupIds);
            $this->syncSchedules($emailTemplate, $schedules, $request->user()->id);
        });

        return redirect()->route('email-templates.create')
            ->with('success', 'Template updated successfully.');
    }

    /**
     * Applies the submitted Email Notification Schedule Timeline rows: an
     * `id` that belongs to this template is updated (or deleted, if
     * `_destroy` is set) rather than trusted blindly — an `id` that doesn't
     * belong to this template (whether mistaken or adversarial) is silently
     * ignored, never updated/deleted. A row with no `id` is a new schedule.
     *
     * @param  array<int, array<string, mixed>>  $schedules
     */
    private function syncSchedules(EmailTemplate $template, array $schedules, int $userId): void
    {
        $existingIds = $template->schedules()->pluck('id', 'id');

        foreach ($schedules as $row) {
            $id = $row['id'] ?? null;

            if ($id && ! $existingIds->has($id)) {
                continue;
            }

            if ($id && ! empty($row['_destroy'])) {
                $template->schedules()->whereKey($id)->delete();

                continue;
            }

            $attributes = [
                'timing' => $row['timing'],
                'days' => $row['days'],
                'channel' => $row['channel'],
            ];

            if ($id) {
                $template->schedules()->whereKey($id)->update($attributes);
            } else {
                $template->schedules()->create($attributes + ['created_by' => $userId]);
            }
        }
    }

    /**
     * Only one template may be the default Offboarding Announcement
     * template — enforced here rather than relying on the checkbox alone,
     * since a client could submit `is_default_announcement=true` for more
     * than one template.
     */
    private function clearOtherDefaultAnnouncements(?int $exceptId = null): void
    {
        EmailTemplate::where('is_default_announcement', true)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->update(['is_default_announcement' => false]);
    }

    /**
     * Only one template may be the default Account Reactivation template —
     * same enforcement as `clearOtherDefaultAnnouncements()` above, for the
     * same reason (a client could otherwise submit
     * `is_default_reactivation=true` for more than one template).
     */
    private function clearOtherDefaultReactivations(?int $exceptId = null): void
    {
        EmailTemplate::where('is_default_reactivation', true)
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->update(['is_default_reactivation' => false]);
    }

    public function destroy(EmailTemplate $emailTemplate): RedirectResponse
    {
        if ($emailTemplate->isSystemDefault()) {
            return redirect()->route('email-templates.index')
                ->with('error', 'System templates cannot be deleted — "' . $emailTemplate->template_name . '" is used automatically by the application and must stay available. Deactivate it instead if you want to stop it from sending.');
        }

        $emailTemplate->delete();

        return redirect()->route('email-templates.index')
            ->with('success', 'Template deleted successfully.');
    }

    public function toggleStatus(Request $request, EmailTemplate $emailTemplate): RedirectResponse|JsonResponse
    {
        $emailTemplate->update(['is_active' => ! $emailTemplate->is_active]);

        $message = 'Template marked as ' . ($emailTemplate->is_active ? 'active' : 'inactive') . '.';

        if ($request->wantsJson()) {
            return response()->json([
                'is_active' => $emailTemplate->is_active,
                'message' => $message,
            ]);
        }

        return redirect()->back()->with('success', $message);
    }
}
