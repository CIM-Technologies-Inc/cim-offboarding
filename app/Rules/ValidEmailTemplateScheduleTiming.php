<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

/**
 * Applied to `schedules.*.timing` on the Email Notification Schedule
 * Timeline. A Creation schedule can only fire on or after the request is
 * created — "N days Before Creation" would compute a date before the
 * request existed, which the dispatcher treats as already due and sends the
 * moment the request is submitted. (Before the event-based scheme, Creation
 * only ever had "On Request Creation" / "After Request Creation".) Zero days
 * is allowed either way, since 0 days before and after are the same moment.
 * Mirrored client-side in `workspace.blade.php`'s schedule editor.
 */
class ValidEmailTemplateScheduleTiming implements DataAwareRule, ValidationRule
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
        $row = Arr::get($this->data, substr($attribute, 0, -strlen('.timing')), []);

        if (empty($row['_destroy']) && ($row['event'] ?? null) === 'creation' && $value === 'before' && (int) ($row['days'] ?? 0) > 0) {
            $fail('A Creation schedule can only be sent on or after the request is created — choose "After".');
        }
    }
}
