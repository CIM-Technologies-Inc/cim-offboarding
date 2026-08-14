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
     * "{{offboarding_link}}" tokens, plus the legacy bare-word / single-brace
     * "approver" / "offboardee" / "employee" placeholders still used by the
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
    ): array {
        return [
            $this->fillPlaceholders($this->subject, $approverName, $offboardeeName, $creatorName, $employeeNumber, $checklistName, $dueDate),
            $this->fillPlaceholders($this->html_content ?? '', $approverName, $offboardeeName, $creatorName, $employeeNumber, $checklistName, $dueDate),
        ];
    }

    private function fillPlaceholders(
        string $text,
        string $approverName,
        string $offboardeeName,
        string $creatorName,
        ?string $employeeNumber,
        ?string $checklistName,
        ?string $dueDate,
    ): string {
        $values = [
            'approver_name' => $approverName,
            'offboardee_name' => $offboardeeName,
            'employee_name' => $offboardeeName,
            'employee_number' => $employeeNumber ?? '',
            'checklist_name' => $checklistName ?? '',
            'due_date' => $dueDate ?? '',
            'offboarding_link' => '<a href="' . route('login') . '">CIM Offboarding</a>',
            'approver' => $approverName,
            'offboardee' => $offboardeeName,
            'employee' => $creatorName,
        ];

        $pattern = '/\{\{\s*(approver_name|offboardee_name|employee_name|employee_number|checklist_name|due_date|offboarding_link)\s*\}\}'
            . '|\{\s*(approver|offboardee|employee)\s*\}'
            . '|\b(approver|offboardee|employee)\b/i';

        return preg_replace_callback($pattern, function ($matches) use ($values) {
            $key = strtolower($matches[1] ?: ($matches[2] ?: $matches[3]));

            return $values[$key];
        }, $text);
    }
}
