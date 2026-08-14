<?php

namespace App\Notifications;

use App\Models\OffboardingRequestApprover;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ChecklistOverdueNotification extends Notification
{
    use Queueable;

    public function __construct(
        public OffboardingRequestApprover $assignment,
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
        return 'checklist_overdue';
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $offboardee = $this->assignment->offboardingRequest->employee;
        $template = $this->assignment->checklistTemplate;

        return [
            'offboarding_request_id' => $this->assignment->offboarding_request_id,
            'offboarding_request_approver_id' => $this->assignment->id,
            'offboardee_id' => $offboardee->id,
            'offboardee_name' => $offboardee->name,
            'offboardee_employee_code' => $offboardee->employee_code,
            'checklist_title' => $template->title,
            'due_at' => $this->assignment->due_at?->format('M d, Y g:i A'),
            'status' => 'Overdue',
            'message' => "The \"{$template->title}\" checklist for {$offboardee->name} is overdue and requires attention.",
            'url' => route('approvals.index'),
        ];
    }
}
