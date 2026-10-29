<?php

namespace App\Http\Requests;

use App\Models\EmailTemplate;
use App\Rules\DistinctEmailTemplateSchedule;
use App\Rules\ValidEmailTemplateScheduleTiming;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A brand-new template can never legitimately reuse one of the
            // app's reserved system names — those already exist as seeded
            // rows, edited (not duplicated) via the existing template cards.
            // Allowing a duplicate here would let a second, "Customizable"-
            // labeled row with the same name silently compete for the same
            // hardcoded `where('template_name', ...)` lookup elsewhere.
            'template_name' => ['required', 'string', 'max:255', Rule::notIn(EmailTemplate::SYSTEM_TEMPLATE_NAMES)],
            'subject' => ['required', 'string', 'max:255'],
            'html_content' => ['nullable', 'string'],
            'is_default_announcement' => ['boolean'],
            'is_default_reactivation' => ['boolean'],
            // "Recipients / CC" — see `EmailTemplate::ccRecipientEmails()`.
            'recipient_employee_ids' => ['nullable', 'array'],
            'recipient_employee_ids.*' => ['integer', 'exists:employees,id'],
            'recipient_group_ids' => ['nullable', 'array'],
            'recipient_group_ids.*' => ['integer', 'exists:employee_groups,id'],
            // Manually entered recipient addresses (company or personal).
            'recipient_emails' => ['nullable', 'array', 'max:50'],
            'recipient_emails.*' => ['required', 'string', 'max:255', 'email', 'distinct:ignore_case'],
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

    public function messages(): array
    {
        return [
            'template_name.not_in' => 'That name is reserved for a Default System template and cannot be used here.',
            'recipient_emails.*.email' => '":input" is not a valid email address.',
            'recipient_emails.*.distinct' => '":input" has already been added as a recipient.',
            'recipient_emails.*.max' => 'Recipient email addresses can be at most 255 characters.',
            'recipient_emails.max' => 'You can add at most 50 manually entered recipient email addresses.',
        ];
    }
}
