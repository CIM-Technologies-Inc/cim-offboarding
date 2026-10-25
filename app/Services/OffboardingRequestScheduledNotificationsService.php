<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmailTemplateSchedule;
use App\Models\EmailTemplateScheduleSend;
use App\Models\OffboardingRequest;
use Illuminate\Support\Carbon;

/**
 * Builds the real, per-request data behind the "Scheduled Email
 * Notifications" tab on the Offboardee request detail view — sibling to
 * `OffboardingRequestPreviewService` (same resulting shape), but against a
 * REAL, already-persisted `OffboardingRequest` instead of an unsaved shadow
 * one, and reporting ACTUAL delivery status from the `EmailTemplateScheduleSend`
 * ledger rather than a hypothetical "would this be sent" preview.
 *
 * Reuses `EmailTemplateScheduleDispatcher::computeScheduledDate()`/
 * `resolveRecipients()` — the exact same pure formulas the real scheduler
 * (`SendEmailTemplateSchedules` / `EmailTemplateScheduleDispatcher::processForRequest()`)
 * uses to decide what's due — and never calls any of that dispatcher's
 * side-effecting methods (`processSchedule()`/`processForRequest()`/
 * `sendForRequest()`), so building this tab's data can never send mail or
 * write a ledger row, however many times it's requested.
 */
class OffboardingRequestScheduledNotificationsService
{
    public function __construct(
        private EmailTemplateScheduleDispatcher $dispatcher,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forRequest(OffboardingRequest $request): array
    {
        $request->loadMissing([
            'employee',
            'approvers.employee',
            'generalSignatoryApprovals.generalSignatory.clearanceSignatory',
        ]);

        $schedules = EmailTemplateSchedule::where('is_active', true)
            ->whereHas('emailTemplate', fn ($q) => $q->where('is_active', true))
            ->with(['emailTemplate', 'checklistTemplates:id', 'generalSignatories:id'])
            ->get();

        return $schedules->map(function (EmailTemplateSchedule $schedule) use ($request) {
            $scheduledDate = $this->dispatcher->computeScheduledDate($schedule, $request);
            $recipients = $this->dispatcher->resolveRecipients($schedule, $request);
            $emailTemplate = $schedule->emailTemplate;

            $existing = $scheduledDate
                ? EmailTemplateScheduleSend::where('email_template_schedule_id', $schedule->id)
                    ->where('offboarding_request_id', $request->id)
                    ->where('scheduled_for', $scheduledDate->toDateString())
                    ->first()
                : null;

            [$status, $sentAt, $failureReason, $note] = $this->resolveStatus($scheduledDate, $existing, $request);

            return [
                'template_name' => $emailTemplate?->template_name,
                'event' => $schedule->event,
                'event_label' => EmailTemplateSchedule::EVENT_LABELS[$schedule->event] ?? $schedule->event,
                'label' => $schedule->label(),
                'channel' => $schedule->channel,
                'scheduled_date' => $scheduledDate?->format('M d, Y'),
                'status' => $status,
                'sent_at' => $sentAt?->format('M d, Y g:i A'),
                'failure_reason' => $failureReason,
                'note' => $note,
                'recipients' => $recipients->map(fn (Employee $recipient) => [
                    'name' => $recipient->name,
                    'email' => $recipient->email,
                ])->values()->all(),
                'cc_recipients' => $emailTemplate ? $emailTemplate->ccRecipientNames() : [],
            ];
        })->values()->all();
    }

    /**
     * Mirrors `EmailTemplateScheduleDispatcher::processForRequest()`'s own
     * real due/dedup branches exactly (see that method's own docblock) —
     * this is a READ of the same decision, not a second, independent
     * implementation of it:
     * - no reference date yet → 'pending_event' (the real dispatcher would
     *   also treat this as "not due," it just has no reason to report why).
     * - a 'sent' ledger row for this exact occurrence → 'sent' (the real
     *   dispatcher's own skip condition).
     * - a 'failed' ledger row for this exact occurrence → 'failed' (the
     *   real dispatcher retries this on its next sweep, never skips it).
     * - no row yet, date in the future → 'scheduled'.
     * - no row yet, date due/past → 'due' (exactly what the real dispatcher
     *   would process as "due" on its very next run).
     * - request cancelled/completed and nothing sent → 'not_applicable': the
     *   real dispatcher never sends for either (cancelled is recorded and
     *   skipped; completed is excluded from its sweep entirely), so showing
     *   "Scheduled"/"Failed — will retry" there would be wrong.
     *
     * @return array{0: string, 1: ?Carbon, 2: ?string, 3: ?string}
     */
    private function resolveStatus(?Carbon $scheduledDate, ?EmailTemplateScheduleSend $existing, OffboardingRequest $request): array
    {
        if ($existing?->status !== 'sent' && in_array($request->status, ['cancelled', 'completed'], true)) {
            return ['not_applicable', null, $existing?->failure_reason, $request->status === 'cancelled'
                ? 'Not sent — this offboarding request was retracted.'
                : 'Not sent — this offboarding request was completed before this notification was delivered.'];
        }

        if ($scheduledDate === null) {
            return ['pending_event', null, null, 'Pending Event — will be scheduled once the required milestone is reached.'];
        }

        if ($existing?->status === 'sent') {
            return ['sent', $existing->email_sent_at ?? $existing->system_sent_at, null, 'Already sent.'];
        }

        if ($existing?->status === 'failed') {
            return ['failed', null, $existing->failure_reason, 'Delivery failed — will automatically retry on the next scheduled check.'];
        }

        if ($scheduledDate->isFuture()) {
            return ['scheduled', null, null, 'Scheduled — will be sent automatically on the scheduled date.'];
        }

        return ['due', null, null, null];
    }
}
