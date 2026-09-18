<?php

namespace App\Console\Commands;

use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\ChecklistReminderLog;
use App\Models\ChecklistTemplate;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\OffboardingRequestApprover;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendChecklistReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-checklist-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send each checklist template\'s own "Schedule Email/Notification" reminder — calculated from THAT checklist\'s own current due date, not the offboardee\'s Last Working Day directly — to its Clearance Signatory, Task Assignee(s), and any monitoring Group Head/Department Head, once, per configured reminder cycle.';

    /**
     * Unlike `app:send-scheduled-email-templates`/`app:send-scheduled-
     * checklist-item-notifications` (both daily, since their trigger is a
     * bare calendar date), this checklist-level schedule has a genuine
     * "Time to Send" field — run frequently enough (see
     * `routes/console.php`) that a reminder actually goes out reasonably
     * close to its configured time, not just "sometime that day".
     *
     * Deliberately never pre-materializes a future scheduled row: every
     * cycle's send date is computed fresh from the checklist's OWN CURRENT
     * `due_at` on every run, so an Extend Due update that moves the due
     * date is picked up automatically for any cycle not yet logged — there
     * is nothing to separately cancel/invalidate. A cycle already logged
     * `sent` stays sent regardless of a later due-date change.
     */
    public function handle(): int
    {
        $assignments = OffboardingRequestApprover::query()
            ->whereHas('checklistTemplate', fn ($q) => $q->where('notification_enabled', true))
            ->whereNotIn('status', ['approved', 'declined'])
            ->whereNotNull('due_at')
            ->whereHas('offboardingRequest', fn ($q) => $q->whereIn('status', ['pending', 'in_progress']))
            ->with([
                'offboardingRequest.employee',
                'employee',
                'checklistTemplate.items',
                'checklistTemplate.notificationEmailTemplate',
                'itemAssignments.assignedEmployee',
                'reminderLogs',
            ])
            ->get();

        $sent = 0;

        foreach ($assignments as $assignment) {
            $sent += $this->processAssignment($assignment);
        }

        $this->info("Sent {$sent} checklist reminder(s).");

        return self::SUCCESS;
    }

    private function processAssignment(OffboardingRequestApprover $assignment): int
    {
        $template = $assignment->checklistTemplate;
        $emailTemplate = $template?->notificationEmailTemplate;

        if (! $emailTemplate?->is_active) {
            return 0;
        }

        $maxCycles = $template->notification_repeat ? max(1, (int) $template->notification_max_reminders) : 1;
        $sent = 0;

        for ($cycle = 1; $cycle <= $maxCycles; $cycle++) {
            $scheduledAt = $this->scheduledAtForCycle($assignment, $template, $cycle);

            if ($scheduledAt->isFuture()) {
                // Cycles only move later as $cycle increases — if this one
                // hasn't arrived yet, none of the following ones have
                // either.
                break;
            }

            $alreadyLoggedIds = $assignment->reminderLogs
                ->where('reminder_number', $cycle)
                ->pluck('recipient_employee_id');

            $pendingRecipients = $assignment->reminderRecipients()
                ->reject(fn (Employee $recipient) => $alreadyLoggedIds->contains($recipient->id));

            if ($pendingRecipients->isEmpty()) {
                continue;
            }

            // Guards the narrow window where the checklist was submitted/
            // approved AFTER this run's initial fetch but before this
            // specific assignment was reached — the base query above
            // already excludes an approved/declined checklist entirely on
            // its NEXT run, so this is the only place that case is ever
            // actually seen.
            $isComplete = in_array($assignment->fresh()->status, ['approved', 'declined'], true);

            foreach ($pendingRecipients as $recipient) {
                $status = $this->processRecipient($assignment, $template, $emailTemplate, $recipient, $cycle, $scheduledAt, $isComplete);

                if ($status === 'sent') {
                    $sent++;
                }
            }
        }

        return $sent;
    }

    /**
     * `notification_days_before` counted back from the checklist's own
     * CURRENT `due_at`, shifted forward by the repeat interval for cycles
     * after the first, and combined with the configured "Time to Send" (if
     * any — otherwise it inherits `due_at`'s own time-of-day, effectively
     * midnight, since `due_at` is always set from a plain date).
     */
    private function scheduledAtForCycle(OffboardingRequestApprover $assignment, ChecklistTemplate $template, int $cycle): Carbon
    {
        $scheduledAt = $assignment->due_at->copy()
            ->subDays($template->notification_days_before)
            ->addDays(($cycle - 1) * (int) $template->notification_repeat_interval_days);

        if ($template->notification_time) {
            $scheduledAt = Carbon::parse($scheduledAt->format('Y-m-d') . ' ' . $template->notification_time->format('H:i:s'));
        }

        return $scheduledAt;
    }

    private function processRecipient(
        OffboardingRequestApprover $assignment,
        ChecklistTemplate $template,
        EmailTemplate $emailTemplate,
        Employee $recipient,
        int $cycle,
        Carbon $scheduledAt,
        bool $isComplete,
    ): string {
        $status = 'skipped';
        $sentAt = null;

        if (! $isComplete) {
            $offboardee = $assignment->offboardingRequest->employee;

            [$subject, $body] = $emailTemplate->render(
                approverName: $recipient->name,
                offboardeeName: $offboardee->name,
                employeeNumber: $offboardee->employee_code,
                checklistName: $template->title,
                dueDate: $assignment->due_at->format('M d, Y'),
                department: $offboardee->department,
                position: $offboardee->designation,
                separationDate: $assignment->offboardingRequest->last_working_day?->format('M d, Y'),
            );

            try {
                Mail::to($recipient->email)->send(new ChecklistSignatoryAnnouncementMail($subject, $body));
                $status = 'sent';
                $sentAt = now();
            } catch (\Throwable $e) {
                $status = 'failed';
                Log::error('Failed to send checklist reminder.', [
                    'offboarding_request_approver_id' => $assignment->id,
                    'checklist_template_id' => $template->id,
                    'recipient' => $recipient->email,
                    'reminder_number' => $cycle,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        try {
            ChecklistReminderLog::create([
                'offboarding_request_approver_id' => $assignment->id,
                'offboarding_request_id' => $assignment->offboarding_request_id,
                'checklist_template_id' => $template->id,
                'email_template_id' => $emailTemplate->id,
                'recipient_employee_id' => $recipient->id,
                'recipient_email' => $recipient->email,
                'notification_type' => $template->notification_type,
                'reminder_number' => $cycle,
                'scheduled_at' => $scheduledAt,
                'sent_at' => $sentAt,
                'status' => $status,
            ]);
        } catch (QueryException $e) {
            // Unique (approver, recipient, cycle) constraint — another
            // concurrent run already logged this exact cycle for this
            // recipient. Safe to ignore: the whole point of that index is
            // to make a duplicate send/log impossible even under a race.
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
        }

        return $status;
    }
}
