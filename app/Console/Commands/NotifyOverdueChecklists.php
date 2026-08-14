<?php

namespace App\Console\Commands;

use App\Models\OffboardingRequestApprover;
use App\Models\User;
use App\Notifications\ChecklistOverdueNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class NotifyOverdueChecklists extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:notify-overdue-checklists';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Notify admins and the responsible approvers when a checklist assignment passes its due date without being submitted/approved.';

    /**
     * Finds every still-outstanding checklist assignment whose due date has
     * passed and that hasn't been flagged overdue yet, and notifies:
     *   - every admin
     *   - the Department Head (always the final approver)
     *   - on per-item-approver checklists, each item's own effective
     *     signatory, but only for items that are still unchecked
     * `overdue_notified_at` guards this to fire exactly once per assignment
     * — re-running the command (e.g. on the next scheduled tick) never
     * sends duplicate notifications. Resolution (marking these read once
     * the checklist is actually submitted/approved) happens separately in
     * `ApprovalController`, not here.
     */
    public function handle(): int
    {
        $overdue = OffboardingRequestApprover::query()
            ->whereNotIn('status', ['approved', 'declined'])
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNull('overdue_notified_at')
            ->whereHas('offboardingRequest', fn ($q) => $q->whereIn('status', ['pending', 'in_progress']))
            ->with([
                'offboardingRequest.employee',
                'checklistTemplate.items',
                'employee.user',
                'itemProgress',
                'itemAssignments.assignedEmployee.user',
            ])
            ->get();

        foreach ($overdue as $assignment) {
            $this->notifyForAssignment($assignment);
        }

        $this->info("Notified {$overdue->count()} overdue checklist assignment(s).");

        return self::SUCCESS;
    }

    private function notifyForAssignment(OffboardingRequestApprover $assignment): void
    {
        $recipients = collect();

        // The Department Head is always responsible, as the final approver.
        if ($assignment->employee?->user) {
            $recipients->push($assignment->employee->user);
        }

        // On a per-item-approver checklist, also notify each still-unchecked
        // item's own effective signatory — not everyone assigned to the
        // template, only those actually responsible for what's incomplete.
        if ($assignment->usesPerItemApprovers()) {
            $progress = $assignment->itemProgress->keyBy('checklist_item_id');

            foreach ($assignment->checklistTemplate->items as $item) {
                $isChecked = (bool) ($progress->get($item->id)?->is_checked ?? false);

                if ($isChecked) {
                    continue;
                }

                $signatory = $assignment->effectiveSignatoryFor($item);

                if ($signatory?->user) {
                    $recipients->push($signatory->user);
                }
            }
        }

        $recipients = $recipients
            ->merge(User::where('role', User::ROLE_ADMIN)->get())
            ->filter()
            ->unique('id');

        Notification::send($recipients, new ChecklistOverdueNotification($assignment));

        $assignment->update(['overdue_notified_at' => now()]);
    }
}
