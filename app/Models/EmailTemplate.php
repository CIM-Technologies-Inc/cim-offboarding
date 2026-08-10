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
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Renders this template's subject/body, replacing placeholders with the
     * approver's, offboardee's, and request creator's name/link. Supports
     * the recommended "{{approver_name}}" / "{{offboardee_name}}" /
     * "{{offboarding_link}}" tokens, plus the legacy bare-word / single-brace
     * "approver" / "offboardee" / "employee" placeholders still used by the
     * drag-and-drop email template editor.
     *
     * @return array{0: string, 1: string} [subject, body]
     */
    public function render(string $approverName, string $offboardeeName, string $creatorName = ''): array
    {
        return [
            $this->fillPlaceholders($this->subject, $approverName, $offboardeeName, $creatorName),
            $this->fillPlaceholders($this->html_content ?? '', $approverName, $offboardeeName, $creatorName),
        ];
    }

    private function fillPlaceholders(string $text, string $approverName, string $offboardeeName, string $creatorName): string
    {
        $values = [
            'approver_name' => $approverName,
            'offboardee_name' => $offboardeeName,
            'offboarding_link' => '<a href="' . route('login') . '">CIM Offboarding</a>',
            'approver' => $approverName,
            'offboardee' => $offboardeeName,
            'employee' => $creatorName,
        ];

        $pattern = '/\{\{\s*(approver_name|offboardee_name|offboarding_link)\s*\}\}'
            . '|\{\s*(approver|offboardee|employee)\s*\}'
            . '|\b(approver|offboardee|employee)\b/i';

        return preg_replace_callback($pattern, function ($matches) use ($values) {
            $key = strtolower($matches[1] ?: ($matches[2] ?: $matches[3]));

            return $values[$key];
        }, $text);
    }
}
