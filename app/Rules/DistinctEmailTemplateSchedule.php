<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

/**
 * Applied to `schedules.*.days`: rejects a schedule row that is an exact
 * duplicate of an earlier (not-removed) row on the same template — same
 * event, same effective timing, same days, same Clearance deadline, same
 * channel and same checklist / General Signatory selection. Two such rows
 * resolve to the same recipients on the same date, so each would send its
 * own copy of the same email. Rows differing in any of those (e.g. one
 * Email and one System Notification, or different selections) are
 * legitimate and allowed. "0 days Before" and "0 days After" are treated as
 * the same occurrence, matching `EmailTemplateSchedule::label()` ("On …").
 * Mirrored client-side in `workspace.blade.php`'s schedule editor.
 */
class DistinctEmailTemplateSchedule implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $rows = Arr::get($this->data, 'schedules');
        $currentKey = explode('.', $attribute)[1] ?? null;

        if (! is_array($rows) || $currentKey === null || ! is_array($rows[$currentKey] ?? null) || ! empty($rows[$currentKey]['_destroy'])) {
            return;
        }

        $current = self::signature($rows[$currentKey]);

        foreach ($rows as $key => $row) {
            if ((string) $key === (string) $currentKey) {
                return;
            }

            if (is_array($row) && empty($row['_destroy']) && self::signature($row) === $current) {
                $fail('This schedule duplicates another schedule on this template (same event, timing, days, channel and recipients).');

                return;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function signature(array $row): string
    {
        $days = (int) ($row['days'] ?? 0);
        $event = $row['event'] ?? null;
        $ids = function ($list): array {
            $list = array_map('intval', is_array($list) ? $list : []);
            sort($list);

            return array_values(array_unique($list));
        };

        return json_encode([
            $event,
            $days === 0 ? 'on' : ($row['timing'] ?? null),
            $days,
            $event === 'clearance' ? (int) ($row['clearance_signing_deadline_days'] ?? 19) : null,
            $row['channel'] ?? null,
            $ids($row['checklist_template_ids'] ?? []),
            $ids($row['general_signatory_ids'] ?? []),
        ]);
    }
}
