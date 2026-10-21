<?php

namespace App\Notifications;

use App\Models\EmailTemplateSchedule;
use App\Models\OffboardingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * In-app bell notification for the "System Notification" (or "Email and
 * System Notification") channel of an `EmailTemplateSchedule` — see
 * `SendEmailTemplateSchedules`.
 */
class ScheduledEmailTemplateNotification extends Notification
{
    use Queueable;

    public function __construct(
        public EmailTemplateSchedule $schedule,
        public OffboardingRequest $offboardingRequest,
        public string $subject,
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
        return 'scheduled_email_template';
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $offboardee = $this->offboardingRequest->employee;

        return [
            'email_template_schedule_id' => $this->schedule->id,
            'offboarding_request_id' => $this->offboardingRequest->id,
            'offboardee_name' => $offboardee?->name,
            'offboardee_employee_code' => $offboardee?->employee_code,
            'message' => $this->subject,
            'url' => route('approvals.index'),
        ];
    }
}
