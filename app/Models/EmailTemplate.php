<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTemplate extends Model
{
    protected $fillable = [
        'template_name',
        'subject',
        'html_content',
        'is_active',
        'is_default_announcement',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default_announcement' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
     * for the overdue-checklist notification, plus the legacy bare-word /
     * single-brace "approver" / "offboardee" / "employee" placeholders still
     * used by the drag-and-drop email template editor. A placeholder with no
     * value available at this call site (e.g. "{{due_date}}" when the
     * caller has no specific assignment in scope) is simply replaced with an
     * empty string, never left as a raw, unresolved token in the sent email.
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
    ): array {
        $values = $this->placeholderValues(
            $approverName, $offboardeeName, $creatorName, $employeeNumber, $checklistName,
            $dueDate, $department, $position, $daysOverdue, $pendingItems, $checklistStatus,
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
            . '|department|position|days_overdue|pending_items|checklist_status|offboarding_link)\s*\}\}'
            . '|\{\s*(approver|offboardee|employee)\s*\}'
            . '|\b(approver|offboardee|employee)\b/i';

        return preg_replace_callback($pattern, function ($matches) use ($values) {
            $key = strtolower($matches[1] ?: ($matches[2] ?: $matches[3]));

            return $values[$key];
        }, $text);
    }
}
