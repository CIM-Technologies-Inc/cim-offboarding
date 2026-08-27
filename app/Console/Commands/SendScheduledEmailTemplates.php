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
    protected $description = 'Send every active, scheduled Email and Notification template that is due — either a "N days before/after Last Working Day" one-time trigger, or a recurring "every N days from request creation" schedule — for every still-active offboarding request.';

    /**
     * Two independent schedule types, each handled by its own method below:
     * 'one_time' (`processOneTimeTemplate()` — the original, unchanged
     * before/after-Last-Working-Day trigger, fires at most once ever per
     * (template, request)) and 'recurring'
     * (`processRecurringTemplate()` — fires every `schedule_interval_days`
     * days counted from the request's own `created_at`, until its Last
     * Working Day is reached). A template that's missing the columns its
     * own `schedule_type` needs (e.g. `schedule_interval_days` still null
     * on a 'recurring' template someone half-configured) is simply skipped
     * by the query below rather than erroring.
     */
    public function handle(): int
    {
        $templates = EmailTemplate::where('is_active', true)
            ->where('is_scheduled', true)
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('schedule_type', 'one_time')
                        ->whereNotNull('schedule_timing')
                        ->whereNotNull('schedule_days');
                })->orWhere(function ($q) {
                    $q->where('schedule_type', 'recurring')
                        ->whereNotNull('schedule_interval_days');
                });
            })
            ->get();

        $sent = 0;

        foreach ($templates as $template) {
            $sent += $template->schedule_type === 'recurring'
                ? $this->processRecurringTemplate($template)
                : $this->processOneTimeTemplate($template);
        }

        $this->info("Sent {$sent} scheduled email(s).");

        return self::SUCCESS;
    }

    /**
     * The original, unchanged one-time trigger: finds every pending/
     * in_progress request that hasn't received this template yet (ever —
     * `whereDoesntHave` with no date bound) and whose computed send date
     * (Last Working Day +/- schedule_days) is today or has already passed,
     * and sends it. This existence check — not the (now relaxed, see the
     * `allow_repeat_sends_...` migration) DB uniqueness — is what keeps a
     * one-time template from ever firing twice for the same request.
     */
    private function processOneTimeTemplate(EmailTemplate $template): int
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

    /**
     * The recurring trigger: every still-active request due today gets
     * sent once — "due" meaning the whole number of days elapsed since the
     * request's own `created_at` (not Last Working Day) is a positive
     * multiple of `schedule_interval_days`, e.g. day 5, 10, 15, ... for an
     * interval of 5. A request whose Last Working Day has already arrived
     * (`<=` today) is excluded entirely, so nothing further is ever sent
     * for it once offboarding reaches that day — matching "stop once the
     * current date reaches the Last Working Day" exactly, not "the day
     * after". `whereDoesntHave(...whereDate('sent_at', today))` is the
     * per-day duplicate guard this mode needs in place of the one-time
     * mode's DB uniqueness (dropped so this table can hold more than one
     * row per (template, request) pair) — it makes a same-day re-run of
     * this command (e.g. the scheduler firing twice) a safe no-op instead
     * of a double-send.
     */
    private function processRecurringTemplate(EmailTemplate $template): int
    {
        $today = now()->startOfDay();

        $requests = OffboardingRequest::query()
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereNotNull('last_working_day')
            ->where('last_working_day', '>', $today)
            ->whereDoesntHave('scheduledEmailSends', function ($q) use ($template, $today) {
                $q->where('email_template_id', $template->id)->whereDate('sent_at', $today);
            })
            ->with(['employee', 'approvers.employee'])
            ->get();

        $sent = 0;

        foreach ($requests as $request) {
            $daysSinceCreation = $request->created_at->copy()->startOfDay()->diffInDays($today);

            if ($daysSinceCreation <= 0 || $daysSinceCreation % $template->schedule_interval_days !== 0) {
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
