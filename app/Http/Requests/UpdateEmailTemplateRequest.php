<?php

namespace App\Http\Requests;

use App\Models\EmailTemplate;
use App\Rules\DistinctEmailTemplateSchedule;
use App\Rules\ValidEmailTemplateScheduleTiming;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A Customizable template can't be renamed INTO one of the
            // app's reserved system names — that would create a name
            // collision with the hardcoded `where('template_name', ...)`
            // lookup elsewhere. Keeping an ALREADY-reserved template's own
            // name unchanged is always allowed (its input is server-side
            // locked back to that same value anyway — see
            // `EmailTemplateController::update()`), so only an actual
            // rename into a reserved name is rejected here.
            'template_name' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail) {
                $current = $this->route('emailTemplate');

                if ($current && $value === $current->template_name) {
                    return;
                }

                if (in_array($value, EmailTemplate::SYSTEM_TEMPLATE_NAMES, true)) {
                    $fail('That name is reserved for a Default System template and cannot be used here.');
                }
            }],
            'subject' => ['required', 'string', 'max:255'],
            'html_content' => ['nullable', 'string'],
            'is_default_announcement' => ['boolean'],
            'is_default_reactivation' => ['boolean'],
            // "Recipients / CC" — see `EmailTemplate::ccRecipientEmails()`.
            'recipient_employee_ids' => ['nullable', 'array'],
            'recipient_employee_ids.*' => ['integer', 'exists:employees,id'],
            'recipient_group_ids' => ['nullable', 'array'],
            'recipient_group_ids.*' => ['integer', 'exists:employee_groups,id'],
            // Email Notification Schedule Timeline — see `EmailTemplateSchedule`.
            'schedules' => ['nullable', 'array'],
            'schedules.*.id' => ['nullable', 'integer'],
            'schedules.*.event' => ['required_with:schedules', Rule::in(['creation', 'last_working_day', 'clearance', 'final_pay'])],
            'schedules.*.timing' => ['required_with:schedules', Rule::in(['before', 'after']), new ValidEmailTemplateScheduleTiming],
            'schedules.*.days' => ['required_with:schedules', 'integer', 'min:0', new DistinctEmailTemplateSchedule],
            // Only meaningful (and only persisted — see
            // `EmailTemplateController::syncSchedules()`) when
            // `event === 'clearance'`; still structurally validated
            // whenever present so a tampered/stray value can't sneak in.
            'schedules.*.clearance_signing_deadline_days' => ['nullable', 'integer', 'min:0'],
            'schedules.*.channel' => ['required_with:schedules', Rule::in(['email', 'system', 'email_and_system'])],
            // "Repeat" — after its first occurrence the schedule fires again
            // every `repeat_interval_days` (see EmailTemplateScheduleDispatcher::dueOccurrence()).
            // The interval is required, and must be at least 1 day, only
            // while Repeat is on.
            'schedules.*.repeat_enabled' => ['nullable', 'boolean'],
            'schedules.*.repeat_interval_days' => ['nullable', 'required_if_accepted:schedules.*.repeat_enabled', 'integer', 'min:1', 'max:365'],
            'schedules.*._destroy' => ['nullable', 'boolean'],
            // "Active Checklist Selection" / "Active General Signatory
            // Selection" — per schedule. Empty/omitted means no selection
            // (defaults to every checklist/General Signatory actually
            // attached to each eligible request — see
            // `SendEmailTemplateSchedules::resolveRecipients()`), never "no
            // recipients at all".
            'schedules.*.checklist_template_ids' => ['nullable', 'array'],
            'schedules.*.checklist_template_ids.*' => ['integer', 'exists:checklist_templates,id'],
            'schedules.*.general_signatory_ids' => ['nullable', 'array'],
            'schedules.*.general_signatory_ids.*' => ['integer', 'exists:general_signatories,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_default_announcement' => $this->boolean('is_default_announcement'),
            'is_default_reactivation' => $this->boolean('is_default_reactivation'),
        ]);
    }
}
