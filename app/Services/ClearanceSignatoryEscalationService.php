<?php

namespace App\Services;

use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\ActivityLog;
use App\Models\ChecklistDelegation;
use App\Models\ChecklistSignatoryAssignment;
use App\Models\ChecklistTemplate;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use App\Models\User;
use App\Notifications\ClearanceSignatoryEscalationNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Secondary Clearance Signatory & Automatic Escalation — the one place the
 * assignment lifecycle of a checklist instance's Clearance Signatory is
 * changed:
 *
 *   Assigned to Primary --(primary deadline passed)--> Escalated to Secondary
 *   Escalated to Secondary --(secondary deadline passed)--> HR/Admin Action Required
 *   Reassigned by HR/Admin --(that deadline passed)--> HR/Admin Action Required
 *   any open state --(HR/Admin "Assign New Clearance Signatory")--> Reassigned by HR/Admin
 *
 * (Completed / Cancelled are derived — see `OffboardingRequestApprover::assignmentState()`.)
 *
 * `offboarding_request_approvers.employee_id` remains the authoritative
 * CURRENT Clearance Signatory: every existing access check
 * (`ApprovalController::authorizeAssignment()` etc.) and Clearance
 * Signatory notification already reads it, so moving it here is what moves
 * responsibility. Individual task assignments (`checklist_item_assignments`)
 * are never touched.
 *
 * Deadline convention: the assignment timestamp plus N days, to the minute,
 * in the app timezone (`config('app.timezone')`). Calendar days count every
 * day; working days skip Saturdays, Sundays and
 * `config('offboarding.escalation_holidays')`. An assignment at Oct 1 09:00
 * with 10 calendar days therefore expires Oct 11 09:00, and the scheduler
 * (every 5 minutes) escalates it on its first run at/after that moment —
 * never before.
 *
 * Concurrency/idempotency: every transition re-reads the row with
 * `lockForUpdate()` inside a transaction and re-checks the state it expects,
 * so a repeated/overlapping scheduler run, or a scheduler run racing an
 * HR/Admin reassignment, can apply a transition at most once.
 * Notifications are sent only after the transition commits, and their
 * per-recipient outcome is recorded on the history row so a failed email is
 * retried later without repeating the transfer.
 */
class ClearanceSignatoryEscalationService
{
    public const TEMPLATE_ESCALATED_TO_YOU = 'Clearance Signatory Escalation — Assigned to You';

    public const TEMPLATE_TRANSFERRED_AWAY = 'Clearance Signatory Responsibility Transferred';

    public const TEMPLATE_HR_NOTICE = 'Clearance Signatory Escalation — HR/Admin Notice';

    public const TEMPLATE_HR_ACTION_REQUIRED = 'Clearance Signatory Overdue — HR/Admin Action Required';

    public const TEMPLATE_HR_ASSIGNED = 'Clearance Signatory Assigned by HR/Admin';

    public const REASSIGN_PERMISSION = 'checklist-signatories.reassign';

    public const MAX_NOTIFICATION_ATTEMPTS = 3;

    private const DATE_FORMAT = 'M d, Y g:i A';

    private const OPEN_REQUEST_STATUSES = ['pending', 'in_progress'];

    /** Approver statuses that can still be escalated/reassigned. */
    private const OPEN_APPROVER_STATUSES = ['pending', 'viewed', 'on_hold'];

    public function __construct(private AutoLoginLinkService $autoLoginLinkService) {}

    // ------------------------------------------------------------------
    // Rules
    // ------------------------------------------------------------------

    /**
     * Why this employee can't be a Clearance Signatory (null = eligible):
     * must exist, be an active Employee Master record, not be the offboardee
     * themselves, and — when they already have a login — that account must
     * not be blocked and must have Approvals access (`approvals.view`, the
     * permission every Clearance Signatory action route requires). An
     * employee with no account yet is eligible: one is provisioned with the
     * Approver role on assignment, the app's existing convention
     * (`User::findOrCreateApprover()`).
     */
    public function eligibilityError(?Employee $employee, ?OffboardingRequest $request = null): ?string
    {
        if (! $employee) {
            return 'The selected employee does not exist in Employee Master.';
        }

        if ($employee->status !== 'active') {
            return "{$employee->name} is not an active employee and cannot be a Clearance Signatory.";
        }

        if ($request && $employee->id === $request->employee_id) {
            return "{$employee->name} is the offboardee on this request and cannot sign their own checklist.";
        }

        $user = User::firstWhere('username', $employee->employee_code_digits);

        if ($user) {
            if ($user->isBlocked()) {
                return "{$employee->name}'s user account is blocked.";
            }

            if (! $user->can('approvals.view')) {
                return "{$employee->name}'s user account does not have Approvals access, which clearance-signatory actions require. Grant it on the Roles & Permissions page first.";
            }
        }

        return null;
    }

