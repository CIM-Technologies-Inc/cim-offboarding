<?php

namespace App\Http\Requests;

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
            'template_name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'html_content' => ['nullable', 'string'],
            'is_default_announcement' => ['boolean'],
            'is_scheduled' => ['boolean'],
            'schedule_type' => [
                'nullable',
                Rule::requiredIf(fn () => $this->boolean('is_scheduled')),
                Rule::in(['one_time', 'recurring']),
            ],
            'schedule_timing' => [
                'nullable',
                Rule::requiredIf(fn () => $this->boolean('is_scheduled') && $this->input('schedule_type') === 'one_time'),
                Rule::in(['before', 'after']),
            ],
            'schedule_days' => [
                'nullable',
                Rule::requiredIf(fn () => $this->boolean('is_scheduled') && $this->input('schedule_type') === 'one_time'),
                'integer',
                'min:1',
            ],
            'schedule_interval_days' => [
                'nullable',
                Rule::requiredIf(fn () => $this->boolean('is_scheduled') && $this->input('schedule_type') === 'recurring'),
                'integer',
                'min:1',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_default_announcement' => $this->boolean('is_default_announcement'),
            'is_scheduled' => $this->boolean('is_scheduled'),
        ]);
    }
}
