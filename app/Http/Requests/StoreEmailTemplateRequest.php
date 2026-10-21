<?php

namespace App\Http\Requests;

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
            'template_name' => ['required', 'string', 'max:255'],
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
            'schedules.*.timing' => ['required_with:schedules', Rule::in(['before', 'after'])],
            'schedules.*.days' => ['required_with:schedules', 'integer', 'min:0'],
            'schedules.*.channel' => ['required_with:schedules', Rule::in(['email', 'system', 'email_and_system'])],
            'schedules.*._destroy' => ['nullable', 'boolean'],
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