    /**
     * Every employee `eligibilityError()` would accept (offboardee check
     * aside — that's per request), shaped for the searchable selectors:
     * active, and either no login yet or a non-blocked login with Approvals
     * access. Computed with set lookups rather than a permission check per
     * employee. `$includeId` keeps a currently-saved choice listed even if
     * it has since become ineligible, so the form can show (and validation
     * explain) it.
     *
     * @return Collection<int, array{id: int, name: string, employee_code: ?string, department: ?string, position: ?string}>
     */
    public function eligibleEmployeeOptions(?int $includeId = null): Collection
    {
        $usernamesWithAccess = User::permission('approvals.view')->whereNull('blocked_at')->pluck('username')->flip();
        $allUsernames = User::pluck('username')->flip();

        return Employee::where(fn ($query) => $query->where('status', 'active')->when($includeId, fn ($q) => $q->orWhere('id', $includeId)))
            ->orderBy('name')
            ->get(['id', 'name', 'employee_code', 'employee_code_digits', 'department', 'designation', 'position', 'status'])
            ->filter(fn (Employee $employee) => $employee->id === $includeId
                || ! $allUsernames->has($employee->employee_code_digits)
                || $usernamesWithAccess->has($employee->employee_code_digits))
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_code' => $employee->employee_code,
                'department' => $employee->department,
                'position' => $employee->position ?: $employee->designation,
            ])
            ->values();
    }

    public function computeDeadline(Carbon $from, int $days, ?string $dayType): Carbon
    {
        $deadline = $from->copy();

        if ($dayType !== 'working') {
            return $deadline->addDays($days);
        }

        $holidays = config('offboarding.escalation_holidays', []);
        $added = 0;

        while ($added < $days) {
            $deadline->addDay();

            if ($deadline->isWeekend() || in_array($deadline->toDateString(), $holidays, true)) {
                continue;
            }

            $added++;
        }

        return $deadline;
    }

    /**
     * The completion period an HR/Admin reassignment would start right now,
     * for the reassignment modal's "Calculated deadline". Null when this
     * checklist has no escalation period configured (no deadline monitoring).
     */
    public function previewReassignmentDeadline(OffboardingRequestApprover $approver): ?Carbon
    {
        return $approver->escalation_days
            ? $this->computeDeadline(now(), $approver->escalation_days, $approver->escalation_day_type)
            : null;
    }

    // ------------------------------------------------------------------
    // Initial assignment (called when a checklist instance is created)
    // ------------------------------------------------------------------

    /**
     * Snapshots the template's Secondary Clearance Signatory & Escalation
     * Settings onto a just-created checklist instance and starts the
     * primary signatory's completion period from the ACTUAL assignment
     * time. Called by `ChecklistApprovalNotifier::attachAndNotify()` for
     * every stage (initial, Secondary, Final Pay). A headless "Use Task
     * Assignee as Clearance Signatory" checklist has no Clearance Signatory
     * to escalate and is left untracked. The existing assignment email is
     * still sent by the caller, unchanged.
     */
    public function initializeAssignment(OffboardingRequestApprover $approver, ChecklistTemplate $template): void
    {
        if (! $approver->employee_id || $template->use_task_assignee_as_signatory) {
            return;
        }

        $assignedAt = now();
        $config = $this->resolveConfiguration($template, $approver->employee_id, $approver->offboardingRequest);
        $secondary = $config['secondary'];
        $enabled = $config['escalates'];
        $note = $config['note'];

        $deadline = $enabled ? $this->computeDeadline($assignedAt, (int) $config['days'], $config['day_type']) : null;

        $approver->update([
            'primary_signatory_employee_id' => $approver->employee_id,
            'secondary_signatory_employee_id' => $secondary?->id,
            'escalation_enabled' => $enabled,
            'escalation_days' => $config['days'],
            'escalation_day_type' => $config['day_type'],
            'escalation_notify_hr' => (bool) $template->escalation_notify_hr,
            'escalation_status' => OffboardingRequestApprover::ESCALATION_ASSIGNED_TO_PRIMARY,
            'signatory_assigned_at' => $assignedAt,
            'signatory_deadline_at' => $deadline,
            'primary_deadline_at' => $deadline,
            'escalation_note' => $note,
        ]);

        $this->recordEvent($approver, ChecklistSignatoryAssignment::EVENT_INITIAL, ChecklistSignatoryAssignment::TRIGGER_SYSTEM, [
            'stage' => 'primary',
            'new_employee' => $approver->employee,
            'assigned_at' => $assignedAt,
            'deadline_at' => $deadline,
            'previous_status' => null,
            'new_status' => OffboardingRequestApprover::ESCALATION_ASSIGNED_TO_PRIMARY,
            'reason' => $note ?? ($deadline ? 'Assigned to the primary Clearance Signatory.' : 'Assigned to the primary Clearance Signatory (automatic escalation not enabled).'),
            // The existing assignment email/in-app notification is sent by
            // the attach flow itself.
            'notification_status' => null,
        ]);
    }

    /**
     * What a checklist template's Secondary Clearance Signatory & Escalation
     * Settings resolve to for ONE request, given that request's primary
     * signatory — the single rule set shared by `initializeAssignment()`
     * (which snapshots it) and the New Offboarding Request review screen
     * (`OffboardingRequestPreviewService`, which displays it), so what HR/Admin
     * reviews is exactly what submission applies.
     *
     * `escalates` is false — with a `note` saying why — when the configured
     * secondary can't actually be used on this request (missing, the same
     * person as the primary, or not eligible, e.g. inactive or the offboardee).
     *
     * @return array{feature_on: bool, secondary: ?Employee, escalates: bool, days: ?int, day_type: string, note: ?string}
     */
    public function resolveConfiguration(ChecklistTemplate $template, ?int $primaryEmployeeId, ?OffboardingRequest $request): array
    {
        // "Enable Secondary Approver" off: the feature is disabled for this
        // checklist — its saved settings (kept so re-enabling restores them)
        // are ignored.
        $featureOn = (bool) $template->secondary_approver_enabled && ! $template->use_task_assignee_as_signatory;
        $secondary = $featureOn && $template->secondary_signatory_employee_id ? Employee::find($template->secondary_signatory_employee_id) : null;
        $escalates = $featureOn && (bool) $template->escalation_enabled && (int) $template->escalation_days > 0;
        $note = null;

        if ($escalates) {
            if (! $secondary) {
                $escalates = false;
                $note = 'Automatic escalation not applied: no valid secondary Clearance Signatory configured.';
            } elseif ($secondary->id === $primaryEmployeeId) {
                $escalates = false;
                $note = 'Automatic escalation not applied: the secondary Clearance Signatory is the same person as the primary on this request.';
            } elseif ($error = $this->eligibilityError($secondary, $request)) {
                $escalates = false;
                $note = 'Automatic escalation not applied: '.$error;
            }
        }

        return [
            'feature_on' => $featureOn,
            'secondary' => $secondary,
            'escalates' => $escalates,
            'days' => $featureOn ? ($template->escalation_days ?: null) : null,
            'day_type' => $template->escalation_day_type ?: 'calendar',
            'note' => $note,
        ];
    }

    // ------------------------------------------------------------------
    // Scheduler
    // ------------------------------------------------------------------

    /**
     * One scheduler pass: applies every due transition, then retries any
     * escalation notifications that previously failed.
     *
     * @return array{escalated: int, hr_action_required: int, stopped: int, notifications_retried: int}
     */
    public function processDue(): array
    {
        $counts = ['escalated' => 0, 'hr_action_required' => 0, 'stopped' => 0, 'notifications_retried' => 0];

        $dueIds = OffboardingRequestApprover::query()
            ->whereIn('escalation_status', [
                OffboardingRequestApprover::ESCALATION_ASSIGNED_TO_PRIMARY,
                OffboardingRequestApprover::ESCALATION_ESCALATED_TO_SECONDARY,
                OffboardingRequestApprover::ESCALATION_REASSIGNED_BY_HR,
            ])
            ->whereIn('status', self::OPEN_APPROVER_STATUSES)
            ->whereNotNull('signatory_deadline_at')
            ->where('signatory_deadline_at', '<=', now())
            ->whereHas('offboardingRequest', fn ($query) => $query->whereIn('status', self::OPEN_REQUEST_STATUSES))
            ->orderBy('signatory_deadline_at')
            ->pluck('id');

        foreach ($dueIds as $approverId) {
            try {
                $outcome = $this->processOne($approverId);
            } catch (\Throwable $e) {
                // One bad row never blocks the rest; it is simply retried on
                // the next run (nothing was committed for it).
                Log::error('Clearance signatory escalation failed for one checklist.', [
                    'offboarding_request_approver_id' => $approverId,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            if ($outcome) {
                $counts[$outcome]++;
            }
        }

        $counts['notifications_retried'] = $this->retryFailedNotifications();

        return $counts;
    }

    /**
     * Applies the transition due for ONE checklist instance, if it is still
     * due once re-read under a row lock. Returns which transition happened
     * ('escalated' | 'hr_action_required' | 'stopped') or null when nothing
     * was due any more (completed, cancelled, reassigned meanwhile, or
     * already processed by an overlapping run).
     */
    public function processOne(int $approverId): ?string
    {
        $result = DB::transaction(function () use ($approverId) {
            /** @var OffboardingRequestApprover|null $approver */
            $approver = OffboardingRequestApprover::whereKey($approverId)->lockForUpdate()->first();

            if (! $approver || ! $this->isStillDue($approver)) {
                return null;
            }

            $approver->loadMissing('offboardingRequest', 'employee');

            return match ($approver->escalation_status) {
                OffboardingRequestApprover::ESCALATION_ASSIGNED_TO_PRIMARY => $this->escalatePrimary($approver),
                OffboardingRequestApprover::ESCALATION_ESCALATED_TO_SECONDARY => $this->failCurrentAssignment($approver, 'secondary', $approver->secondary_signatory_employee_id),
                OffboardingRequestApprover::ESCALATION_REASSIGNED_BY_HR => $this->failCurrentAssignment($approver, 'hr_assigned', $approver->employee_id),
                default => null,
            };
        });

        if ($result && $result['event']) {
            $this->deliver($result['event']);
        }

        return $result['outcome'] ?? null;
    }

    private function isStillDue(OffboardingRequestApprover $approver): bool
    {
        return in_array($approver->escalation_status, [
            OffboardingRequestApprover::ESCALATION_ASSIGNED_TO_PRIMARY,
            OffboardingRequestApprover::ESCALATION_ESCALATED_TO_SECONDARY,
            OffboardingRequestApprover::ESCALATION_REASSIGNED_BY_HR,
        ], true)
            && in_array($approver->status, self::OPEN_APPROVER_STATUSES, true)
            && $approver->signatory_deadline_at !== null
            && $approver->signatory_deadline_at->lte(now())
            && in_array(OffboardingRequest::whereKey($approver->offboarding_request_id)->value('status'), self::OPEN_REQUEST_STATUSES, true);
    }

    /**
     * First-level escalation: primary missed their deadline.
     */
    private function escalatePrimary(OffboardingRequestApprover $approver): array
    {
        // Someone else (e.g. a Permanent Clearance Signatory replacement)
        // already moved this checklist away from the primary — the spec's
        // "no other process has already reassigned the checklist". Stop
        // monitoring rather than re-evaluating it every run.
        if ($approver->employee_id !== $approver->primary_signatory_employee_id || ! $approver->escalation_enabled) {
            return $this->stopMonitoring($approver, 'Automatic escalation stopped: the Clearance Signatory was changed outside the escalation workflow.');
        }

        $primary = $approver->employee;
        $secondary = $approver->secondary_signatory_employee_id ? Employee::find($approver->secondary_signatory_employee_id) : null;
        $error = $secondary
            ? ($secondary->id === $approver->employee_id ? 'the secondary Clearance Signatory is the same person as the current one.' : $this->eligibilityError($secondary, $approver->offboardingRequest))
            : 'no secondary Clearance Signatory is configured.';

        // The configured secondary can't take over any more (left the
        // company, account blocked, …) — never auto-assign anyone else;
        // hand straight to HR/Admin instead.
        if ($error) {
            return $this->requireHrAction($approver, 'primary', "The primary Clearance Signatory ({$primary?->name}) did not complete the checklist by the deadline, and the secondary cannot take over: {$error}", markSecondaryFailed: false);
        }

        $now = now();
        $deadline = $this->computeDeadline($now, (int) $approver->escalation_days, $approver->escalation_day_type);

        User::findOrCreateApprover($secondary);

        $approver->update([
            'employee_id' => $secondary->id,
            // The previous signatory's "viewed" doesn't carry over to the
            // new one; an on-hold checklist stays on hold.
            'status' => $approver->status === 'viewed' ? 'pending' : $approver->status,
            'escalation_status' => OffboardingRequestApprover::ESCALATION_ESCALATED_TO_SECONDARY,
            'escalated_at' => $now,
            'secondary_assigned_at' => $now,
            'secondary_deadline_at' => $deadline,
            'signatory_assigned_at' => $now,
            'signatory_deadline_at' => $deadline,
            ...$this->clearDelegation($approver),
        ]);

        $reason = "The primary Clearance Signatory ({$primary?->name}) did not complete the checklist within the configured {$this->periodLabel($approver)} (deadline {$approver->primary_deadline_at?->format(self::DATE_FORMAT)}).";

        $event = $this->recordEvent($approver, ChecklistSignatoryAssignment::EVENT_ESCALATED, ChecklistSignatoryAssignment::TRIGGER_SCHEDULER, [
            'stage' => 'secondary',
            'previous_employee' => $primary,
            'new_employee' => $secondary,
            'assigned_at' => $now,
            'deadline_at' => $deadline,
            'previous_status' => OffboardingRequestApprover::ESCALATION_ASSIGNED_TO_PRIMARY,
            'new_status' => OffboardingRequestApprover::ESCALATION_ESCALATED_TO_SECONDARY,
            'reason' => $reason,
        ]);

        $this->audit($approver, 'clearance_signatory_escalated', "\"{$this->checklistTitle($approver)}\" Clearance Signatory escalated automatically: {$primary?->name} → {$secondary->name}. {$reason}", null, [
            'previous_employee_id' => $primary?->id,
            'new_employee_id' => $secondary->id,
            'deadline_at' => $deadline->toDateTimeString(),
            'history_id' => $event->id,
        ]);

        return ['outcome' => 'escalated', 'event' => $event];
    }

    /**
     * Second-level (or post-HR-reassignment) failure: the current signatory
     * missed their deadline. Never auto-assigns anyone — waits for HR/Admin.
     */
    private function failCurrentAssignment(OffboardingRequestApprover $approver, string $stage, ?int $expectedEmployeeId): array
    {
        if ($approver->employee_id !== $expectedEmployeeId) {
            return $this->stopMonitoring($approver, 'Automatic escalation stopped: the Clearance Signatory was changed outside the escalation workflow.');
        }

        $who = $stage === 'secondary' ? 'secondary Clearance Signatory' : 'Clearance Signatory assigned by HR/Admin';

        return $this->requireHrAction(
            $approver,
            $stage,
            "The {$who} ({$approver->employee?->name}) also did not complete the checklist within the configured {$this->periodLabel($approver)} (deadline {$approver->signatory_deadline_at?->format(self::DATE_FORMAT)}). HR/Admin must assign a new Clearance Signatory.",
            markSecondaryFailed: $stage === 'secondary',
        );
    }

    private function requireHrAction(OffboardingRequestApprover $approver, string $stage, string $reason, bool $markSecondaryFailed): array
    {
        $previousStatus = $approver->escalation_status;
        $now = now();

        $approver->update([
            'escalation_status' => OffboardingRequestApprover::ESCALATION_HR_ACTION_REQUIRED,
            // Stops deadline monitoring until HR/Admin reassigns.
            'signatory_deadline_at' => null,
            'secondary_failed_at' => $markSecondaryFailed ? $now : $approver->secondary_failed_at,
            'escalated_at' => $approver->escalated_at ?? $now,
            'hr_alert_sent_at' => $now,
            'escalation_note' => $reason,
        ]);

        $event = $this->recordEvent($approver, ChecklistSignatoryAssignment::EVENT_HR_ACTION_REQUIRED, ChecklistSignatoryAssignment::TRIGGER_SCHEDULER, [
            'stage' => $stage,
            'previous_employee' => $approver->employee,
            'new_employee' => null,
            'assigned_at' => null,
            'deadline_at' => null,
            'previous_status' => $previousStatus,
            'new_status' => OffboardingRequestApprover::ESCALATION_HR_ACTION_REQUIRED,
            'reason' => $reason,
        ]);

        $this->audit($approver, 'clearance_signatory_hr_action_required', "\"{$this->checklistTitle($approver)}\" requires HR/Admin to assign a new Clearance Signatory. {$reason}", null, [
            'expired_employee_id' => $approver->employee_id,
            'history_id' => $event->id,
        ]);

        return ['outcome' => 'hr_action_required', 'event' => $event];
    }

    private function stopMonitoring(OffboardingRequestApprover $approver, string $note): array
    {
        $approver->update(['signatory_deadline_at' => null, 'escalation_note' => $note]);

        return ['outcome' => 'stopped', 'event' => null];
    }

    // ------------------------------------------------------------------
    // HR/Admin manual reassignment
    // ------------------------------------------------------------------

    /**
     * HR/Admin's "Assign New Clearance Signatory". `$expectedEmployeeId` /
     * `$expectedEscalationStatus` are what the modal showed when it was
     * opened — if the scheduler (or another admin) changed the checklist
     * since, nothing is saved and the caller gets a "changed since loaded"
     * error to refresh, rather than silently overwriting it.
     *
     * @return array{ok: bool, message: string, approver?: OffboardingRequestApprover}
     */
    public function reassign(OffboardingRequestApprover $approver, Employee $newSignatory, User $performedBy, ?string $reason, ?int $expectedEmployeeId, ?string $expectedEscalationStatus): array
    {
        $result = DB::transaction(function () use ($approver, $newSignatory, $performedBy, $reason, $expectedEmployeeId, $expectedEscalationStatus) {
            /** @var OffboardingRequestApprover|null $locked */
            $locked = OffboardingRequestApprover::whereKey($approver->id)->lockForUpdate()->first();

            if (! $locked) {
                return ['ok' => false, 'message' => 'This checklist no longer exists.'];
            }

            $locked->load('offboardingRequest', 'employee', 'checklistTemplate');

            if ($error = $this->reassignmentBlocker($locked)) {
                return ['ok' => false, 'message' => $error];
            }

            if ($locked->employee_id !== $expectedEmployeeId || $locked->escalation_status !== $expectedEscalationStatus) {
                return ['ok' => false, 'stale' => true, 'message' => 'This checklist\'s Clearance Signatory has changed since you opened this form. Please refresh and review it again.'];
            }

            if ($error = $this->eligibilityError($newSignatory, $locked->offboardingRequest)) {
                return ['ok' => false, 'message' => $error];
            }

            if ($newSignatory->id === $locked->employee_id && ! $locked->isAwaitingHrReassignment()) {
                return ['ok' => false, 'message' => "{$newSignatory->name} is already the active Clearance Signatory for this checklist."];
            }

            $previous = $locked->employee;
            $previousStatus = $locked->escalation_status;
            $now = now();
            $deadline = $locked->escalation_days
                ? $this->computeDeadline($now, $locked->escalation_days, $locked->escalation_day_type)
                : null;

            User::findOrCreateApprover($newSignatory);

            $locked->update([
                'employee_id' => $newSignatory->id,
                'status' => $locked->status === 'viewed' ? 'pending' : $locked->status,
                // A checklist that predates this feature gets its original
                // primary recorded now, so history stays complete.
                'primary_signatory_employee_id' => $locked->primary_signatory_employee_id ?? $previous?->id,
                'escalation_status' => OffboardingRequestApprover::ESCALATION_REASSIGNED_BY_HR,
                'signatory_assigned_at' => $now,
                'signatory_deadline_at' => $deadline,
                'hr_reassigned_at' => $now,
                'hr_reassigned_by_user_id' => $performedBy->id,
                'hr_reassignment_reason' => $reason,
                'escalation_note' => null,
                ...$this->clearDelegation($locked),
            ]);

            $event = $this->recordEvent($locked, ChecklistSignatoryAssignment::EVENT_HR_REASSIGNED, ChecklistSignatoryAssignment::TRIGGER_MANUAL, [
                'stage' => 'hr_assigned',
                'previous_employee' => $previous,
                'new_employee' => $newSignatory,
                'assigned_at' => $now,
                'deadline_at' => $deadline,
                'previous_status' => $previousStatus,
                'new_status' => OffboardingRequestApprover::ESCALATION_REASSIGNED_BY_HR,
                'reason' => $reason,
                'performed_by' => $performedBy,
            ]);

            $this->audit($locked, 'clearance_signatory_reassigned_by_hr', "\"{$this->checklistTitle($locked)}\" Clearance Signatory reassigned by {$performedBy->name}: {$previous?->name} → {$newSignatory->name}.".($reason ? " Reason: {$reason}" : ''), $performedBy, [
                'previous_employee_id' => $previous?->id,
                'new_employee_id' => $newSignatory->id,
                'deadline_at' => $deadline?->toDateTimeString(),
                'history_id' => $event->id,
            ]);

            return ['ok' => true, 'message' => "{$newSignatory->name} is now the Clearance Signatory for \"{$this->checklistTitle($locked)}\".", 'approver' => $locked, 'event' => $event];
        });

        if ($result['ok']) {
            $this->deliver($result['event']);
            unset($result['event']);
        }

        return $result;
    }

    /**
     * Why this checklist can't be reassigned at all right now (null = it can).
     */
    public function reassignmentBlocker(OffboardingRequestApprover $approver): ?string
    {
        if (! in_array($approver->offboardingRequest?->status, self::OPEN_REQUEST_STATUSES, true)) {
            return 'This offboarding request is no longer active.';
        }

        if ($approver->status === 'approved') {
            return 'This checklist is already completed.';
        }

        if ($approver->status === 'declined') {
            return 'This checklist was declined by its Clearance Signatory and cannot be reassigned here.';
        }

        if (! $approver->employee_id || $approver->checklistTemplate?->use_task_assignee_as_signatory) {
            return 'This checklist uses its Task Assignees as signatories and has no single Clearance Signatory.';
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Notifications
    // ------------------------------------------------------------------

    /**
     * Sends the email + in-app notifications for one history event and
     * records each recipient's outcome on it. Emails are queued
     * (`ChecklistSignatoryAnnouncementMail` is ShouldQueue), so "queued"
     * means handed to the queue — the queue worker delivers it and retries
     * on its own; an exception here (e.g. the queue/SMTP being unreachable
     * with a sync queue) marks that recipient "failed" for
     * `retryFailedNotifications()`.
     */
    private function deliver(ChecklistSignatoryAssignment $event, bool $onlyFailed = false): void
    {
        $approver = $event->approver()->with(['offboardingRequest.employee', 'checklistTemplate', 'employee', 'itemProgress'])->first();

        if (! $approver) {
            return;
        }

        $previousLog = collect($event->notifications ?? [])->keyBy('key');
        $log = $previousLog;

        foreach ($this->recipientsFor($event, $approver) as $recipient) {
            /** @var array{key: string, role: string, employee: ?Employee, user: ?User, email: ?string, template: string, message: string} $recipient */
            if ($onlyFailed && ($previousLog->get($recipient['key'])['status'] ?? null) !== 'failed') {
                continue;
            }

            $attempts = ($previousLog->get($recipient['key'])['attempts'] ?? 0) + 1;
            $entry = ['key' => $recipient['key'], 'role' => $recipient['role'], 'name' => $recipient['name'], 'email' => $recipient['email'], 'attempts' => $attempts];
            $errors = [];

            // In-app notification (only created once per recipient).
            if ($recipient['user'] && ! ($previousLog->get($recipient['key'])['in_app'] ?? false)) {
                try {
                    Notification::send($recipient['user'], new ClearanceSignatoryEscalationNotification($approver, $event->event, $recipient['message']));
                    $entry['in_app'] = true;
                } catch (\Throwable $e) {
                    $errors[] = 'in-app: '.$e->getMessage();
                }
            } else {
                $entry['in_app'] = $previousLog->get($recipient['key'])['in_app'] ?? false;
            }

            $emailTemplate = EmailTemplate::where('is_active', true)->where('template_name', $recipient['template'])->latest('updated_at')->first();

            if (! $emailTemplate) {
                $entry['email_status'] = 'skipped';
                $entry['email_error'] = "No active \"{$recipient['template']}\" email template.";
                Log::warning('Clearance signatory escalation email skipped — template missing/inactive.', ['template' => $recipient['template'], 'history_id' => $event->id]);
            } elseif (! $recipient['email'] || ! filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) {
                $entry['email_status'] = 'skipped';
                $entry['email_error'] = 'No valid email address on file.';
            } else {
                try {
                    [$subject, $body] = $emailTemplate->render(
                        approverName: $recipient['name'],
                        offboardeeName: $approver->offboardingRequest->employee->name,
                        employeeNumber: $approver->offboardingRequest->employee->employee_code,
                        checklistName: $this->checklistTitle($approver),
                        checklistStatus: $approver->clearanceStatusLabel(),
                        pendingItems: $approver->itemsStatusTableHtml(),
                        offboardingLinkUrl: $recipient['user'] ? $this->autoLoginLinkService->generate($recipient['user']) : null,
                        escalation: $this->escalationTokens($approver, $event),
                    );

                    Mail::to($recipient['email'])->send(new ChecklistSignatoryAnnouncementMail($subject, $body));
                    $entry['email_status'] = 'queued';
                } catch (\Throwable $e) {
                    $entry['email_status'] = 'failed';
                    $entry['email_error'] = $e->getMessage();
                    $errors[] = 'email: '.$e->getMessage();
                }
            }

            $entry['status'] = $errors ? 'failed' : 'ok';
            if ($errors) {
                $entry['error'] = implode('; ', $errors);
            }

            $log->put($recipient['key'], $entry);
        }

        $anyFailed = $log->contains(fn (array $entry) => $entry['status'] === 'failed');

        $event->update([
            'notifications' => $log->values()->all(),
            'notification_status' => $log->isEmpty() ? 'no_recipients' : ($anyFailed ? 'failed' : 'sent'),
            'notification_attempts' => $event->notification_attempts + 1,
        ]);
    }

    /**
     * Re-attempts only the recipients whose earlier delivery failed, for
     * events that haven't exhausted their attempts — never re-applies the
     * transition itself.
     */
    public function retryFailedNotifications(): int
    {
        $events = ChecklistSignatoryAssignment::where('notification_status', 'failed')
            ->where('notification_attempts', '<', self::MAX_NOTIFICATION_ATTEMPTS)
            ->get();

        foreach ($events as $event) {
            try {
                $this->deliver($event, onlyFailed: true);
            } catch (\Throwable $e) {
                Log::error('Retrying clearance signatory escalation notification failed.', ['history_id' => $event->id, 'exception' => $e->getMessage()]);
            }
        }

        return $events->count();
    }

    /**
     * @return array<int, array{key: string, role: string, name: string, employee: ?Employee, user: ?User, email: ?string, template: string, message: string}>
     */
    private function recipientsFor(ChecklistSignatoryAssignment $event, OffboardingRequestApprover $approver): array
    {
        $offboardee = $approver->offboardingRequest->employee;
        $title = $this->checklistTitle($approver);
        $about = "\"{$title}\" checklist for {$offboardee->name} ({$offboardee->employee_code})";
        $employeeRecipient = function (?int $employeeId, string $role, string $template, string $message) {
            $employee = $employeeId ? Employee::find($employeeId) : null;

            return $employee ? [
                'key' => "{$role}:employee:{$employee->id}",
                'role' => $role,
                'name' => $employee->name,
                'employee' => $employee,
                'user' => $employee->user,
                'email' => $employee->email,
                'template' => $template,
                'message' => $message,
            ] : null;
        };
        $hrRecipients = fn (string $template, string $message) => $this->hrAdminUsers()->map(fn (User $user) => [
            'key' => "hr:user:{$user->id}",
            'role' => 'hr_admin',
            'name' => $user->employee?->name ?? $user->name,
            'employee' => $user->employee,
            'user' => $user,
            'email' => $user->email ?: $user->employee?->email,
            'template' => $template,
            'message' => $message,
        ])->all();

        $recipients = match ($event->event) {
            ChecklistSignatoryAssignment::EVENT_ESCALATED => array_merge(
                [
                    $employeeRecipient($event->new_employee_id, 'new_signatory', self::TEMPLATE_ESCALATED_TO_YOU,
                        "You are now the Clearance Signatory for the {$about}: the previous signatory did not complete it in time. Complete by {$event->deadline_at?->format(self::DATE_FORMAT)}."),
                    $employeeRecipient($event->previous_employee_id, 'previous_signatory', self::TEMPLATE_TRANSFERRED_AWAY,
                        "Clearance Signatory responsibility for the {$about} has been transferred to {$event->new_employee_name} because it wasn't completed by your deadline."),
                ],
                $approver->escalation_notify_hr
                    ? $hrRecipients(self::TEMPLATE_HR_NOTICE, "The {$about} was escalated from {$event->previous_employee_name} to the secondary Clearance Signatory {$event->new_employee_name}.")
                    : [],
            ),
            ChecklistSignatoryAssignment::EVENT_HR_ACTION_REQUIRED => $hrRecipients(self::TEMPLATE_HR_ACTION_REQUIRED,
                "HR/Admin action required: assign a new Clearance Signatory for the {$about}. {$event->previous_employee_name} did not complete it in time."),
            ChecklistSignatoryAssignment::EVENT_HR_REASSIGNED => [
                $employeeRecipient($event->new_employee_id, 'new_signatory', self::TEMPLATE_HR_ASSIGNED,
                    "HR/Admin assigned you as the Clearance Signatory for the {$about}.".($event->deadline_at ? " Complete by {$event->deadline_at->format(self::DATE_FORMAT)}." : '')),
            ],
            default => [],
        };

        return array_values(array_filter($recipients));
    }

    /**
     * HR/Admin: every non-blocked user holding the reassignment permission
     * (the Admin role by default — see the permission's migration).
     *
     * @return Collection<int, User>
     */
    public function hrAdminUsers(): Collection
    {
        return User::permission(self::REASSIGN_PERMISSION)
            ->whereNull('blocked_at')
            ->with('employee')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    private function escalationTokens(OffboardingRequestApprover $approver, ChecklistSignatoryAssignment $event): array
    {
        $format = fn ($date) => $date?->format(self::DATE_FORMAT) ?? '—';
        $initialAssignedAt = $approver->signatoryAssignments()->where('event', ChecklistSignatoryAssignment::EVENT_INITIAL)->value('assigned_at');

        return [
            'primary_signatory_name' => $approver->primarySignatory?->name ?? '—',
            'secondary_signatory_name' => $approver->secondarySignatory?->name ?? '—',
            'previous_signatory_name' => $event->previous_employee_name ?? '—',
            'new_signatory_name' => $event->new_employee_name ?? '—',
            'original_assigned_at' => $format($initialAssignedAt ? Carbon::parse($initialAssignedAt) : $approver->assigned_at),
            'original_deadline' => $format($approver->primary_deadline_at),
            'secondary_assigned_at' => $format($approver->secondary_assigned_at),
            'secondary_deadline' => $format($approver->secondary_deadline_at),
            'new_assigned_at' => $format($event->assigned_at),
            'signatory_deadline' => $event->deadline_at ? $format($event->deadline_at) : 'No completion period configured',
            'escalated_at' => $format($approver->escalated_at ?? $event->occurred_at),
            'escalation_reason' => $event->reason ?? '—',
            'reassigned_by' => $event->performedBy?->employee?->name ?? $event->performedBy?->name ?? '—',
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * The checklist-wide delegate (if any) acted on behalf of the PREVIOUS
     * signatory — that stand-in ends with the transfer. Individually
     * assigned task(s) are unaffected.
     *
     * @return array<string, mixed>
     */
    private function clearDelegation(OffboardingRequestApprover $approver): array
    {
        if (! $approver->delegated_employee_id) {
            return [];
        }

        ChecklistDelegation::where('offboarding_request_approver_id', $approver->id)
            ->where('status', 'active')
            ->update(['status' => 'superseded', 'superseded_at' => now()]);

        return ['delegated_employee_id' => null, 'delegation_status' => null, 'delegated_at' => null, 'delegate_completed_at' => null];
    }

    private function recordEvent(OffboardingRequestApprover $approver, string $eventName, string $trigger, array $data): ChecklistSignatoryAssignment
    {
        $event = ChecklistSignatoryAssignment::create([
            'offboarding_request_approver_id' => $approver->id,
            'offboarding_request_id' => $approver->offboarding_request_id,
            'checklist_template_id' => $approver->checklist_template_id,
            'event' => $eventName,
            'stage' => $data['stage'] ?? null,
            'trigger' => $trigger,
            'previous_employee_id' => $data['previous_employee']?->id ?? null,
            'previous_employee_name' => $data['previous_employee']?->name ?? null,
            'new_employee_id' => $data['new_employee']?->id ?? null,
            'new_employee_name' => $data['new_employee']?->name ?? null,
            'assigned_at' => $data['assigned_at'] ?? null,
            'deadline_at' => $data['deadline_at'] ?? null,
            'occurred_at' => now(),
            'previous_status' => $data['previous_status'] ?? null,
            'new_status' => $data['new_status'] ?? null,
            'reason' => $data['reason'] ?? null,
            'performed_by_user_id' => ($data['performed_by'] ?? null)?->id,
            'notification_status' => array_key_exists('notification_status', $data) ? $data['notification_status'] : 'pending',
        ]);

        if ($eventName !== ChecklistSignatoryAssignment::EVENT_INITIAL) {
            $approver->offboardingRequest?->activities()->create([
                'user_id' => ($data['performed_by'] ?? null)?->id,
                'offboarding_request_approver_id' => $approver->id,
                'action' => 'clearance_signatory_'.$eventName,
                'status' => $approver->offboardingRequest->status,
                'comment' => trim((ChecklistSignatoryAssignment::EVENT_LABELS[$eventName] ?? $eventName).': '
                    .($data['previous_employee']?->name ?? '—').' → '.($data['new_employee']?->name ?? 'HR/Admin').'. '.($data['reason'] ?? '')),
            ]);
        }

        return $event;
    }

    private function audit(OffboardingRequestApprover $approver, string $action, string $description, ?User $user, array $newValues): void
    {
        ActivityLog::record($action, 'Checklist', $description, array_filter([
            'subject_type' => 'OffboardingRequestApprover',
            'subject_id' => $approver->id,
            'new_values' => $newValues + ['offboarding_request_id' => $approver->offboarding_request_id, 'checklist_template_id' => $approver->checklist_template_id],
            'user' => $user,
        ], fn ($value) => $value !== null));
    }

    private function checklistTitle(OffboardingRequestApprover $approver): string
    {
        return $approver->checklistTemplate?->title ?? 'Untitled Checklist';
    }

    private function periodLabel(OffboardingRequestApprover $approver): string
    {
        $days = (int) $approver->escalation_days;

        return $days.' '.($approver->escalation_day_type === 'working' ? 'working' : 'calendar').' day'.($days === 1 ? '' : 's');
    }
}
