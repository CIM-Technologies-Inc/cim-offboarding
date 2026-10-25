<?php

namespace App\Jobs;

use App\Models\EmailTemplateSchedule;
use App\Models\OffboardingRequest;
use App\Services\EmailTemplateScheduleDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The eager, at-creation trigger for every active `event = 'creation'`
 * Email Notification Schedule — dispatched by
 * `OffboardingRequestController::notifyDepartmentHeads()` once a request's
 * checklists/General Signatories are attached, so a "0 days after
 * Creation" schedule doesn't have to wait for the next daily sweep.
 *
 * Queued (picked up within a minute by the scheduled `queue:work`) rather
 * than run inline, because `EmailTemplateScheduleDispatcher` sends
 * synchronously so its ledger reflects the real SMTP result — doing that
 * inside the request-creation HTTP request would block it on one SMTP
 * round trip per recipient. Shares the dispatcher's per-occurrence lock and
 * ledger with `app:send-email-template-schedules`, so the two can never
 * double-send. Any occurrence this job fails to deliver is recorded
 * `'failed'` and retried by that daily sweep, so the job itself never
 * needs to be retried by the queue.
 */
class ProcessCreationEmailTemplateSchedules implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $offboardingRequestId) {}

    public function handle(EmailTemplateScheduleDispatcher $dispatcher): void
    {
        $request = OffboardingRequest::with([
            'employee',
            'approvers.employee',
            'generalSignatoryApprovals.generalSignatory.clearanceSignatory',
        ])->find($this->offboardingRequestId);

        if (! $request) {
            return;
        }

        EmailTemplateSchedule::where('is_active', true)
            ->where('event', 'creation')
            ->whereHas('emailTemplate', fn ($q) => $q->where('is_active', true))
            ->with('emailTemplate', 'checklistTemplates:id', 'generalSignatories:id')
            ->get()
            ->each(fn (EmailTemplateSchedule $schedule) => $dispatcher->processForRequest($schedule, $request));
    }
}
