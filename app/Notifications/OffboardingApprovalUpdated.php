<?php

namespace App\Notifications;

use App\Models\OffboardingRequest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class OffboardingApprovalUpdated extends Notification
{
    use Queueable;

    public function __construct(
        public OffboardingRequest $offboardingRequest,
        public User $approver,
        public string $action,
        public ?string $comment = null,
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
        return $this->action === 'approved' ? 'offboarding_approved' : 'offboarding_declined';
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $offboardee = $this->offboardingRequest->employee;

        return [
            'offboarding_request_id' => $this->offboardingRequest->id,
            'offboardee_id' => $offboardee->id,
            'offboardee_name' => $offboardee->name,
            'approver_name' => $this->approver->name,
            'approver_department' => $this->approver->employee?->department,
            'action' => 'view_offboarding_timeline',
            'comment' => $this->comment,
            'message' => "{$this->approver->name} {$this->action} the offboarding request for {$offboardee->name}.",
            'url' => route('offboardees.index', ['offboardee' => $offboardee->id]),
        ];
    }
}
