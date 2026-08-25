<?php

namespace App\Console\Commands;

use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\ChecklistItem;
use App\Models\OffboardingRequestApprover;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendScheduledChecklistItemNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-scheduled-checklist-item-notifications';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send each checklist item\'s scheduled Email and Notification template to its Task Assignee once the item\'s "N days before/after Last Working Day" date is due, for every offboarding request that checklist is attached to.';

    /**
     * For each item with scheduling enabled, finds every attached, still-
     * active offboarding request that hasn't received this item's
     * notification yet and whose computed send date has arrived, and sends
     * it to the item's REQUEST-SPECIFIC effective assignee (via
     * `OffboardingRequestApprover::effectiveSignatoryFor()`, which honors
     * any per-request reassignment/takeover) — never the template's own
     * live `signatory_id`, which could differ or have changed since the
     * request was created. The `whereNotIn` guard plus the unique DB
     * constraint on `checklist_item_scheduled_sends` (checklist_item_id,
     * offboarding_request_id) ensure a re-run never sends the same item's
     * notification twice for the same request.
     */
    public function handle(): int
    {
        $items = ChecklistItem::where('notify_enabled', true)
            ->whereNotNull('email_template_id')
            ->whereNotNull('notify_timing')
            ->whereNotNull('notify_days')
            ->with('emailTemplate')
            ->get();

        $sent = 0;

        foreach ($items as $item) {
            if (! $item->emailTemplate?->is_active) {
                continue;
            }

            $sent += $this->processItem($item);
        }

        $this->info("Sent {$sent} scheduled checklist item notification(s).");

        return self::SUCCESS;
    }

    private function processItem(ChecklistItem $item): int
    {
        $alreadySent = $item->scheduledSends()->pluck('offboarding_request_id');

        $assignments = OffboardingRequestApprover::where('checklist_template_id', $item->checklist_template_id)
            ->whereNotIn('offboarding_request_id', $alreadySent)
            ->whereHas('offboardingRequest', fn ($q) => $q->whereIn('status', ['pending', 'in_progress'])->whereNotNull('last_working_day'))
            ->with(['offboardingRequest.employee', 'itemAssignments.assignedEmployee'])
            ->get();

        $sent = 0;

        foreach ($assignments as $assignment) {
            $lastWorkingDay = $assignment->offboardingRequest->last_working_day;

            $scheduledDate = $item->notify_timing === 'before'
                ? $lastWorkingDay->copy()->subDays($item->notify_days)
                : $lastWorkingDay->copy()->addDays($item->notify_days);

            if ($scheduledDate->isFuture()) {
                continue;
            }

            $this->sendForAssignment($item, $assignment);
            $sent++;
        }

        return $sent;
    }

    private function sendForAssignment(ChecklistItem $item, OffboardingRequestApprover $assignment): void
    {
        $request = $assignment->offboardingRequest;
        $offboardee = $request->employee;
        $recipient = $assignment->effectiveSignatoryFor($item);

        if ($recipient && $recipient->email && filter_var($recipient->email, FILTER_VALIDATE_EMAIL)) {
            [$subject, $body] = $item->emailTemplate->render(
                approverName: $recipient->name,
                offboardeeName: $offboardee->name,
                employeeNumber: $offboardee->employee_code,
                checklistName: $item->title,
                department: $offboardee->department,
                position: $offboardee->designation,
                separationDate: $request->last_working_day?->format('M d, Y'),
            );

            try {
                Mail::to($recipient->email)->send(new ChecklistSignatoryAnnouncementMail($subject, $body));
            } catch (\Throwable $e) {
                Log::error('Failed to send scheduled checklist item notification.', [
                    'checklist_item_id' => $item->id,
                    'offboarding_request_id' => $request->id,
                    'recipient' => $recipient->email,
                    'exception' => $e->getMessage(),
                ]);
            }
        } else {
            Log::warning('Scheduled checklist item notification skipped — no valid recipient.', [
                'checklist_item_id' => $item->id,
                'offboarding_request_id' => $request->id,
            ]);
        }

        $item->scheduledSends()->create([
            'offboarding_request_id' => $request->id,
            'sent_at' => now(),
        ]);
    }
}
