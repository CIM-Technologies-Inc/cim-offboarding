<?php

namespace App\Services;

use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\EmailTemplateSchedule;
use App\Models\EmailTemplateScheduleSend;
use App\Models\Employee;
use App\Models\OffboardingRequest;
use App\Models\User;
use App\Notifications\ScheduledEmailTemplateNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Resolves and sends one `EmailTemplateSchedule` ("N days before/after
 * {Creation|Last Working Day|Clearance|Final Pay}") against whichever
 * offboarding request(s) are due for it — the shared logic behind both
 * `app:send-email-template-schedules` (sweeps every active schedule against
 * every eligible request, on its own interval) and
 * `ProcessCreationEmailTemplateSchedules`, queued by
 * `OffboardingRequestController::notifyDepartmentHeads()` (eagerly fires
 * just the `event = 'creation'` schedules for the ONE request that was just
 * created/reset, so a request doesn't have to wait for the next scheduler
 * tick). Both paths write to the exact same `EmailTemplateScheduleSend`
 * ledger, keyed per computed occurrence (`scheduled_for`) — whichever
 * caller resolves an occurrence first marks it `'sent'`, so the two can
 * never double-send the same one.
 *
 * The `clearance` event's own `EmailTemplateSchedule.clearance_signing_deadline_days`
 * is a DELIBERATELY SEPARATE, per-schedule setting from the pre-existing
 * `ChecklistTemplate.clearance_signing_deadline_days` (which drives the
 * unrelated, already-shipped `NotifyClearanceSigningDue` command). Both
 * default to 19 days and both compute off the same `last_working_day`, but
 * they are two independent systems by design — this class never reads from
 * or writes to the checklist-template-level one.
 */
class EmailTemplateScheduleDispatcher
{
    /**
     * Sweeps every offboarding request currently eligible for this
     * schedule — used by the console command. Nothing is pre-materialized:
     * the due date is recomputed fresh from each request's live
     * `last_working_day`/`created_at` on every run, so a Last Working Day
     * extension is picked up automatically with no explicit recalculation
     * step, exactly like every other date-driven command in this app.
     */
    public function processSchedule(EmailTemplateSchedule $schedule): int
    {
        $requests = OffboardingRequest::query()
            ->whereIn('status', ['pending', 'in_progress', 'cancelled'])
            ->when(
                in_array($schedule->event, ['last_working_day', 'clearance'], true),
                fn ($q) => $q->whereNotNull('last_working_day')
            )
            ->whereDoesntHave('scheduledEmailSends', fn ($q) => $q
                ->where('email_template_schedule_id', $schedule->id)
                ->where('status', 'cancelled'))
            ->with([
                'employee',
                'approvers.employee',
                'generalSignatoryApprovals.generalSignatory.clearanceSignatory',
            ])
            ->get();

        $processed = 0;

        foreach ($requests as $request) {
            if ($this->processForRequest($schedule, $request)) {
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * Evaluates and, if due, sends this ONE schedule for this ONE request —
     * the per-request body `processSchedule()`'s sweep already used,
     * extracted so it's independently callable for a single just-created/
     * just-reset request without re-querying every other eligible request
     * in the system. Returns whether anything was actually processed
     * (sent, failed-and-recorded, or recorded-cancelled) — a `false` here
     * (not yet due, or already delivered for this exact occurrence) is not
     * an error, just nothing new to do.
     */
    public function processForRequest(EmailTemplateSchedule $schedule, OffboardingRequest $request): bool
    {
        // A request cancelled before this schedule ever got a chance to
        // fire is recorded once (so it's never re-evaluated again) and
        // skipped — never sent for a retracted/cancelled request. Uses
        // `updateOrCreate` (not a blind `create()`) purely as a defensive
        // no-op against two overlapping runs racing each other.
        if ($request->status === 'cancelled') {
            $schedule->sends()->updateOrCreate(
                ['offboarding_request_id' => $request->id, 'scheduled_for' => null],
                ['status' => 'cancelled']
            );

            return true;
        }

        $scheduledDate = $this->computeScheduledDate($schedule, $request);

        if ($scheduledDate === null || $scheduledDate->isFuture()) {
            return false;
        }

        // Only one process may work on a given occurrence at a time — the
        // daily sweep, the eager at-creation job, and any duplicate
        // `schedule:run` invocation (e.g. two OS-level scheduler tasks
        // firing the same minute) would otherwise all read "no 'sent' row
        // yet" below and each send their own copy. Whoever doesn't get the
        // lock simply skips; the holder records the outcome.
        $lock = Cache::lock(self::occurrenceLockKey($schedule->id, $request->id, $scheduledDate->toDateString()), 600);

        if (! $lock->get()) {
            return false;
        }

        try {
            // Has THIS exact occurrence (the date actually computed from
            // today's live Last Working Day/creation date) already been
            // successfully delivered? If so, skip it — this is the only thing
            // that blocks a resend, scoped to one specific date rather than the
            // whole (schedule, request) pair, so an extension that produces a
            // DIFFERENT date is free to fire on its own merits, and a
            // `'failed'` row for this SAME date is retried below instead of
            // skipped. Read inside the lock so a run that was waiting on
            // another one sees that run's result.
            $existing = $schedule->sends()
                ->where('offboarding_request_id', $request->id)
                ->where('scheduled_for', $scheduledDate->toDateString())
                ->first();

            if ($existing?->status === 'sent') {
                return false;
            }

            $this->sendForRequest($schedule, $request, $scheduledDate, $existing);
        } finally {
            $lock->release();
        }

        return true;
    }

    public static function occurrenceLockKey(int $scheduleId, int $requestId, string $scheduledFor): string
    {
        return "email-template-schedule-send:{$scheduleId}:{$requestId}:{$scheduledFor}";
    }

    /**
     * The date this schedule actually comes due for this request. First
     * resolves a REFERENCE date from `$schedule->event` — `null` whenever
     * that milestone doesn't exist for this request yet (e.g. no Final Pay
     * checklist attached, or, defensively, an unset `last_working_day` —
     * shouldn't happen, it's required at request creation, but
     * `processSchedule()`'s own eligibility query only guards this for its
     * OWN sweep, not for a single-request call from elsewhere) — then
     * applies `timing`/`days` on top of it. A `null` reference date means
     * "don't send prematurely," never "send immediately" — propagated
     * straight through as `null`, which `processForRequest()` already
     * treats as "not due yet."
     *
     * Public (not just used internally) so `OffboardingRequestPreviewService`
     * can call this exact same formula against an unsaved, in-memory
     * "shadow" `OffboardingRequest` built from a New Offboarding Request
     * form's current values — reads only plain attributes (`created_at`/
     * `last_working_day`) and, via `finalPayReferenceDate()`, a relationship
     * *query* that degrades safely to `null` on an unsaved model (no id to
     * match against), which is exactly the "pending" preview behavior
     * wanted for a Final Pay schedule before any Final Pay checklist could
     * possibly exist yet.
     */
    public function computeScheduledDate(EmailTemplateSchedule $schedule, OffboardingRequest $request): ?Carbon
    {
        $referenceDate = match ($schedule->event) {
            'creation' => $request->created_at,
            'last_working_day' => $request->last_working_day,
            // A brand-new, per-SCHEDULE setting (default 19, admin-editable
            // per Timeline schedule) — deliberately independent of the
            // pre-existing per-CHECKLIST-TEMPLATE `clearance_signing_deadline_days`
            // that already drives `NotifyClearanceSigningDue`; see this
            // class's own docblock.
            'clearance' => $request->last_working_day?->copy()->addDays($schedule->clearance_signing_deadline_days ?? 19),
            'final_pay' => $this->finalPayReferenceDate($request),
            default => null,
        };

        if (! $referenceDate) {
            return null;
        }

        // Normalized to the start of the calendar day: a schedule is due
        // on a DATE (that's also what the ledger's `scheduled_for` stores
        // and what the Scheduled Email Notifications tab shows). Without
        // this, a reference carrying a time-of-day — `created_at` (e.g.
        // 15:45) or a Final Pay `due_at` (always 23:59:59) — stays "in the
        // future" through that whole day, so the daily 08:00 sweep only
        // picked it up the following day.
        $referenceDate = $referenceDate->copy()->startOfDay();

        return $schedule->timing === 'before'
            ? $referenceDate->subDays($schedule->days)
            : $referenceDate->addDays($schedule->days);
    }

    /**
     * The Final Pay milestone's own reference date — the LATEST `due_at`
     * among every currently-attached Final Pay `OffboardingRequestApprover`
     * row on this request (never `offboarding_requests.completed_at`: that
     * column is only ever set AFTER Final Pay is fully approved, which
     * would make "Before Final Pay" compute a date that's already in the
     * past the instant it becomes knowable at all — `due_at` is a genuine,
     * forward-looking deadline instead). A request can have zero, one, or
     * several Final Pay checklist templates depending on department — `MAX()`
     * treats "the whole Final Pay process" as due once every one of its
     * components is due, matching how an admin would actually think about
     * it. Returns `null` (→ `computeScheduledDate()` reports "not due yet,"
     * never sends prematurely) whenever no Final Pay checklist has attached
     * to this request yet — i.e. the Primary/Secondary stages aren't done.
     */
    private function finalPayReferenceDate(OffboardingRequest $request): ?Carbon
    {
        $maxDueAt = $request->approvers()
            ->whereHas('checklistTemplate', fn ($query) => $query->where('is_final_pay_checklist', true))
            ->max('due_at');

        return $maxDueAt ? Carbon::parse($maxDueAt) : null;
    }

    /**
     * Sends (or, for a `'failed'` occurrence, resumes) one occurrence and
     * records the outcome. Retry-safe: a channel that already completed on
     * an earlier attempt (`email_sent_at`/`system_sent_at` set) is not
     * repeated, and within the email channel every address already marked
     * `'sent'` in `recipient_log` is skipped — so a partial failure (one
     * recipient's mailbox rejected, or the SMTP server dropping mid-loop)
     * only re-sends to whoever didn't get it, never to everyone again. The
     * occurrence is `'sent'` only once every channel fully succeeded.
     */
    private function sendForRequest(EmailTemplateSchedule $schedule, OffboardingRequest $request, Carbon $scheduledDate, ?EmailTemplateScheduleSend $existing): void
    {
        $template = $schedule->emailTemplate;
        $recipients = $this->resolveRecipients($schedule, $request);

        $recipientLog = collect($existing?->recipient_log ?? [])->keyBy('key');
        $emailSentAt = $existing?->email_sent_at;
        $systemSentAt = $existing?->system_sent_at;
        $errors = [];

        if (in_array($schedule->channel, ['email', 'email_and_system'], true) && ! $emailSentAt) {
            $errors = $this->sendEmails($template, $request, $recipients, $recipientLog);

            if ($errors === []) {
                $emailSentAt = now();
            }
        }

        if (in_array($schedule->channel, ['system', 'email_and_system'], true) && ! $systemSentAt) {
            try {
                $this->sendSystemNotifications($schedule, $request, $template, $recipients);
                $systemSentAt = now();
            } catch (\Throwable $e) {
                $errors[] = 'System notification: '.$e->getMessage();
            }
        }

        if ($errors !== []) {
            Log::error('Failed to process email template schedule.', [
                'email_template_schedule_id' => $schedule->id,
                'offboarding_request_id' => $request->id,
                'scheduled_for' => $scheduledDate->toDateString(),
                'errors' => $errors,
            ]);
        }

        // `updateOrCreate`, not `create()` — this is a RETRY path when a
        // `'failed'` row already exists for this exact occurrence, so a
        // successful retry must flip that same row to `'sent'` rather
        // than attempt a second insert and collide with the unique
        // index.
        $schedule->sends()->updateOrCreate(
            ['offboarding_request_id' => $request->id, 'scheduled_for' => $scheduledDate->toDateString()],
            [
                'status' => $errors === [] ? 'sent' : 'failed',
                'email_sent_at' => $emailSentAt,
                'system_sent_at' => $systemSentAt,
                'failure_reason' => $errors === [] ? null : implode("\n", $errors),
                'recipient_log' => $recipientLog->values()->all(),
                'attempts' => ($existing?->attempts ?? 0) + 1,
            ]
        );
    }

    /**
     * Resolves WHO this one occurrence goes to — purely internal
     * stakeholders (Clearance Signatories, General Signatories), never the
     * offboardee and never a Task Assignee. Implements the "Active
     * Checklist Selection" / "Active General Signatory Selection"
     * scenarios:
     *
     * - Neither selected on the schedule ("no selection") → every checklist
     *   actually attached to THIS request's own Clearance Signatory, plus
     *   every General Signatory actually attached to THIS request's own
     *   Clearance Signatory. Never "every active checklist/General
     *   Signatory company-wide" — only what this specific request actually
     *   has.
     * - Checklists selected, General Signatories not → only the selected
     *   checklists' Clearance Signatories; General Signatories contribute
     *   nothing (not defaulted to "all").
     * - General Signatories selected, checklists not → the mirror image.
     * - Both selected → both scoped to their own selection.
     *
     * A selected checklist/General Signatory that isn't actually attached
     * to this particular request simply contributes no recipient for it —
     * never an error, never a fallback to "everyone". For a schedule fired
     * eagerly at request creation (the two creation-based timing kinds),
     * this also means a selection naming a Secondary/Final-Pay tier item —
     * not yet attached at that point — resolves to nobody for this
     * occurrence; a known, documented limitation (see
     * `OffboardingRequestController::notifyDepartmentHeads()`'s own
     * comment), not something this method needs to special-case.
     *
     * Deduped by Employee id only (`unique('id')`) — NOT by email address.
     * This same collection also feeds `sendSystemNotifications()`, where
     * collapsing two different Employee records that happen to share one
     * email would silently drop a legitimate second User's in-app
     * notification. The email-address-level dedup that actually matters
     * for the email channel lives in `sendEmails()` alone — see its own
     * docblock.
     *
     * Public for the same reason as `computeScheduledDate()` above — only
     * ever reads `$request->approvers`/`$request->generalSignatoryApprovals`
     * as already-loaded collections (never the `->approvers()` query-
     * builder relation, which would require a real saved id), so it works
     * correctly against `OffboardingRequestPreviewService`'s unsaved shadow
     * request with its relations manually `setRelation()`'d.
     *
     * @return Collection<int, Employee>
     */
    public function resolveRecipients(EmailTemplateSchedule $schedule, OffboardingRequest $request): Collection
    {
        $selectedChecklistIds = $schedule->checklistTemplates->pluck('id');
        $selectedGeneralSignatoryIds = $schedule->generalSignatories->pluck('id');
        $hasAnySelection = $selectedChecklistIds->isNotEmpty() || $selectedGeneralSignatoryIds->isNotEmpty();

        $clearanceSignatories = $selectedChecklistIds->isNotEmpty()
            ? $request->approvers->whereIn('checklist_template_id', $selectedChecklistIds)->map(fn ($approver) => $approver->employee)
            : ($hasAnySelection ? collect() : $request->approvers->map(fn ($approver) => $approver->employee));

        $generalSignatoryRecipients = $selectedGeneralSignatoryIds->isNotEmpty()
            ? $request->generalSignatoryApprovals->whereIn('general_signatory_id', $selectedGeneralSignatoryIds)->map(fn ($gsApproval) => $gsApproval->generalSignatory?->clearanceSignatory)
            : ($hasAnySelection ? collect() : $request->generalSignatoryApprovals->map(fn ($gsApproval) => $gsApproval->generalSignatory?->clearanceSignatory));

        return $clearanceSignatories->merge($generalSignatoryRecipients)->filter()->unique('id');
    }

    /**
     * Sends one email per eligible recipient, recording each address's
     * outcome into `$recipientLog` (keyed by normalized address) and
     * returning one error string per address that failed. Each send is
     * isolated — one rejected address never stops the rest of the loop.
     *
     * `sendNow()`, not `send()`: `ChecklistSignatoryAnnouncementMail` is
     * `ShouldQueue`, so a plain `send()` only writes a queue job — the
     * ledger would say `'sent'` before SMTP was ever contacted, and a
     * transport failure would surface only in `failed_jobs`, invisible to
     * this occurrence and never retried by it. This always runs from the
     * console (the scheduled sweep, or the queued at-creation
     * `ProcessCreationEmailTemplateSchedules` job), never in a web
     * request, so sending synchronously costs no HTTP latency.
     *
     * @param  Collection<int, Employee>  $recipients
     * @param  Collection<string, array<string, mixed>>  $recipientLog
     * @return array<int, string>
     */
    private function sendEmails($template, OffboardingRequest $request, Collection $recipients, Collection $recipientLog): array
    {
        $offboardee = $request->employee;
        $errors = [];
        $handledThisAttempt = [];

        // The offboardee is never a recipient of these schedules (see
        // `resolveRecipients()`) — every entry here is an internal
        // stakeholder, so always their plain company `email`.
        //
        // Deduped by NORMALIZED email address (trimmed, lowercased) —
        // `resolveRecipients()`'s own `unique('id')` only catches two rows
        // for the SAME Employee; it can't catch two DIFFERENT Employee
        // records that happen to share one email address (this app's own
        // `employees`/`users` tables don't enforce email uniqueness), which
        // would otherwise send that one inbox two separate copies of the
        // same notification.
        foreach ($recipients as $recipient) {
            $recipientEmail = trim((string) $recipient->email);
            $key = strtolower($recipientEmail);

            if ($recipientEmail === '' || ! filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                $key = $key !== '' ? $key : "employee:{$recipient->id}";
                $reason = $recipientEmail === '' ? 'No email address on file' : 'Invalid email address';

                $recipientLog->put($key, [
                    'key' => $key,
                    'employee_id' => $recipient->id,
                    'name' => $recipient->name,
                    'email' => $recipientEmail !== '' ? $recipientEmail : null,
                    'status' => 'skipped',
                    'error' => $reason,
                ]);

                Log::warning('Scheduled email template recipient skipped.', [
                    'employee_id' => $recipient->id,
                    'offboarding_request_id' => $request->id,
                    'reason' => $reason,
                ]);

                continue;
            }

            if (isset($handledThisAttempt[$key]) || ($recipientLog->get($key)['status'] ?? null) === 'sent') {
                continue;
            }

            $handledThisAttempt[$key] = true;
            $cc = $template->ccRecipientEmails($recipientEmail);
            $entry = ['key' => $key, 'employee_id' => $recipient->id, 'name' => $recipient->name, 'email' => $key, 'cc' => $cc];

            try {
                $recipientUser = User::firstWhere('username', $recipient->employee_code_digits);

                [$subject, $body] = $template->render(
                    approverName: $recipient->name,
                    offboardeeName: $offboardee->name,
                    employeeNumber: $offboardee->employee_code,
                    department: $offboardee->department,
                    position: $offboardee->designation,
                    separationDate: $request->last_working_day?->format('M d, Y'),
                    offboardingLinkUrl: $recipientUser ? app(AutoLoginLinkService::class)->generate($recipientUser) : null,
                );

                Mail::to($recipientEmail)->cc($cc)->sendNow(new ChecklistSignatoryAnnouncementMail($subject, $body));

                $recipientLog->put($key, $entry + ['status' => 'sent', 'sent_at' => now()->toDateTimeString()]);
            } catch (\Throwable $e) {
                $recipientLog->put($key, $entry + ['status' => 'failed', 'error' => $e->getMessage()]);
                $errors[] = "{$key}: {$e->getMessage()}";
            }
        }

        return $errors;
    }

    /**
     * @param  Collection<int, Employee>  $recipients
     */
    private function sendSystemNotifications(EmailTemplateSchedule $schedule, OffboardingRequest $request, $template, Collection $recipients): void
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
