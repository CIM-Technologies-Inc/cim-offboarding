<?php

namespace App\Services;

use App\Exceptions\FollowUpNotAllowedException;
use App\Models\ChecklistFollowUp;
use App\Models\Employee;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChecklistFollowUpService
{
    public function __construct(private ChecklistApprovalNotifier $notifier)
    {
    }

    /**
     * Everything the Employee Dashboard needs to decide whether to show/
     * enable the Follow Up button for one checklist, and what to display
     * when it's disabled. Read-only — mirrors the exact same rules `send()`
     * enforces, but without a lock, since this is purely informational
     * (the authoritative check happens again, locked, inside `send()`
     * itself when the button is actually clicked).
     *
     * @return array{
     *     canSendNow: bool,
     *     lastSentAt: ?\Illuminate\Support\Carbon,
     *     nextAllowedAt: ?\Illuminate\Support\Carbon,
     *     attemptsUsed: int,
     *     maxAttempts: int,
     *     maxReached: bool,
     * }
     */
    public function stateFor(OffboardingRequestApprover $assignment, Employee $employee): array
    {
        $maxAttempts = (int) config('offboarding.max_follow_up_attempts');
        $cooldownHours = (int) config('offboarding.follow_up_cooldown_hours');

        $attemptsUsed = ChecklistFollowUp::where('offboarding_request_id', $assignment->offboarding_request_id)
            ->where('employee_id', $employee->id)
            ->count();

        $lastForThisChecklist = ChecklistFollowUp::where('offboarding_request_approver_id', $assignment->id)
            ->where('employee_id', $employee->id)
            ->orderByDesc('sent_at')
            ->first();

        $nextAllowedAt = $lastForThisChecklist?->sent_at?->copy()->addHours($cooldownHours);
        $onCooldown = $nextAllowedAt !== null && now()->lessThan($nextAllowedAt);
        $maxReached = $attemptsUsed >= $maxAttempts;

        return [
            'canSendNow' => ! $onCooldown && ! $maxReached,
            'lastSentAt' => $lastForThisChecklist?->sent_at,
            'nextAllowedAt' => $onCooldown ? $nextAllowedAt : null,
            'attemptsUsed' => $attemptsUsed,
            'maxAttempts' => $maxAttempts,
            'maxReached' => $maxReached,
        ];
    }

    /**
     * Sends one follow-up for one checklist, after re-validating both rules
     * inside a row lock on the parent `OffboardingRequest` — the same lock
     * every concurrent follow-up attempt on ANY of this employee's
     * checklists contends for, since the attempt budget is shared across
     * the whole request, not per checklist. This is what actually prevents
     * bypass via a double-click, two open tabs, or a race between two
     * checklists' buttons: the count is re-read fresh, under the lock,
     * immediately before deciding, never trusted from a page that may have
     * been sitting open for a while.
     *
     * @throws FollowUpNotAllowedException  when the cooldown hasn't
     *      elapsed for this specific checklist, or the request's shared
     *      budget is already exhausted — the message is written to be
     *      shown to the employee as-is.
     */
    public function send(OffboardingRequestApprover $assignment, Employee $employee, ?User $recipient): ChecklistFollowUp
    {
        return DB::transaction(function () use ($assignment, $employee, $recipient) {
            OffboardingRequest::whereKey($assignment->offboarding_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            $maxAttempts = (int) config('offboarding.max_follow_up_attempts');
            $cooldownHours = (int) config('offboarding.follow_up_cooldown_hours');

            $attemptsUsed = ChecklistFollowUp::where('offboarding_request_id', $assignment->offboarding_request_id)
                ->where('employee_id', $employee->id)
                ->count();

            if ($attemptsUsed >= $maxAttempts) {
                throw new FollowUpNotAllowedException(
                    "You've reached the maximum of {$maxAttempts} follow-up attempts for this offboarding request."
                );
            }

            $lastForThisChecklist = ChecklistFollowUp::where('offboarding_request_approver_id', $assignment->id)
                ->where('employee_id', $employee->id)
                ->orderByDesc('sent_at')
                ->first();

            if ($lastForThisChecklist) {
                $nextAllowedAt = $lastForThisChecklist->sent_at->copy()->addHours($cooldownHours);

                if (now()->lessThan($nextAllowedAt)) {
                    throw new FollowUpNotAllowedException(
                        'You already sent a follow-up for this checklist on ' . $lastForThisChecklist->sent_at->format('M d, Y g:i A')
                        . '. You can send another one starting ' . $nextAllowedAt->format('M d, Y g:i A') . '.'
                    );
                }
            }

            $followUp = ChecklistFollowUp::create([
                'offboarding_request_id' => $assignment->offboarding_request_id,
                'offboarding_request_approver_id' => $assignment->id,
                'employee_id' => $employee->id,
                'recipient_user_id' => $recipient?->id,
                'attempt_number' => $attemptsUsed + 1,
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            $delivered = $recipient && $this->notifier->notifyFollowUp($assignment, $followUp, $recipient);

            if (! $delivered) {
                $followUp->update(['status' => 'failed']);
            }

            return $followUp;
        });
    }
}
