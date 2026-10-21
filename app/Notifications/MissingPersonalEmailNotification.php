<?php

namespace App\Notifications;

use App\Models\OffboardingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MissingPersonalEmailNotification extends Notification
{
    use Queueable;

    public function __construct(
        public OffboardingRequest $offboardingRequest,
        public string $context,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'missing_personal_email';
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $offboardee = $this->offboardingRequest->employee;

        return [
            'offboarding_request_id' => $this->offboardingRequest->id,
            'employee_id' => $offboardee->id,
            'employee_name' => $offboardee->name,
            'employee_code' => $offboardee->employee_code,
            'context' => $this->context,
            'message' => "{$offboardee->name} has no valid personal email on file — the \"{$this->context}\" notification could not be sent. Update their personal email in the Employee Master.",
            'url' => route('employee-groups.index'),
        ];
    }
}
