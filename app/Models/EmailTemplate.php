<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailTemplate extends Model
{
    protected $fillable = [
        'template_name',
        'subject',
        'html_content',
        'is_active',
        'is_default_announcement',
        'is_scheduled',
        'schedule_type',
        'schedule_timing',
        'schedule_days',
        'schedule_interval_days',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default_announcement' => 'boolean',
            'is_scheduled' => 'boolean',
            'schedule_days' => 'integer',
            'schedule_interval_days' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scheduledSends(): HasMany
    {
        return $this->hasMany(EmailTemplateScheduledSend::class);
    }

    /**
     * Human-readable summary of this template's schedule for the template
     * list, or null when scheduling isn't enabled/configured. Two
     * independent modes: 'one_time' (e.g. "5 days before Last Working
     * Day" — fires once) and 'recurring' (e.g. "Every 5 days until Last
     * Working Day" — keeps firing on that interval, counted from the
     * offboarding request's creation date, until its Last Working Day is
     * reached — see `SendScheduledEmailTemplates::processRecurringTemplate()`).
     */
    public function scheduleLabel(): ?string
    {
        if (! $this->is_scheduled) {
            return null;
        }

        if ($this->schedule_type === 'recurring') {
            if (! $this->schedule_interval_days) {
                return null;
            }

            $unit = $this->schedule_interval_days === 1 ? 'day' : 'days';

            return "Every {$this->schedule_interval_days} {$unit} until Last Working Day";
        }

        if (! $this->schedule_days || ! $this->schedule_timing) {
            return null;
        }

        $unit = $this->schedule_days === 1 ? 'day' : 'days';
        $timing = $this->schedule_timing === 'before' ? 'before' : 'after';

        return "{$this->schedule_days} {$unit} {$timing} Last Working Day";
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
     * saves — plus the legacy bare-word / single-brace "approver" /
     * "offboardee" / "employee" placeholders still used by the
     * drag-and-drop email template editor. A placeholder with no value
     * available at this call site (e.g. "{{due_date}}" when the caller has
     * no specific assignment in scope) is simply replaced with an empty
     * string, never left as a raw, unresolved token in the sent email.
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
    ): array {
        $values = $this->placeholderValues(
            $approverName, $offboardeeName, $creatorName, $employeeNumber, $checklistName,
            $dueDate, $department, $position, $daysOverdue, $pendingItems, $checklistStatus,
            $dateHired, $separationDate, $reason, $requestDate, $offboardingStatus,
            $username, $temporaryPassword, $checklistSummary, $departmentHeadName,
            $assignedSignatories, $checklistProgress, $remainingItems, $followUpSentAt,
            $generalSignatoryTasks, $approveButton,
            $originalDueDate, $extensionDays, $extendedDueDate, $clearanceSignatoryName,
        );

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
            'offboarding_link' => '<a href="' . route('login') . '">CIM Offboarding</a>',
            'approver' => $approverName,
            'offboardee' => $offboardeeName,
            'employee' => $creatorName,
        ];
    }

    /**
     * @param  array<string, string>  $values
     */
    private function fillPlaceholders(string $text, array $values): string
    {
        $pattern = '/\{\{\s*(approver_name|offboardee_name|employee_name|employee_number|checklist_name|due_date'
            . '|department|position|days_overdue|pending_items|checklist_status|date_hired|separation_date|reason|offboarding_link'
            . '|request_date|offboarding_status|username|temporary_password|checklist_summary'
            . '|department_head_name|assigned_signatories|checklist_progress|remaining_items|follow_up_sent_at|general_signatory_tasks|approve_button'
            . '|original_due_date|extension_days|extended_due_date|clearance_signatory_name)\s*\}\}'
            . '|\{\s*(approver|offboardee|employee)\s*\}'
            . '|\b(approver|offboardee|employee)\b/i';

        return preg_replace_callback($pattern, function ($matches) use ($values) {
            $key = strtolower($matches[1] ?: ($matches[2] ?: $matches[3]));

            return $values[$key];
        }, $text);
    }
}
