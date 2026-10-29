<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EmailTemplate extends Model
{
    /**
     * Every template name the application's own trigger code looks up by a
     * fixed, hardcoded string (a `private const *_TEMPLATE` or literal
     * `where('template_name', ...)` match in a Controller/Service/Console
     * Command) — see each one's own trigger file for the exact call site.
     * `User Account Reactivation Notification` is the one exception that's
     * looked up by the `is_default_reactivation` flag instead of by name,
     * but it's just as much a fixed system trigger (`UserController::reactivate()`),
     * so it belongs in this list too.
     *
     * Renaming or deleting a template whose name is in this list breaks the
     * workflow that sends it — see `isSystemDefault()`.
     */
    public const SYSTEM_TEMPLATE_NAMES = [
        'Offboarding Request Notification',
        'Offboarding Details Notification – Employee',
        'Employee Offboarding Follow-Up Notification',
        'General Signatory Offboarding Notification',
        'Final Pay Checklist Approval',
        'Final Pay Checklist Completed',
        'Offboarding Overdue Notice',
        'Clearance Signing Due Reached',
        'Offboarding Reminder',
        'Checklist Item Pending Head Approval',
        'Final Approval Request',
        'Checklist Due Date Extended',
        'Offboarding Request Cancelled',
        'Checklist Signatory Declined',
        'User Account Reactivation Notification',
        'Offboarding Checklist Assigned to You',
        // Secondary Clearance Signatory & automatic escalation — looked up by
        // name in `ClearanceSignatoryEscalationService` (its TEMPLATE_*
        // constants).
        'Clearance Signatory Escalation — Assigned to You',
        'Clearance Signatory Responsibility Transferred',
        'Clearance Signatory Escalation — HR/Admin Notice',
        'Clearance Signatory Overdue — HR/Admin Action Required',
        'Clearance Signatory Assigned by HR/Admin',
    ];

    protected $fillable = [
        'template_name',
        'subject',
        'html_content',
        'is_active',
        'is_default_announcement',
        'is_default_reactivation',
        'created_by',
        // Manually entered Recipients (company or personal addresses, not
        // necessarily any employee's) — see `ccRecipientEmails()`.
        'recipient_emails',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default_announcement' => 'boolean',
            'is_default_reactivation' => 'boolean',
            'recipient_emails' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * HR/Admin-configured "N days before/after Last Working Day" schedules
     * — the Email Notification Schedule Timeline feature. Independent of
     * (and additive to) this template's own hardcoded system trigger, if it
     * has one — see `isSystemDefault()`.
     */
    /**
     * Ordered by `id` (creation order), never by computed day-offset — a
     * newly-added schedule must always append at the end of the timeline,
     * regardless of what day count it was configured with. See
     * `workspace.blade.php`'s own `schedules` array, seeded straight from
     * this relation.
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(EmailTemplateSchedule::class)->orderBy('id');
    }

    /**
     * Individually-selected CC recipients — "Recipients / CC" on the
     * Create/Edit Email Template form. See `ccRecipientEmails()` for how
     * these combine with `recipientGroups()` below at actual send time.
     */
    public function recipientEmployees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'email_template_recipient_employees');
    }

    /**
     * Whole Employee Master groups selected as CC recipients — every
     * current member of each selected group is CC'd (resolved fresh at
     * send time, so group membership changes are picked up automatically
     * without re-saving the template).
     */
    public function recipientGroups(): BelongsToMany
    {
        return $this->belongsToMany(EmployeeGroup::class, 'email_template_recipient_groups');
    }

    /**
     * This template's configured CC list — every individually-selected
     * employee plus every current member of every selected group —
     * deduped (an employee picked individually AND via a group is only
     * CC'd once) and filtered down to valid, non-empty email addresses.
     * `$excludePrimaryEmail` keeps whoever is already this specific send's
     * "To" recipient out of the CC list, so nobody gets two copies of the
     * same email. Returns `[]` (a documented no-op for Laravel Mail's
     * `->cc()`) when nothing is configured, so every existing call site
     * behaves exactly as before for every template that doesn't use this.
     *
     * @return array<int, string>
     */
    public function ccRecipientEmails(?string $excludePrimaryEmail = null): array
    {
        $exclude = $excludePrimaryEmail ? strtolower(trim($excludePrimaryEmail)) : null;

        return $this->recipientEmployees->pluck('email')
            ->merge($this->recipientGroups->flatMap(fn (EmployeeGroup $group) => $group->employees->pluck('email')))
            // Manually entered addresses — same filter/dedupe/exclude as the
            // employee-based ones, so every send path (manual or scheduled)
            // includes them with no change of its own.
            ->merge($this->recipient_emails ?? [])
            ->filter(fn (?string $email) => $email && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->map(fn (string $email) => strtolower(trim($email)))
            ->unique()
            ->reject(fn (string $email) => $exclude && $email === $exclude)
            ->values()
            ->all();
    }

    /**
     * Same CC list as `ccRecipientEmails()` above (individually-selected
     * employees plus every current member of every selected group), but
     * returning each employee's NAME instead of their email — for
     * display-only contexts (e.g. the New Offboarding Request review
     * screen) where showing an email address is less useful to an admin
     * than showing who it belongs to. Sending still uses
     * `ccRecipientEmails()`; this never touches `->cc()`.
     *
     * Deduped by employee id (an employee picked individually AND via a
     * group is only listed once), NOT by email like `ccRecipientEmails()`
     * is — that method collapses same-email recipients into one because
     * an inbox only needs one copy of a sent email, but here every
     * configured recipient is a distinct person the admin deliberately
     * added and should be named, even if two employees happen to share an
     * email address (e.g. placeholder data).
     *
     * @return array<int, string>
     */
    public function ccRecipientNames(?string $excludePrimaryEmail = null): array
    {
        $exclude = $excludePrimaryEmail ? strtolower(trim($excludePrimaryEmail)) : null;

        return $this->recipientEmployees
            ->merge($this->recipientGroups->flatMap(fn (EmployeeGroup $group) => $group->employees))
            ->unique('id')
            ->reject(fn (Employee $employee) => $exclude && $employee->email && strtolower(trim($employee->email)) === $exclude)
            // Plain collection from here on: merging the manual addresses
            // (strings) into an Eloquent collection — which an EMPTY
            // employee list still is after map() — calls getKey() on them.
            ->toBase()
            ->map(fn (Employee $employee) => $employee->name)
            // Manually entered addresses have no employee name — shown as
            // the address itself, skipping any already covered above.
            ->merge(collect($this->recipient_emails ?? [])
                ->map(fn (string $email) => strtolower(trim($email)))
                ->reject(fn (string $email) => $email === $exclude
                    || $this->recipientEmployees->merge($this->recipientGroups->flatMap(fn (EmployeeGroup $group) => $group->employees))
                        ->contains(fn (Employee $employee) => $employee->email && strtolower(trim($employee->email)) === $email)))
            ->values()
            ->all();
    }

    /**
     * True when this template is one the application's own code looks up
     * automatically by a fixed name (or, for the reactivation template, by
     * the `is_default_reactivation` flag) — see `SYSTEM_TEMPLATE_NAMES`.
     * Everything else is a Customizable template: freely created, renamed,
     * deleted, and either selected manually (a per-request override, the
     * "Notify Approver" picker) or attached to a specific checklist's own
     * "Schedule Email/Notification" setting.
     */
    public function isSystemDefault(): bool
    {
        return in_array($this->template_name, self::SYSTEM_TEMPLATE_NAMES, true)
            || $this->is_default_reactivation;
    }

    /**
     * A short plain-text excerpt of the email body for the Email and
     * Notification template list — derived from `html_content` itself (no
     * separate description to maintain), so it changes as soon as the body
     * is edited and saved. Scripts/styles are dropped, block breaks become
     * spaces, tags are stripped and entities decoded; placeholders such as
     * {{employee_name}} stay as-is (never resolved here). Null when the body
     * has no readable text. Output is plain text — escape it when rendering.
     */
    public function bodyPreview(int $limit = 150): ?string
    {
        $html = (string) $this->html_content;
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? '';
        $html = preg_replace('#<(br|/p|/div|/li|/tr|/td|/h[1-6])\b[^>]*>#i', ' ', $html) ?? '';

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)) ?? '');

        return $text === '' ? null : Str::limit($text, $limit);
    }

    /**
     * Human-readable summary of this template's active custom schedules for
     * the template list, or null when none are configured — the single
     * schedule's own label when there's exactly one, otherwise a count.
     */
    public function scheduleLabel(): ?string
    {
        $active = $this->schedules->where('is_active', true);

        if ($active->isEmpty()) {
            return null;
        }

        if ($active->count() === 1) {
            return $active->first()->label();
        }

        return "{$active->count()} custom schedules";
    }

    /**
     * The single active template flagged as the default Offboarding
     * Announcement email — used automatically on request submission instead
     * of requiring the submitter to pick one manually.
     */
    public static function activeDefaultAnnouncement(): ?self
    {
        return static::where('is_active', true)
            ->where('is_default_announcement', true)
            ->first();
    }

    /**
     * The single active template flagged as the default Account
     * Reactivation email — used automatically when HR/Admin reactivates a
     * blocked account instead of requiring them to pick one manually. See
     * `UserController::reactivate()`.
     */
    public static function activeDefaultReactivation(): ?self
    {
        return static::where('is_active', true)
            ->where('is_default_reactivation', true)
            ->first();
    }

    /**
     * Renders this template's subject/body, replacing placeholders with the
     * approver's, offboardee's, and request creator's name/link, plus
     * whichever of the optional checklist-specific values the caller has
     * available. Supports the recommended "{{approver_name}}" /
     * "{{employee_name}}" (alias of "{{offboardee_name}}") /
     * "{{employee_number}}" / "{{checklist_name}}" / "{{due_date}}" /
     * "{{offboarding_link}}" tokens, plus "{{department}}" / "{{position}}"
     * / "{{days_overdue}}" / "{{pending_items}}" / "{{checklist_status}}"
     * for the overdue-checklist notification, "{{date_hired}}" /
     * "{{separation_date}}" / "{{reason}}" for the offboarding request
     * notification, "{{request_date}}" / "{{offboarding_status}}" /
     * "{{username}}" / "{{temporary_password}}" / "{{checklist_summary}}"
     * for the Offboarding Details Notification sent to the offboardee
     * themselves, "{{department_head_name}}" / "{{assigned_signatories}}" /
     * "{{checklist_progress}}" / "{{remaining_items}}" /
     * "{{follow_up_sent_at}}" for the employee-initiated Follow-Up
     * notification, "{{general_signatory_tasks}}" for the General
     * Signatory Offboarding Notification — a single pre-rendered HTML blob
     * (heading included) listing that General Signatory's configured
     * tasks, or an empty string when they have none, so a template
     * referencing this token shows no Task List section at all rather than
     * an empty one, and "{{approve_button}}" for that same email — a
     * pre-rendered "Approve" `<a>` button linking the General Signatory's
     * one-click approval, or an empty string once they've already approved
     * (so a template referencing this token never shows a stale/dead
     * button), "{{original_due_date}}" / "{{extension_days}}" /
     * "{{extended_due_date}}" / "{{clearance_signatory_name}}" for the
     * Checklist Due Date Extended notification sent to a checklist's
     * Clearance Signatory once `OffboardingRequestApprover::extendDue()`
     * saves, "{{cancelled_by}}" / "{{cancelled_at}}" / "{{offboarding_request_id}}"
     * / "{{cancellation_reason}}" for the Offboarding Request Cancelled
     * notification sent once `OffboardingRequestController::cancel()`
     * commits (that same call also passes the offboardee's Original Last
     * Working Day via "{{separation_date}}", same generic token every
     * other Last-Working-Day-bearing template already uses), "{{declined_by}}"
     * / "{{declined_at}}" / "{{decline_reason}}" / "{{signatory_type}}" /
     * "{{checklist_type}}" for the Checklist Signatory Declined notification,
     * and "{{completed_at}}" for the Final Pay Checklist Completed
     * notification sent once `ChecklistCompletionService::checkFinalPayCompletion()`
     * transitions the request to `completed`, "{{account_status}}" /
     * "{{reactivated_at}}" for the User Account Reactivation Notification
     * sent once `UserController::reactivate()` unblocks an account — plus the legacy
     * bare-word / single-brace "approver" /
     * "offboardee" / "employee" placeholders still used by the
     * drag-and-drop email template editor. A placeholder with no value
     * available at this call site (e.g. "{{due_date}}" when the caller has
     * no specific assignment in scope) is simply replaced with an empty
     * string, never left as a raw, unresolved token in the sent email.
     * `$offboardingLinkUrl` overrides what "{{offboarding_link}}" itself
     * points to — every caller except `ChecklistApprovalNotifier::notifyOffboardee()`'s
     * first-account-creation case leaves it null, which keeps the plain,
     * generic sign-in page; that one caller instead passes a per-employee
     * auto-fill URL (`AuthController::autoFill()`) so a first-time
     * offboardee's Username/Password fields are pre-filled from the same
     * credentials this email already shows them in plain text.
     *
     * @return array{0: string, 1: string} [subject, body]
     */
    public function render(
        string $approverName,
        string $offboardeeName,
        string $creatorName = '',
        ?string $employeeNumber = null,
        ?string $checklistName = null,
        ?string $dueDate = null,
        ?string $department = null,
        ?string $position = null,
        ?string $daysOverdue = null,
        ?string $pendingItems = null,
        ?string $checklistStatus = null,
        ?string $dateHired = null,
        ?string $separationDate = null,
        ?string $reason = null,
        ?string $requestDate = null,
        ?string $offboardingStatus = null,
        ?string $username = null,
        ?string $temporaryPassword = null,
        ?string $checklistSummary = null,
        ?string $departmentHeadName = null,
        ?string $assignedSignatories = null,
        ?string $checklistProgress = null,
        ?string $remainingItems = null,
        ?string $followUpSentAt = null,
        ?string $generalSignatoryTasks = null,
        ?string $approveButton = null,
        ?string $originalDueDate = null,
        ?string $extensionDays = null,
        ?string $extendedDueDate = null,
        ?string $clearanceSignatoryName = null,
        ?string $cancelledBy = null,
        ?string $cancelledAt = null,
        ?string $offboardingRequestId = null,
        ?string $cancellationReason = null,
        ?string $declinedBy = null,
        ?string $declinedAt = null,
        ?string $declineReason = null,
        ?string $signatoryType = null,
        ?string $checklistType = null,
        ?string $completedAt = null,
        ?string $offboardingLinkUrl = null,
        ?string $accountStatus = null,
        ?string $reactivatedAt = null,
        // Clearance Signatory escalation tokens (see `ESCALATION_TOKENS`),
        // keyed by token name — only `ClearanceSignatoryEscalationService`
        // passes these; every other caller leaves them blank.
        array $escalation = [],
    ): array {
        $values = $this->placeholderValues(
            $approverName, $offboardeeName, $creatorName, $employeeNumber, $checklistName,
            $dueDate, $department, $position, $daysOverdue, $pendingItems, $checklistStatus,
            $dateHired, $separationDate, $reason, $requestDate, $offboardingStatus,
            $username, $temporaryPassword, $checklistSummary, $departmentHeadName,
            $assignedSignatories, $checklistProgress, $remainingItems, $followUpSentAt,
            $generalSignatoryTasks, $approveButton,
            $originalDueDate, $extensionDays, $extendedDueDate, $clearanceSignatoryName,
            $cancelledBy, $cancelledAt, $offboardingRequestId, $cancellationReason,
            $declinedBy, $declinedAt, $declineReason, $signatoryType, $checklistType,
            $completedAt, $offboardingLinkUrl, $accountStatus, $reactivatedAt,
        );

        foreach (self::ESCALATION_TOKENS as $token) {
            $values[$token] = (string) ($escalation[$token] ?? '');
        }

        return [
            $this->fillPlaceholders($this->subject, $values),
            $this->fillPlaceholders($this->html_content ?? '', $values),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function placeholderValues(
        string $approverName,
        string $offboardeeName,
        string $creatorName,
        ?string $employeeNumber,
        ?string $checklistName,
        ?string $dueDate,
        ?string $department,
        ?string $position,
        ?string $daysOverdue,
        ?string $pendingItems,
        ?string $checklistStatus,
        ?string $dateHired,
        ?string $separationDate,
        ?string $reason,
        ?string $requestDate,
        ?string $offboardingStatus,
        ?string $username,
        ?string $temporaryPassword,
        ?string $checklistSummary,
        ?string $departmentHeadName,
        ?string $assignedSignatories,
        ?string $checklistProgress,
        ?string $remainingItems,
        ?string $followUpSentAt,
        ?string $generalSignatoryTasks,
        ?string $approveButton,
        ?string $originalDueDate = null,
        ?string $extensionDays = null,
        ?string $extendedDueDate = null,
        ?string $clearanceSignatoryName = null,
        ?string $cancelledBy = null,
        ?string $cancelledAt = null,
        ?string $offboardingRequestId = null,
        ?string $cancellationReason = null,
        ?string $declinedBy = null,
        ?string $declinedAt = null,
        ?string $declineReason = null,
        ?string $signatoryType = null,
        ?string $checklistType = null,
        ?string $completedAt = null,
        ?string $offboardingLinkUrl = null,
        ?string $accountStatus = null,
        ?string $reactivatedAt = null,
    ): array {
        return [
            'approver_name' => $approverName,
            'offboardee_name' => $offboardeeName,
            'employee_name' => $offboardeeName,
            'employee_number' => $employeeNumber ?? '',
            'checklist_name' => $checklistName ?? '',
            'due_date' => $dueDate ?? '',
            'department' => $department ?? '',
            'position' => $position ?? '',
            'days_overdue' => $daysOverdue ?? '',
            'pending_items' => $pendingItems ?? '',
            'checklist_status' => $checklistStatus ?? '',
            'date_hired' => $dateHired ?? '',
            'separation_date' => $separationDate ?? '',
            'reason' => $reason ?? '',
            'request_date' => $requestDate ?? '',
            'offboarding_status' => $offboardingStatus ?? '',
            'username' => $username ?? '',
            'temporary_password' => $temporaryPassword ?? '',
            'checklist_summary' => $checklistSummary ?? '',
            'department_head_name' => $departmentHeadName ?? '',
            'assigned_signatories' => $assignedSignatories ?? '',
            'checklist_progress' => $checklistProgress ?? '',
            'remaining_items' => $remainingItems ?? '',
            'follow_up_sent_at' => $followUpSentAt ?? '',
            'general_signatory_tasks' => $generalSignatoryTasks ?? '',
            'approve_button' => $approveButton ?? '',
            'original_due_date' => $originalDueDate ?? '',
            'extension_days' => $extensionDays ?? '',
            'extended_due_date' => $extendedDueDate ?? '',
            'clearance_signatory_name' => $clearanceSignatoryName ?? '',
            'cancelled_by' => $cancelledBy ?? '',
            'cancelled_at' => $cancelledAt ?? '',
            'offboarding_request_id' => $offboardingRequestId ?? '',
            'cancellation_reason' => $cancellationReason ?? '',
            'declined_by' => $declinedBy ?? '',
            'declined_at' => $declinedAt ?? '',
            'decline_reason' => $declineReason ?? '',
            'signatory_type' => $signatoryType ?? '',
            'checklist_type' => $checklistType ?? '',
            'completed_at' => $completedAt ?? '',
            'account_status' => $accountStatus ?? '',
            'reactivated_at' => $reactivatedAt ?? '',
            // Defaults to the plain, generic sign-in page for every caller
            // except `ChecklistApprovalNotifier::notifyOffboardee()`'s
            // first-account-creation case, which passes a per-employee
            // auto-fill URL instead (see `AuthController::autoFill()`) so
            // the offboardee doesn't have to retype the username/password
            // this same email already shows them in plain text.
            //
            // Rendered as a dark green button — same inline styling as the
            // one-click Approve button (see
            // `ChecklistApprovalNotifier::buildApproveButtonHtml()`), inline
            // because email clients strip `<style>` blocks. A bare `<a>`
            // (no wrapping `<p>`), since every template already puts this
            // token inside its own paragraph.
            'offboarding_link' => '<a href="' . e($offboardingLinkUrl ?? route('login')) . '" style="display:inline-block;background-color:#145a3a;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:bold;">CIM Offboarding</a>',
            'approver' => $approverName,
            'offboardee' => $offboardeeName,
            'employee' => $creatorName,
        ];
    }

    /**
     * @param  array<string, string>  $values
     */
    /**
     * Tokens filled only by the Clearance Signatory escalation emails.
     */
    public const ESCALATION_TOKENS = [
        'primary_signatory_name', 'secondary_signatory_name', 'previous_signatory_name', 'new_signatory_name',
        'original_assigned_at', 'original_deadline', 'secondary_assigned_at', 'secondary_deadline',
        'new_assigned_at', 'signatory_deadline', 'escalated_at', 'escalation_reason', 'reassigned_by',
    ];

    private function fillPlaceholders(string $text, array $values): string
    {
        $pattern = '/\{\{\s*(approver_name|offboardee_name|employee_name|employee_number|checklist_name|due_date'
            . '|department|position|days_overdue|pending_items|checklist_status|date_hired|separation_date|reason|offboarding_link'
            . '|request_date|offboarding_status|username|temporary_password|checklist_summary'
            . '|department_head_name|assigned_signatories|checklist_progress|remaining_items|follow_up_sent_at|general_signatory_tasks|approve_button'
            . '|original_due_date|extension_days|extended_due_date|clearance_signatory_name'
            . '|cancelled_by|cancelled_at|offboarding_request_id|cancellation_reason'
            . '|declined_by|declined_at|decline_reason|signatory_type|checklist_type|completed_at'
            . '|account_status|reactivated_at|' . implode('|', self::ESCALATION_TOKENS) . ')\s*\}\}'
            . '|\{\s*(approver|offboardee|employee)\s*\}'
            . '|\b(approver|offboardee|employee)\b/i';

        return preg_replace_callback($pattern, function ($matches) use ($values) {
            $key = strtolower($matches[1] ?: ($matches[2] ?: $matches[3]));

            return $values[$key];
        }, $text);
    }
}
