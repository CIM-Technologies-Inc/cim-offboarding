<?php

namespace App\Services;

use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\EmailLog;
use App\Models\EmailTemplateSchedule;
use App\Models\EmailTemplateScheduleSend;
use App\Models\Employee;
use App\Models\OffboardingRequest;
use App\Models\User;
use App\Notifications\ScheduledEmailTemplateNotification;
use App\Support\EmailLogContext;
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
 * The `clearance` event ("Clearance Due Date") is timed from each
 * checklist's own Clearance Due Date: the request's Last Working Day plus
 * that checklist template's CURRENT `clearance_signing_deadline_days` (the
 * same setting `NotifyClearanceSigningDue` uses). Checklists on a request
 * can have different deadlines, so a clearance schedule resolves one
 * occurrence per distinct deadline, each sent only to the Clearance
 * Signatories of the checklists sharing that deadline — see
 * `clearanceDeadlineGroups()` / `dueOccurrences()`. Nothing falls back to a
 * fixed number of days: a checklist with no deadline configured simply has
 * no Clearance Due Date. (The old per-schedule
 * `EmailTemplateSchedule.clearance_signing_deadline_days` is no longer
 * used.)
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
                'approvers.checklistTemplate',
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

        // The occurrence due right now: the schedule's initial date for a
        // one-time schedule, or — with Repeat on — the latest repeat
        // occurrence that has come due, within the schedule's stopping
        // window. Each occurrence has its own ledger row (keyed by its date
        // in `scheduled_for`) and its own lock below, so every repeat is
        // sent once and only once.
        //
        // A Clearance Due Date schedule can have several occurrences due on
        // different dates (one per distinct checklist deadline); each is its
        // own ledger row, lock and recipient set.
        $processed = false;

        foreach ($this->dueOccurrences($schedule, $request) as $date => $checklistTemplateIds) {
            if ($this->processOccurrence($schedule, $request, Carbon::parse($date), $checklistTemplateIds)) {
                $processed = true;
            }
        }

        return $processed;
    }

    /**
     * @param  array<int, int>|null  $checklistTemplateIds  Restricts recipients to these checklists' signatories (Clearance Due Date occurrences); null = the schedule's normal recipients.
     */
    private function processOccurrence(EmailTemplateSchedule $schedule, OffboardingRequest $request, Carbon $scheduledDate, ?array $checklistTemplateIds): bool
    {
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

            $this->sendForRequest($schedule, $request, $scheduledDate, $existing, $checklistTemplateIds);
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
    public function computeScheduledDate(EmailTemplateSchedule $schedule, OffboardingRequest $request, ?int $clearanceDeadlineDays = null): ?Carbon
    {
        $referenceDate = $this->referenceDate($schedule, $request, $clearanceDeadlineDays);

        if (! $referenceDate) {
            return null;
        }

        return $schedule->timing === 'before'
            ? $referenceDate->subDays($schedule->days)
            : $referenceDate->addDays($schedule->days);
    }

    /**
     * The event date a schedule is timed from (request creation, Last
     * Working Day, Clearance Due Date or Final Pay deadline), normalized to
     * the start of its calendar day — or null while that milestone doesn't
     * exist for this request yet. For the Clearance Due Date the caller
     * passes the checklist deadline being evaluated (see
     * `clearanceDeadlineGroups()`); without one there is no date.
     */
    public function referenceDate(EmailTemplateSchedule $schedule, OffboardingRequest $request, ?int $clearanceDeadlineDays = null): ?Carbon
    {
        $referenceDate = match ($schedule->event) {
            'creation' => $request->created_at,
            'last_working_day' => $request->last_working_day,
            // Clearance Due Date = Last Working Day + the checklist's
            // configured Clearance Signing Deadline (never a fixed default).
            'clearance' => $clearanceDeadlineDays !== null
                ? $request->last_working_day?->copy()->addDays($clearanceDeadlineDays)
                : null,
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
        return $referenceDate->copy()->startOfDay();
    }

    /**
     * Repeat occurrences: the initial date (`computeScheduledDate()`), then
     * every `repeat_interval_days` after it.
     *
     * Stopping window: a "Before" schedule (with at least 1 day) only
     * repeats while still BEFORE its event — no occurrence on or after the
     * event date, and nothing at all is sent once that date is reached
     * (e.g. 7 days before LWD every 2 days → 7, 5, 3, 1 days before).
     * "After" schedules — and 0-day ("On" the event) ones, where before and
     * after are the same day — keep repeating with no end date; they stop
     * only when the request does: completed requests are excluded from
     * `processSchedule()`'s sweep, and a cancelled/retracted request is
     * recorded and skipped at the top of `processForRequest()`.
     *
     * Everything is recomputed from the request's live dates on every run,
     * so a Last Working Day change or an edited schedule moves future
     * occurrences automatically.
     */
    private function repeatCutoff(EmailTemplateSchedule $schedule, OffboardingRequest $request, ?int $clearanceDeadlineDays = null): ?Carbon
    {
        return $schedule->timing === 'before' && $schedule->days > 0
            ? $this->referenceDate($schedule, $request, $clearanceDeadlineDays)
            : null;
    }

    /**
     * The most recent occurrence on or before `$today` (and inside the
     * stopping window), whether or not it is still sendable — null when the
     * schedule hasn't started yet or its milestone doesn't exist.
     */
    public function latestOccurrence(EmailTemplateSchedule $schedule, OffboardingRequest $request, ?Carbon $today = null, ?int $clearanceDeadlineDays = null): ?Carbon
    {
        $initial = $this->computeScheduledDate($schedule, $request, $clearanceDeadlineDays);
        $today = ($today ?? now())->copy()->startOfDay();

        if ($initial === null || $initial->gt($today)) {
            return null;
        }

        $interval = $schedule->repeatsEvery();

        if ($interval === null) {
            return $initial;
        }

        $cutoff = $this->repeatCutoff($schedule, $request, $clearanceDeadlineDays);
        $limit = $cutoff !== null && $cutoff->copy()->subDay()->lt($today) ? $cutoff->copy()->subDay() : $today;

        if ($initial->gt($limit)) {
            return null;
        }

        $steps = intdiv((int) round($initial->diffInDays($limit, true)), $interval);

        return $initial->copy()->addDays($steps * $interval);
    }

    /**
     * The occurrence to send right now, or null if none is due. One-time
     * schedules: the initial date once it has arrived (a late scheduler
     * still sends it). Repeating schedules: the latest occurrence that has
     * come due — earlier ones missed while the scheduler wasn't running are
     * superseded rather than sent in a burst — and, for a "Before"
     * schedule, only while its event date hasn't been reached.
     */
    public function dueOccurrence(EmailTemplateSchedule $schedule, OffboardingRequest $request, ?Carbon $today = null, ?int $clearanceDeadlineDays = null): ?Carbon
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $latest = $this->latestOccurrence($schedule, $request, $today, $clearanceDeadlineDays);

        if ($latest === null) {
            return null;
        }

        $cutoff = $schedule->repeatsEvery() !== null ? $this->repeatCutoff($schedule, $request, $clearanceDeadlineDays) : null;

        return $cutoff !== null && $today->gte($cutoff) ? null : $latest;
    }

    /**
     * The next occurrence strictly after `$today`, or null when there is
     * none (a one-time schedule already past, or a repeating "Before"
     * schedule that has reached its event date).
     */
    public function nextOccurrence(EmailTemplateSchedule $schedule, OffboardingRequest $request, ?Carbon $today = null, ?int $clearanceDeadlineDays = null): ?Carbon
    {
        $initial = $this->computeScheduledDate($schedule, $request, $clearanceDeadlineDays);
        $today = ($today ?? now())->copy()->startOfDay();

        if ($initial === null) {
            return null;
        }

        if ($initial->gt($today)) {
            return $initial;
        }

        $interval = $schedule->repeatsEvery();

        if ($interval === null) {
            return null;
        }

        $steps = intdiv((int) round($initial->diffInDays($today, true)), $interval) + 1;
        $next = $initial->copy()->addDays($steps * $interval);
        $cutoff = $this->repeatCutoff($schedule, $request, $clearanceDeadlineDays);

        return $cutoff !== null && $next->gte($cutoff) ? null : $next;
    }

    /**
     * Clearance Due Date groups for this request: every active checklist
     * template attached to it (narrowed to the schedule's saved checklist
     * selection, if it has one) that has a Clearance Signing Deadline
     * configured, grouped by that deadline — `[deadline days => [checklist
     * template ids]]`, read live from each template so an admin's change to
     * a deadline applies to every later calculation. General Signatories
     * have no Clearance Signing Deadline, so they never appear here; a
     * schedule whose only saved selection is General Signatories has no
     * clearance occurrences at all.
     *
     * @return array<int, array<int, int>>
     */
    public function clearanceDeadlineGroups(EmailTemplateSchedule $schedule, OffboardingRequest $request): array
    {
        $selectedChecklistIds = $schedule->checklistTemplates->pluck('id');

        if ($selectedChecklistIds->isEmpty() && $schedule->generalSignatories->isNotEmpty()) {
            return [];
        }

        $groups = [];

        foreach ($request->approvers as $approver) {
            $template = $approver->checklistTemplate;

            if (! $template || ! $template->is_active || $template->clearance_signing_deadline_days === null) {
                continue;
            }

            if ($selectedChecklistIds->isNotEmpty() && ! $selectedChecklistIds->contains($template->id)) {
                continue;
            }

            $groups[$template->clearance_signing_deadline_days][] = $template->id;
        }

        ksort($groups);

        return array_map(fn (array $ids) => array_values(array_unique($ids)), $groups);
    }

    /**
     * Every occurrence due right now, as `[Y-m-d => checklist template ids
     * | null]`. Non-clearance schedules: at most one, for their normal
     * recipients (null). Clearance Due Date schedules: one per checklist
     * deadline group that has an occurrence due; groups whose occurrences
     * fall on the same date are merged into one send to all of them.
     *
     * @return array<string, array<int, int>|null>
     */
    public function dueOccurrences(EmailTemplateSchedule $schedule, OffboardingRequest $request, ?Carbon $today = null): array
    {
        if ($schedule->event !== 'clearance') {
            $due = $this->dueOccurrence($schedule, $request, $today);

            return $due ? [$due->toDateString() => null] : [];
        }

        $occurrences = [];

        foreach ($this->clearanceDeadlineGroups($schedule, $request) as $deadlineDays => $checklistTemplateIds) {
            $due = $this->dueOccurrence($schedule, $request, $today, $deadlineDays);

            if ($due) {
                $occurrences[$due->toDateString()] = array_values(array_unique(array_merge($occurrences[$due->toDateString()] ?? [], $checklistTemplateIds)));
            }
        }

        ksort($occurrences);

        return $occurrences;
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
    private function sendForRequest(EmailTemplateSchedule $schedule, OffboardingRequest $request, Carbon $scheduledDate, ?EmailTemplateScheduleSend $existing, ?array $checklistTemplateIds = null): void
    {
        $template = $schedule->emailTemplate;
        // Each recipient WITH the checklist(s)/General Signatory row(s) they
        // were selected for — that context is what fills {{checklist_name}},
        // {{due_date}}, etc. per recipient (see `checklistPlaceholderValues()`).
        $contexts = $this->recipientContexts($schedule, $request, $checklistTemplateIds);

        $recipientLog = collect($existing?->recipient_log ?? [])->keyBy('key');
        $emailSentAt = $existing?->email_sent_at;
        $systemSentAt = $existing?->system_sent_at;
        $errors = [];

        if (in_array($schedule->channel, ['email', 'email_and_system'], true) && ! $emailSentAt) {
            // Logged as a Scheduled notification with the schedule's name and
            // intended send date — see EmailLogContext / EmailLogRecorder.
            $errors = EmailLogContext::scope([
                'notification_type' => EmailLog::TYPE_SCHEDULED,
                'trigger' => 'Scheduled email template',
                'schedule_name' => ($template?->template_name ?? 'Email template').' — '.$schedule->label(),
                'scheduled_for' => $scheduledDate->toDateString(),
                'offboarding_request_id' => $request->id,
                'offboardee_name' => $request->employee?->name,
            ], fn () => $this->sendEmails($template, $request, $contexts, $recipientLog));

            if ($errors === []) {
                $emailSentAt = now();
            }
        }

        if (in_array($schedule->channel, ['system', 'email_and_system'], true) && ! $systemSentAt) {
            try {
                $this->sendSystemNotifications($schedule, $request, $template, $contexts);
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
    public function resolveRecipients(EmailTemplateSchedule $schedule, OffboardingRequest $request, ?array $onlyChecklistTemplateIds = null): Collection
    {
        return $this->recipientContexts($schedule, $request, $onlyChecklistTemplateIds)
            ->map(fn (array $context) => $context['employee'])
            ->values();
    }

    /**
     * The same recipients `resolveRecipients()` returns (same selection
     * rules, same order, deduped by Employee id), but each one keeps the
     * request's own `OffboardingRequestApprover` rows (checklists) and
     * `OffboardingRequestGeneralSignatory` rows they were selected FOR in
     * this occurrence. Without this, the recipient list was a bare list of
     * employees and nothing downstream knew which checklist an email was
     * about — which is why {{checklist_name}}/{{due_date}} rendered blank.
     *
     * @return Collection<int, array{employee: Employee, approvers: Collection, general_signatory_approvals: Collection}>
     */
    public function recipientContexts(EmailTemplateSchedule $schedule, OffboardingRequest $request, ?array $onlyChecklistTemplateIds = null): Collection
    {
        // A Clearance Due Date occurrence goes only to the Clearance
        // Signatories of the checklists it was computed for.
        if ($onlyChecklistTemplateIds !== null) {
            $approvers = $request->approvers->whereIn('checklist_template_id', $onlyChecklistTemplateIds);
            $generalSignatoryApprovals = collect();
        } else {
            $selectedChecklistIds = $schedule->checklistTemplates->pluck('id');
            $selectedGeneralSignatoryIds = $schedule->generalSignatories->pluck('id');
            $hasAnySelection = $selectedChecklistIds->isNotEmpty() || $selectedGeneralSignatoryIds->isNotEmpty();

            $approvers = $selectedChecklistIds->isNotEmpty()
                ? $request->approvers->whereIn('checklist_template_id', $selectedChecklistIds)
                : ($hasAnySelection ? collect() : $request->approvers);

            $generalSignatoryApprovals = $selectedGeneralSignatoryIds->isNotEmpty()
                ? $request->generalSignatoryApprovals->whereIn('general_signatory_id', $selectedGeneralSignatoryIds)
                : ($hasAnySelection ? collect() : $request->generalSignatoryApprovals);
        }

        $contexts = collect();

        $add = function (?Employee $employee, string $bucket, $row) use ($contexts) {
            if (! $employee) {
                return;
            }

            $context = $contexts->get($employee->id) ?? [
                'employee' => $employee,
                'approvers' => collect(),
                'general_signatory_approvals' => collect(),
            ];
            $context[$bucket]->push($row);
            $contexts->put($employee->id, $context);
        };

        // Escalation-aware: the checklist's CURRENT active Clearance
        // Signatory; a checklist awaiting HR/Admin reassignment has none.
        foreach ($approvers as $approver) {
            $add($approver->activeClearanceSignatory(), 'approvers', $approver);
        }

        foreach ($generalSignatoryApprovals as $generalSignatoryApproval) {
            $add($generalSignatoryApproval->generalSignatory?->clearanceSignatory, 'general_signatory_approvals', $generalSignatoryApproval);
        }

        return $contexts;
    }

    /**
     * The checklist-specific placeholder values for ONE recipient of ONE
     * occurrence, resolved from the exact rows they were selected for —
     * the same per-checklist values the other checklist notifications
     * already pass (`SendChecklistReminders`/`NotifyOverdueChecklists`:
     * the template's title and the assignment's own `due_at`).
     *
     * "Active" checklist: a recipient's still-outstanding (not yet
     * approved) checklists in this occurrence are used; only if every one
     * of them is already approved do the approved ones show instead, so the
     * value is never blank for someone who was still emailed. A recipient
     * responsible for several checklists in the same occurrence gets one
     * email (the ledger is per address) listing each name, with
     * {{due_date}}/{{checklist_status}} in the same order — the same
     * convention as the Offboarding Request Notification
     * (`ChecklistApprovalNotifier::notifyDepartmentHeadsOfNewRequest()`).
     * A General Signatory row shows as "General Signatory Clearance", the
     * label `GeneralSignatoryApprovalController` already uses.
     *
     * @param  array{employee: Employee, approvers: Collection, general_signatory_approvals: Collection}  $context
     * @return array<string, ?string>
     */
    private function checklistPlaceholderValues(array $context): array
    {
        $activeOnly = fn (Collection $rows) => $rows->reject(fn ($row) => $row->status === 'approved')->values();

        $approvers = $activeOnly($context['approvers']);
        $generalSignatoryApprovals = $activeOnly($context['general_signatory_approvals']);

        if ($approvers->isEmpty() && $generalSignatoryApprovals->isEmpty()) {
            $approvers = $context['approvers']->values();
            $generalSignatoryApprovals = $context['general_signatory_approvals']->values();
        }

        $approvers = $approvers->sortBy(fn ($approver) => $approver->checklistTemplate?->title)->values();

        $entries = $approvers->map(fn ($approver) => [
            'name' => $approver->checklistTemplate?->title ?? 'Untitled Checklist',
            'due' => $approver->due_at?->format('M d, Y'),
            'status' => $approver->clearanceStatusLabel(),
        ])->concat($generalSignatoryApprovals->map(fn ($generalSignatoryApproval) => [
            'name' => 'General Signatory Clearance',
            'due' => $generalSignatoryApproval->due_at?->format('M d, Y'),
            'status' => $generalSignatoryApproval->clearanceStatusLabel(),
        ]));

        if ($entries->isEmpty()) {
            return [];
        }

        $pendingItems = $approvers->count() === 1
            ? $approvers->first()->itemsStatusTableHtml()
            : $approvers->map(fn ($approver) => '<p style="margin:12px 0 4px;font-weight:bold;">'.e($approver->checklistTemplate?->title ?? 'Untitled Checklist').'</p>'.$approver->itemsStatusTableHtml())->implode('');

        return [
            'checklistName' => $entries->pluck('name')->implode(', '),
            'dueDate' => $entries->pluck('due')->map(fn ($due) => $due ?? 'No due date')->implode(', '),
            'checklistStatus' => $entries->pluck('status')->implode(', '),
            'daysOverdue' => $approvers->count() === 1 ? (string) $approvers->first()->daysOverdue() : null,
            'pendingItems' => $pendingItems !== '' ? $pendingItems : null,
            'clearanceSignatoryName' => $context['employee']->name,
        ];
    }

    /**
     * Request-level placeholder values every recipient of this request
     * shares — formatted exactly as the other notifications format them
     * (e.g. `ChecklistApprovalNotifier::notifyOffboardee()`), so a
     * scheduled template using them no longer renders them blank either.
     *
     * @return array<string, ?string>
     */
    private function requestPlaceholderValues(OffboardingRequest $request): array
    {
        $offboardee = $request->employee;

        return [
            'offboardeeName' => $offboardee->name,
            'employeeNumber' => $offboardee->employee_code,
            'department' => $offboardee->department,
            'position' => $offboardee->designation,
            'dateHired' => $offboardee->date_of_joining?->format('M d, Y'),
            'separationDate' => $request->last_working_day?->format('M d, Y'),
            'reason' => $request->reason ? ucfirst($request->reason) : null,
            'requestDate' => $request->created_at?->format('M d, Y'),
            'offboardingRequestId' => (string) $request->id,
            'offboardingStatus' => ucfirst(str_replace('_', ' ', $request->displayStatus())),
        ];
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
     * @param  Collection<int, array{employee: Employee, approvers: Collection, general_signatory_approvals: Collection}>  $contexts
     * @param  Collection<string, array<string, mixed>>  $recipientLog
     * @return array<int, string>
     */
    private function sendEmails($template, OffboardingRequest $request, Collection $contexts, Collection $recipientLog): array
    {
        $requestValues = $this->requestPlaceholderValues($request);
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
        foreach ($contexts as $context) {
            $recipient = $context['employee'];
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

                [$subject, $body] = $template->render(...[
                    ...$requestValues,
                    ...$this->checklistPlaceholderValues($context),
                    'approverName' => $recipient->name,
                    'offboardingLinkUrl' => $recipientUser ? app(AutoLoginLinkService::class)->generate($recipientUser) : null,
                ]);

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
     * Same audience as before (every recipient plus the template's CC
     * employees/groups, deduped by Employee id), but the in-app message is
     * the template's subject with its placeholders filled for each person —
     * previously the raw subject was used, so a subject containing e.g.
     * {{checklist_name}} showed the literal token in-app.
     *
     * @param  Collection<int, array{employee: Employee, approvers: Collection, general_signatory_approvals: Collection}>  $contexts
     */
    private function sendSystemNotifications(EmailTemplateSchedule $schedule, OffboardingRequest $request, $template, Collection $contexts): void
    {
        $ccEmployees = collect($template->recipientEmployees)
            ->merge($template->recipientGroups->flatMap(fn ($group) => $group->employees));

        $employees = $contexts->map(fn (array $context) => $context['employee'])
            ->merge($ccEmployees)
            ->unique('id');

        $requestValues = $this->requestPlaceholderValues($request);

        foreach ($employees as $employee) {
            $user = $employee->user;

            if (! $user) {
                continue;
            }

            $context = $contexts->get($employee->id);

            [$subject] = $template->render(...[
                ...$requestValues,
                ...($context ? $this->checklistPlaceholderValues($context) : []),
                'approverName' => $employee->name,
            ]);

            Notification::send($user, new ScheduledEmailTemplateNotification($schedule, $request, $subject));
        }
    }
}
