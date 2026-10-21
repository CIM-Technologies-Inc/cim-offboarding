<?php

namespace App\Console\Commands;

use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\EmailTemplateSchedule;
use App\Models\Employee;
use App\Models\OffboardingRequest;
use App\Notifications\ScheduledEmailTemplateNotification;
use App\Services\OffboardeeEmailResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

class SendEmailTemplateSchedules extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-email-template-schedules';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Resolve every active EmailTemplateSchedule ("N days before/after Last Working Day") against every still-active offboarding request that hasn\'t been resolved for it yet.';

    /**
     * Nothing here is pre-materialized: the due date is recomputed fresh
     * from each request's live `last_working_day` on every run, so a Last
     * Working Day extension is picked up automatically with no explicit
     * recalculation step, exactly like every other date-driven command in
     * this app (`NotifyClearanceSigningDue`, `NotifyOverdueChecklists`,
     * `SendScheduledChecklistItemNotifications`). A ledger row only ever
     * appears once something has actually happened (sent/failed/cancelled)
     * — see `EmailTemplateScheduleSend`'s own docblock.
     */
    public function handle(): int
    {
        $schedules = EmailTemplateSchedule::where('is_active', true)
            ->whereHas('emailTemplate', fn ($q) => $q->where('is_active', true))
            ->with('emailTemplate')
            ->get();

        $processed = 0;

        foreach ($schedules as $schedule) {
            $processed += $this->processSchedule($schedule);
        }

        $this->info("Processed {$processed} schedule occurrence(s).");

        return self::SUCCESS;
    }

    private function processSchedule(EmailTemplateSchedule $schedule): int
    {
        $requests = OffboardingRequest::query()
            ->whereIn('status', ['pending', 'in_progress', 'cancelled'])
            ->whereNotNull('last_working_day')
            ->whereDoesntHave('scheduledEmailSends', fn ($q) => $q->where('email_template_schedule_id', $schedule->id))
            ->with(['employee', 'approvers.employee'])
            ->get();

        $processed = 0;

        foreach ($requests as $request) {
            // A request cancelled before this schedule ever got a chance to
            // fire is recorded once (so it's never re-evaluated again) and
            // skipped — never sent for a retracted/cancelled request.
            if ($request->status === 'cancelled') {
                $schedule->sends()->create([
                    'offboarding_request_id' => $request->id,
                    'status' => 'cancelled',
                ]);
                $processed++;

                continue;
            }

            $scheduledDate = $schedule->timing === 'before'
                ? $request->last_working_day->copy()->subDays($schedule->days)
                : $request->last_working_day->copy()->addDays($schedule->days);

            if ($scheduledDate->isFuture()) {
                continue;
            }

            $this->sendForRequest($schedule, $request, $scheduledDate);
            $processed++;
        }

        return $processed;
    }

    private function sendForRequest(EmailTemplateSchedule $schedule, OffboardingRequest $request, $scheduledDate): void
    {
        $template = $schedule->emailTemplate;
        $offboardee = $request->employee;

        $recipients = collect([$offboardee])
            ->merge($request->approvers->map(fn ($approver) => $approver->employee))
            ->filter()
            ->unique('id');

        $emailSentAt = null;
        $systemSentAt = null;

        try {
            if (in_array($schedule->channel, ['email', 'email_and_system'], true)) {
                $this->sendEmails($template, $request, $recipients);
                $emailSentAt = now();
            }

            if (in_array($schedule->channel, ['system', 'email_and_system'], true)) {
                $this->sendSystemNotifications($schedule, $request, $template, $recipients);
                $systemSentAt = now();
            }

            $schedule->sends()->create([
                'offboarding_request_id' => $request->id,
                'status' => 'sent',
                'scheduled_for' => $scheduledDate,
                'email_sent_at' => $emailSentAt,
                'system_sent_at' => $systemSentAt,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to process email template schedule.', [
                'email_template_schedule_id' => $schedule->id,
                'offboarding_request_id' => $request->id,
                'exception' => $e->getMessage(),
            ]);

            $schedule->sends()->create([
                'offboarding_request_id' => $request->id,
                'status' => 'failed',
                'scheduled_for' => $scheduledDate,
                'failure_reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Employee>  $recipients
     */
    private function sendEmails($template, OffboardingRequest $request, $recipients): void
    {
        $offboardee = $request->employee;

        // The offboardee's own entry in `$recipients` is notified at their
        // personal email, never the company one — everyone else merged into
        // this same flat list (every checklist approver) keeps using `email`.
        // See `OffboardeeEmailResolver`.
        $eligible = $recipients->filter(function (Employee $employee) use ($offboardee, $request, $template) {
            if ($employee->id === $offboardee->id) {
                return (bool) OffboardeeEmailResolver::resolve($request, $template->template_name);
            }

            return $employee->email && filter_var($employee->email, FILTER_VALIDATE_EMAIL);
        });

        foreach ($eligible as $recipient) {
            $recipientEmail = $recipient->id === $offboardee->id ? $recipient->personal_email : $recipient->email;

            [$subject, $body] = $template->render(
                approverName: $recipient->name,
                offboardeeName: $offboardee->name,
                employeeNumber: $offboardee->employee_code,
                department: $offboardee->department,
                position: $offboardee->designation,
                separationDate: $request->last_working_day?->format('M d, Y'),
            );

            Mail::to($recipientEmail)->cc($template->ccRecipientEmails($recipientEmail))->send(new ChecklistSignatoryAnnouncementMail($subject, $body));
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Employee>  $recipients
     */
    private function sendSystemNotifications(EmailTemplateSchedule $schedule, OffboardingRequest $request, $template, $recipients): void
    {
        $ccEmployees = collect($template->recipientEmployees)
            ->merge($template->recipientGroups->flatMap(fn ($group) => $group->employees));

        $users = $recipients->merge($ccEmployees)
            ->unique('id')
            ->map(fn (Employee $employee) => $employee->user)
            ->filter();

        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, new ScheduledEmailTemplateNotification($schedule, $request, $template->subject));
    }
}
