<?php

namespace App\Console\Commands;

use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\OffboardingRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendScheduledEmailTemplates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-scheduled-email-templates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send every active, scheduled Email and Notification template whose "N days before/after Last Working Day" date has arrived, for every still-active offboarding request.';

    /**
     * For each scheduled template, finds every pending/in_progress request
     * that hasn't received it yet and whose computed send date (Last
     * Working Day +/- schedule_days) is today or has already passed, and
     * sends it. The `whereDoesntHave` guard plus the unique DB constraint on
     * `email_template_scheduled_sends` (email_template_id, offboarding_request_id)
     * ensure a re-run — or a run after the exact due day was missed — never
     * sends the same template twice for the same request.
     */
    public function handle(): int
    {
        $templates = EmailTemplate::where('is_active', true)
            ->where('is_scheduled', true)
            ->whereNotNull('schedule_timing')
            ->whereNotNull('schedule_days')
            ->get();

        $sent = 0;

        foreach ($templates as $template) {
            $sent += $this->processTemplate($template);
        }

        $this->info("Sent {$sent} scheduled email(s).");

        return self::SUCCESS;
    }

    private function processTemplate(EmailTemplate $template): int
    {
        $requests = OffboardingRequest::query()
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereNotNull('last_working_day')
            ->whereDoesntHave('scheduledEmailSends', fn ($q) => $q->where('email_template_id', $template->id))
            ->with(['employee', 'approvers.employee'])
            ->get();

        $sent = 0;

        foreach ($requests as $request) {
            $scheduledDate = $template->schedule_timing === 'before'
                ? $request->last_working_day->copy()->subDays($template->schedule_days)
                : $request->last_working_day->copy()->addDays($template->schedule_days);

            if ($scheduledDate->isFuture()) {
                continue;
            }

            $this->sendForRequest($template, $request);
            $sent++;
        }

        return $sent;
    }

    private function sendForRequest(EmailTemplate $template, OffboardingRequest $request): void
    {
        $offboardee = $request->employee;

        $recipients = collect([$offboardee])
            ->merge($request->approvers->map(fn ($approver) => $approver->employee))
            ->filter()
            ->unique('id')
            ->filter(fn (Employee $employee) => $employee->email && filter_var($employee->email, FILTER_VALIDATE_EMAIL));

        foreach ($recipients as $recipient) {
            [$subject, $body] = $template->render(
                approverName: $recipient->name,
                offboardeeName: $offboardee->name,
                employeeNumber: $offboardee->employee_code,
                department: $offboardee->department,
                position: $offboardee->designation,
                separationDate: $request->last_working_day?->format('M d, Y'),
            );

            try {
                Mail::to($recipient->email)->send(new ChecklistSignatoryAnnouncementMail($subject, $body));
            } catch (\Throwable $e) {
                Log::error('Failed to send scheduled email template.', [
                    'email_template_id' => $template->id,
                    'offboarding_request_id' => $request->id,
                    'recipient' => $recipient->email,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $template->scheduledSends()->create([
            'offboarding_request_id' => $request->id,
            'sent_at' => now(),
        ]);
    }
}
