<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class OffboardingRequest extends Model
{
    /** @use HasFactory<\Database\Factories\OffboardingRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'created_by',
        'immediate_head_id',
        'final_approver_employee_id',
        'reason',
        'separation_type_id',
        'separation_type_description',
        'notice_period_days',
        'notification_date',
        'notice_date',
        'last_working_day',
        'original_last_working_day',
        'approval_mode',
        'email_template_id',
        'approver_notification_template_id',
        'offboardee_notification_template_id',
        'general_signatory_notification_template_id',
        'status',
        'remarks',
        'completed_at',
        'final_pay_notified_at',
        'secondary_notified_at',
        'general_signatory_secondary_notified_at',
        'general_signatory_final_pay_notified_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'notice_date' => 'date',
            'notification_date' => 'date',
            'last_working_day' => 'date',
            'original_last_working_day' => 'date',
            'completed_at' => 'datetime',
            'final_pay_notified_at' => 'datetime',
            'secondary_notified_at' => 'datetime',
            'general_signatory_secondary_notified_at' => 'datetime',
            'general_signatory_final_pay_notified_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * 'upcoming' | 'today' | 'past' — where the saved (never recalculated)
     * `notification_date` sits relative to right now, purely for the
     * Calendar/Offboardee-status UI's visual indicator. Null when no
     * Notification Date was ever recorded (a request created before this
     * feature existed). Deliberately compares against the SAVED date, not
     * a live re-derivation from the current Notice Period default/config,
     * per this feature's own "never dynamically changes" requirement.
     */
    public function noticePeriodStatus(): ?string
    {
        if (! $this->notification_date) {
            return null;
        }

        return match (true) {
            $this->notification_date->isToday() => 'today',
            $this->notification_date->isFuture() => 'upcoming',
            default => 'past',
        };
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Non-authoritative reference back to the Separation Type Management
     * config row this request was created against — nulled automatically if
     * that row is later deleted (see the FK's `nullOnDelete()`). Never used
     * to DISPLAY this request's separation type/description/notice period;
     * those are frozen on this row itself (`reason`, `separation_type_description`,
     * `notice_period_days`) precisely so a later edit or delete here can't
     * alter a request that already exists. Useful only for
     * reporting/traceability (e.g. "how many requests used this type").
     */
    public function separationType(): BelongsTo
    {
        return $this->belongsTo(SeparationType::class);
    }

    /**
     * The HR/admin user who submitted this request — who the "Checklist
     * Approval Confirmed" completion email notifies once an approver
     * finishes their assigned checklist(s), rather than the approver
     * themselves. Null for any request created before this column existed.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The offboardee's immediate head, selected on the New Offboarding
     * Request form — treated as an additional authorized signatory on the
     * Clearance Form, independent of the checklist approval workflow.
     */
    public function immediateHead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'immediate_head_id');
    }

    /**
     * The Employee behind whichever `FinalApprover` config row was active
     * when this request was created (or last reset) — kept purely as a
     * HISTORICAL record of who that was at that moment. The Clearance
     * Form's "Approved for Payment by:" signatory is deliberately NOT
     * sourced from this relation: `ClearanceFormController::buildData()`
     * always resolves the CURRENTLY active `FinalApprover` fresh on every
     * render instead, so activating a different Final Approver is
     * reflected immediately on every request's Clearance Form — including
     * ones created long before that change — with no per-request update
     * needed. Null for a request created before this column existed, or if
     * no Final Approver was configured at creation/reset time.
     */
    public function finalApproverEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'final_approver_employee_id');
    }

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    /**
     * Which admin/HR user performed "Cancel Offboarding" — null for a
     * request that's never been cancelled. See
     * `OffboardingRequestController::cancel()`'s own docblock for why this
     * (and `cancelled_at`) live directly on the surviving request row
     * rather than a separate audit table.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Per-request overrides for the 3 fixed-name email templates that fire
     * automatically at request-creation time — see
     * `ChecklistApprovalNotifier::notifyDepartmentHeadsOfNewRequest()` /
     * `notifyOffboardee()` / `notifyGeneralSignatories()`, each of which
     * prefers its matching relation here over its own fixed-name lookup
     * when set. A frozen snapshot, captured once on the New Offboarding
     * Request form and never re-resolved afterward — deliberately no
     * `is_active` gating on these relations anywhere they're read, so a
     * later change to (or deactivation of) the pointed-at template never
     * affects an already-created request.
     */
    public function approverNotificationTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'approver_notification_template_id');
    }

    public function offboardeeNotificationTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'offboardee_notification_template_id');
    }

    public function generalSignatoryNotificationTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'general_signatory_notification_template_id');
    }

    public function checklistTemplates(): BelongsToMany
    {
        return $this->belongsToMany(ChecklistTemplate::class, 'checklist_assignments')->withTimestamps();
    }

    /**
     * Every active General Signatory snapshotted onto this request at
     * submission time (see `ChecklistApprovalNotifier::notifyGeneralSignatories()`).
     * Frozen the same way `checklistTemplates()` is — a General Signatory
     * added, edited, or deactivated later never changes who already appears
     * on an existing request's Clearance Form. Completely independent of
     * the checklist/approval workflow: a General Signatory here never has a
     * matching `OffboardingRequestApprover` row.
     */
    public function generalSignatories(): BelongsToMany
    {
        return $this->belongsToMany(GeneralSignatory::class, 'offboarding_request_general_signatories')->withTimestamps();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(OffboardingActivity::class)->orderBy('created_at');
    }

    public function approvers(): HasMany
    {
        return $this->hasMany(OffboardingRequestApprover::class)->orderBy('assigned_at');
    }

    /**
     * Every checklist on this request that still counts toward the bulk
     * "Extend Due" button/action (Offboardee Page) — not yet
     * approved/declined, AND actually carrying a due date (a template with
     * no `due_in_days` configured never gets one, so it's excluded rather
     * than counting toward — or blocking — anything). Shared by
     * `OffboardeeController::index()` (button visibility/modal data) and
     * `ApprovalController::extendAllDue()` (the gate check and the fresh
     * data returned after a successful extension) so both always agree on
     * exactly the same set — assumes `approvers.checklistTemplate` is
     * already loaded/loadable on `$this`.
     */
    public function extendDueApplicableApprovers(): Collection
    {
        return $this->approvers
            ->reject(fn (OffboardingRequestApprover $approver) => in_array($approver->status, ['approved', 'declined'], true))
            ->filter(fn (OffboardingRequestApprover $approver) => $approver->due_at !== null);
    }

    /**
     * Whether "Extend Due" has ever pushed this request's Last Working Day
     * forward from its original value. `original_last_working_day` is set
     * once at creation and never touched again (see
     * `OffboardingRequestController::store()` and the
     * `add_original_last_working_day_to_offboarding_requests_table`
     * migration's backfill for requests created before that column
     * existed) — `last_working_day` itself is the only value
     * `ApprovalController::extendAllDue()` ever updates, so comparing the
     * two here always reflects the LATEST extension, however many there
     * have been.
     */
    public function isLastWorkingDayExtended(): bool
    {
        return $this->original_last_working_day !== null
            && $this->last_working_day !== null
            && ! $this->original_last_working_day->equalTo($this->last_working_day);
    }

    /**
     * Per-request approval state for each attached General Signatory — the
     * General Signatory equivalent of `approvers()`. Backed by the very same
     * `offboarding_request_general_signatories` row `generalSignatories()`
     * above already reads (that relation stays a plain `belongsToMany`
     * snapshot for Clearance Form display; this one exposes the same row's
     * approval-state columns as its own model, for the Approvals page and
     * Submit action). Independent of `approvers()`/`OffboardingRequestApprover`
     * — a General Signatory's approval here never affects, and is never
     * affected by, the checklist workflow's own completion gate.
     */
    public function generalSignatoryApprovals(): HasMany
    {
        return $this->hasMany(OffboardingRequestGeneralSignatory::class)->orderBy('created_at');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(ChecklistFollowUp::class);
    }

    /**
     * This request's Final Approval process, if one has ever been
     * initiated (see `FinalApprovalController::send()`) — at most one ever
     * exists per request, enforced by a unique DB constraint.
     */
    public function finalApproval(): HasOne
    {
        return $this->hasOne(OffboardingRequestFinalApproval::class);
    }

    public function scheduledEmailSends(): HasMany
    {
        return $this->hasMany(EmailTemplateScheduledSend::class);
    }

    /**
     * True once at least one assigned approver has viewed, approved, or
     * declined their checklist, the checklist has been delegated in any
     * way, or a General Signatory has acted (viewed/approved) — i.e.
     * someone has actually started working on this request, even though
     * the real `status` column is still "pending" (it only flips to
     * "in_progress" once every regular checklist is approved). Requires
     * `approvers` and `generalSignatoryApprovals` to be loaded/loadable on
     * this instance.
     *
     * A General Signatory is snapshotted onto the request independently of
     * the checklist approval workflow (see `generalSignatories()`), so
     * their own activity is never reflected by the `approvers` relation at
     * all — checking only `approvers` here let a request sit at "Pending"
     * even after its General Signatory had already approved, since nothing
     * about that action ever touches an `OffboardingRequestApprover` row.
     */
    public function hasApproverActivity(): bool
    {
        $hasChecklistActivity = $this->approvers->contains(
            fn (OffboardingRequestApprover $approver) => $approver->status !== 'pending' || $approver->delegation_status !== null
        );

        if ($hasChecklistActivity) {
            return true;
        }

        return $this->generalSignatoryApprovals->contains(
            fn (OffboardingRequestGeneralSignatory $approval) => $approval->status !== 'pending' || $approval->first_viewed_at !== null
        );
    }

    /**
     * True once at least one checklist has been fully approved, one General
     * Signatory has cleared, OR one individual checklist item/task has been
     * checked off — deliberately NARROWER than `hasApproverActivity()`
     * above, which also counts merely viewing/holding/delegating as
     * "activity" for the Pending → In Progress status rollup. This is the
     * gate for the Offboardee page's "Reset Offboarding" button instead: it
     * must stay hidden for a request nobody has acted on yet (viewing a
     * checklist alone doesn't justify offering a destructive reset), but
     * appear the moment there's real progress to actually lose — even a
     * single checked item on an otherwise still-pending checklist counts,
     * since that work would otherwise be silently wiped with no warning the
     * button ever existed. Requires `approvers.itemProgress` and
     * `generalSignatoryApprovals` to be loaded/loadable on this instance.
     */
    public function hasApprovedOrCompletedProgress(): bool
    {
        $hasApprovedChecklistOrTask = $this->approvers->contains(
            fn (OffboardingRequestApprover $approver) => $approver->status === 'approved'
                || $approver->itemProgress->contains(fn (ChecklistItemProgress $progress) => (bool) $progress->is_checked)
        );

        if ($hasApprovedChecklistOrTask) {
            return true;
        }

        return $this->generalSignatoryApprovals->contains(
            fn (OffboardingRequestGeneralSignatory $approval) => $approval->status === 'approved'
        );
    }

    /**
     * True while the request is still active (pending/in_progress) and at
     * least one of its approvers is past its computed due date without
     * having been resolved. Requires `approvers` to be loaded/loadable.
     */
    public function isOverdue(): bool
    {
        return in_array($this->status, ['pending', 'in_progress'], true)
            && $this->approvers->contains(fn (OffboardingRequestApprover $a) => $a->isOverdue());
    }

    /**
     * The status to actually show the user: identical to the real `status`
     * column except a still-"pending"/"in_progress" request displays as
     * "overdue" once any approver has missed its due date, or "in_progress"
     * once a still-"pending" request already has approver activity. This is
     * display-only — the real `status` column must stay untouched, since
     * other approvers' own visibility into the request (`scopeVisibleTo`,
     * the Approvals page query) depends on it staying "pending" until every
     * regular checklist is actually approved.
     */
    public function displayStatus(): string
    {
        if (! in_array($this->status, ['pending', 'in_progress'], true)) {
            return $this->status;
        }

        if ($this->isOverdue()) {
            return 'overdue';
        }

        if ($this->status !== 'pending') {
            return $this->status;
        }

        return $this->hasApproverActivity() ? 'in_progress' : 'pending';
    }

    /**
     * Query-level equivalent of `displayStatus() === 'pending'`, for
     * dashboard counts where loading every request's approvers into memory
     * would be wasteful.
     */
    public function scopeDisplayPending(Builder $query): Builder
    {
        return $query->where('status', 'pending')
            ->whereDoesntHave('approvers', fn (Builder $q) => static::approverActivityConstraint($q))
            ->whereDoesntHave('generalSignatoryApprovals', fn (Builder $q) => static::generalSignatoryActivityConstraint($q))
            ->whereDoesntHave('approvers', fn (Builder $q) => static::overdueConstraint($q));
    }

    /**
     * Query-level equivalent of `displayStatus() === 'in_progress'`.
     */
    public function scopeDisplayInProgress(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('status', 'in_progress')
                ->orWhere(function (Builder $q2) {
                    $q2->where('status', 'pending')
                        ->where(function (Builder $q3) {
                            $q3->whereHas('approvers', fn (Builder $q4) => static::approverActivityConstraint($q4))
                                ->orWhereHas('generalSignatoryApprovals', fn (Builder $q4) => static::generalSignatoryActivityConstraint($q4));
                        });
                });
        })->whereDoesntHave('approvers', fn (Builder $q) => static::overdueConstraint($q));
    }

    /**
     * Query-level equivalent of `displayStatus() === 'overdue'`.
     */
    public function scopeDisplayOverdue(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'in_progress'])
            ->whereHas('approvers', fn (Builder $q) => static::overdueConstraint($q));
    }

    private static function approverActivityConstraint(Builder $query): void
    {
        $query->where('status', '!=', 'pending')->orWhereNotNull('delegation_status');
    }

    /**
     * Query-level equivalent of `hasApproverActivity()`'s General Signatory
     * check above.
     */
    private static function generalSignatoryActivityConstraint(Builder $query): void
    {
        $query->where('status', '!=', 'pending')->orWhereNotNull('first_viewed_at');
    }

    private static function overdueConstraint(Builder $query): void
    {
        $query->whereNotIn('status', ['approved', 'declined'])
            ->whereNotNull('due_at')
            ->where('due_at', '<', now());
    }

    /**
     * Restricts the query to requests the given user is allowed to see/act
     * on: admins see everything, approvers only see requests where they are
     * the department head of an attached checklist template, or the
     * signatory of one of that template's items. This is the single source
     * of truth for approver visibility — reused for both the Approvals
     * listing and the approve/decline authorization check, so they can
     * never drift apart.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        $employee = $user->employee;

        if (! $employee) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($employee) {
            $q->whereHas('checklistTemplates', fn (Builder $t) => $t->where('department_head_id', $employee->id))
                ->orWhereHas('checklistTemplates.items', fn (Builder $i) => $i->where('signatory_id', $employee->id));
        });
    }

    /**
     * Build the offboarding lifecycle as a sequence of timeline steps, from
     * the request being submitted through to completion/cancellation. The
     * approve/decline step(s) come from real, stored `OffboardingActivity`
     * records (who acted, when, and any decline reason) rather than being
     * inferred purely from the current status.
     */
    public function timeline(): array
    {
        $today = Carbon::today();

        $steps = [
            [
                'label' => 'Request Submitted',
                'date' => $this->created_at->format('M d, Y'),
                'done' => true,
            ],
            [
                'label' => 'Resignation / Notice Date',
                'date' => $this->notice_date?->format('M d, Y'),
                'done' => (bool) $this->notice_date,
            ],
        ];

        $declined = false;
        $completedActivity = null;

        foreach ($this->activities as $activity) {
            if ($activity->action === 'completed') {
                // Rendered by the trailing block below instead, using its
                // richer comment — avoids showing "Completed" twice.
                $completedActivity = $activity;

                continue;
            }

            if (in_array($activity->action, ['final_approval_sent', 'final_approval_viewed', 'final_approval_approved'], true)) {
                // Rendered by the dedicated Final Approval step below
                // instead, sourced directly from `$this->finalApproval` —
                // avoids three loose generic-activity lines (which would
                // also render in the wrong chronological SLOT here, since
                // this loop's steps are always positioned before the
                // hardcoded "Completed" step further down, even though
                // Final Approval can only ever happen AFTER completion).
                continue;
            }

            $isDeclined = $activity->action === 'declined';
            $declined = $declined || $isDeclined;

            $steps[] = [
                'label' => $activity->label(),
                'date' => $activity->created_at->format('M d, Y g:i A'),
                'done' => true,
                'comment' => $activity->comment,
                'cancelled' => $isDeclined,
            ];
        }

        $steps[] = [
            'label' => 'Offboarding In Progress',
            'date' => null,
            'done' => in_array($this->status, ['in_progress', 'completed']),
        ];

        $steps[] = [
            'label' => 'Last Working Day',
            'date' => $this->last_working_day->format('M d, Y'),
            'done' => $today->greaterThanOrEqualTo($this->last_working_day),
        ];

        if ($this->status === 'cancelled' && ! $declined) {
            // Fallback for requests cancelled before activity logging existed.
            $steps[] = [
                'label' => 'Cancelled',
                'date' => $this->updated_at->format('M d, Y'),
                'done' => true,
                'cancelled' => true,
            ];
        } elseif ($this->status !== 'cancelled') {
            $steps[] = [
                'label' => 'Completed',
                'date' => $completedActivity?->created_at->format('M d, Y') ?? $this->completed_at?->format('M d, Y'),
                'done' => $this->status === 'completed',
            ];
        }

        // Final Approval — strictly a post-completion event (it can only
        // ever be initiated once `status === 'completed'`, see
        // `FinalApprovalController::send()`), so it's always appended last
        // rather than sourced from the generic activity loop above, which
        // would otherwise render it in the wrong chronological slot ahead
        // of "Completed". Sourced directly from `$this->finalApproval`
        // (never the raw `final_approval_*` activity rows, which the loop
        // above deliberately skips) so it always reflects the current,
        // authoritative approval state — including the Final Signatory's
        // employee code, the fact that approval only ever happens via the
        // emailed link today, and any remark they left.
        if ($finalApproval = $this->finalApproval) {
            $finalApproval->loadMissing('employee');
            $isApproved = $finalApproval->status === 'approved';
            $approverEmployee = $finalApproval->employee;
            $approverLabel = $approverEmployee
                ? $approverEmployee->name . ' (' . $approverEmployee->employee_code . ')'
                : 'the Final Signatory';

            $steps[] = [
                'label' => $isApproved
                    ? 'Final Approval — Approved by ' . $approverLabel
                    : 'Final Approval Requested — ' . $approverLabel,
                'date' => $isApproved
                    ? $finalApproval->approved_at?->format('M d, Y g:i A')
                    : $finalApproval->initiated_at?->format('M d, Y g:i A'),
                'done' => $isApproved,
                'comment' => $isApproved
                    ? trim('Approval Method: Via Email.' . ($finalApproval->remarks ? ' Remarks: ' . $finalApproval->remarks : ''))
                    : 'Awaiting approval from the Final Signatory.',
            ];
        }

        return $steps;
    }

    /**
     * Admin-only, richer version of the timeline: one detailed step per
     * assigned department-head approver (status, assigned/viewed/approved-or
     * -declined timestamps, decline reason, reminder state), sourced from
     * `OffboardingRequestApprover` rows rather than `activities`, since a
     * "pending, never viewed" assignment has no activity row to derive from.
     *
     * Ordered structurally by workflow stage rather than by timestamp —
     * regular checklists are always assigned and resolved before the Final
     * Pay Checklist stage even begins, so a sort-by-timestamp merge would be
     * fragile (two approvals seconds apart can tie at second-level
     * precision); the stage order is a known invariant instead.
     */
    public function approverActivityTimeline(): array
    {
        $this->loadMissing('generalSignatoryApprovals.generalSignatory.clearanceSignatory');

        $remindersByAssignment = $this->activities->where('action', 'reminder_sent')->groupBy('offboarding_request_approver_id');
        // Every "Extend Due" action taken on each checklist — surfaced as
        // its own Timeline entry per extension below (never merged/
        // overwritten, so a checklist extended several times keeps one
        // separate entry per extension, oldest first thanks to
        // `$this->activities` already being loaded in `created_at` order).
        $dueDateExtensionEventsByAssignment = $this->activities->where('action', 'due_date_extended')->groupBy('offboarding_request_approver_id');
        $delegationEventsByAssignment = $this->activities
            ->whereIn('action', ['checklist_assigned', 'checklist_delegate_completed', 'checklist_item_cleared_by_other', 'checklist_item_held', 'checklist_ready_for_approval'])
            ->groupBy('offboarding_request_approver_id');

        // The General Signatory equivalent of the two groupings above —
        // keyed by `offboarding_request_general_signatory_id` (the FK
        // `GeneralSignatoryApprovalController::remind()`/`ApprovalController::index()`'s
        // General Signatory "first viewed" logic both stamp on their own
        // activity rows) rather than `offboarding_request_approver_id`,
        // since a General Signatory's notification/view events are never
        // tied to an `OffboardingRequestApprover` row at all.
        $generalSignatoryEventsByAssignment = $this->activities
            ->whereIn('action', ['general_signatory_reminder_sent', 'general_signatory_viewed'])
            ->groupBy('offboarding_request_general_signatory_id');

        $buildRichStep = function (OffboardingRequestApprover $assignment) use ($remindersByAssignment, $dueDateExtensionEventsByAssignment, $delegationEventsByAssignment): array {
            $assignment->loadMissing(
                'checklistTemplate.items',
                'itemProgress.checkedBy.employee',
                'itemProgress.heldBy.employee',
                'firstViewedBy',
            );
            $progressByItem = $assignment->itemProgress->keyBy('checklist_item_id');

            $checklistItems = $assignment->checklistTemplate?->items
                ->map(function (ChecklistItem $item) use ($progressByItem, $assignment) {
                    $progress = $progressByItem->get($item->id);

                    $status = match (true) {
                        (bool) ($progress?->is_checked) => 'completed',
                        $progress?->status === 'hold' => 'on_hold',
                        $assignment->status === 'declined' => 'declined',
                        filled($progress?->remark) => 'in_progress',
                        default => 'pending',
                    };

                    // The actual moment the assignee acted, sourced straight
                    // from `checked_at`/`held_at` — never displayed for
                    // 'pending'/'in_progress'/'declined', and never
                    // recomputed from anything else, so it always matches
                    // whichever action ('completed' vs 'on_hold') the
                    // status above resolved to. If a held item is later
                    // completed, `is_checked` wins the `match` above and
                    // this naturally switches to `checked_at`.
                    $timestamp = match ($status) {
                        'completed' => $progress?->checked_at,
                        'on_hold' => $progress?->held_at,
                        default => null,
                    };

                    // Who actually performed the check/hold — read straight
                    // off `ChecklistItemProgress.checked_by_user_id`/
                    // `held_by_user_id` (the REAL action recorded in the
                    // system), never guessed from the item's configured/
                    // effective signatory. This matters because a
                    // whole-checklist delegate (see
                    // `ChecklistDelegationController::assign()`) never gets
                    // its own `ChecklistItemAssignment` row, so
                    // `effectiveSignatoryFor()` would incorrectly resolve
                    // back to the Clearance Signatory even when the
                    // delegate is who genuinely did the work.
                    $actorEmployee = match ($status) {
                        'completed' => $progress?->checkedBy?->employee,
                        'on_hold' => $progress?->heldBy?->employee,
                        default => null,
                    };

                    // No distinct task assignee ever did this item — the
                    // Clearance Signatory themselves is the one who
                    // actually checked/held it (or the actor couldn't be
                    // resolved to an Employee at all, e.g. an admin account
                    // with no Employee Master record) — labeled as the
                    // Clearance Signatory rather than implying a "task
                    // assignee" that never existed for this item.
                    $actorPrefix = match (true) {
                        $status !== 'completed' && $status !== 'on_hold' => null,
                        $actorEmployee && $actorEmployee->id !== $assignment->employee_id => $status === 'completed' ? 'Checked by' : 'Hold by',
                        default => 'Clearance Signatory',
                    };

                    $actorName = match (true) {
                        $actorPrefix === null => null,
                        $actorPrefix === 'Clearance Signatory' => $actorEmployee?->name ?? $assignment->employee?->name,
                        default => $actorEmployee?->name,
                    };

                    return [
                        'title' => $item->title,
                        'status' => $status,
                        'timestamp' => $timestamp?->format('F d, Y – g:i A'),
                        'actorPrefix' => $actorPrefix,
                        'actorName' => $actorName,
                        // Whatever the task assignee or Clearance Signatory
                        // themselves typed alongside checking/holding the
                        // item (or while it's merely 'in_progress' — a
                        // remark can exist before the item is actually
                        // checked/held, see the `$status` match above).
                        // Never displayed for a genuinely untouched
                        // 'pending' item, since that state is only reached
                        // when no remark exists in the first place.
                        'remark' => $progress?->remark,
                        // Green vs red for THIS item's own "Completed" badge
                        // — items have no due date of their own, so this
                        // compares the item's actual `checked_at` against
                        // its CHECKLIST's shared `due_at` (same due date
                        // every item on this assignment shares). Only
                        // meaningful once actually completed; never true for
                        // any other status or when the checklist has no due
                        // date at all.
                        'completedLate' => $status === 'completed'
                            && $assignment->due_at !== null
                            && $timestamp !== null
                            && $timestamp->greaterThanOrEqualTo($assignment->due_at),
                    ];
                })
                ->values()
                ->all() ?? [];

            $steps = [[
                'rich' => true,
                'department' => $assignment->checklistTemplate?->title ?? $assignment->department(),
                'approverName' => $assignment->employee?->name,
                'status' => $assignment->status,
                'assignedAt' => $assignment->assigned_at?->format('M d, Y g:i A'),
                'firstViewedAt' => $assignment->first_viewed_at?->format('M d, Y g:i A'),
                // Whoever's view actually set `first_viewed_at` — on a "Use
                // Task Assignee as Clearance Signatory" checklist this can be
                // the Task Assignee, their Immediate/Group/Department Head,
                // or anyone else `visibleTo()` already authorized (see
                // `ApprovalController::index()`'s view-tracking block); on a
                // normal checklist it's always the Clearance Signatory
                // themselves. Null for any assignment recorded before this
                // column existed.
                'firstViewedByName' => $assignment->firstViewedBy?->name,
                'approvedAt' => $assignment->approved_at?->format('M d, Y g:i A'),
                'declinedAt' => $assignment->declined_at?->format('M d, Y g:i A'),
                'declineReason' => $assignment->decline_reason,
                'reminderSentAt' => $assignment->reminder_sent_at?->format('M d, Y g:i A'),
                'canRemind' => ! in_array($assignment->status, ['approved', 'declined'], true),
                'remindUrl' => route('approvals.remind', $assignment->id),
                // Discriminates a checklist approver's rich step from a
                // General Signatory's (see `buildGeneralSignatoryRichStep()`
                // below) — both share this exact shape, but the "Notify
                // Approver" picker needs to know which default email
                // template to pre-select.
                'isGeneralSignatory' => false,
                // Populated only once actually approved (never for a merely
                // pending/viewed/declined row) — the approving employee's
                // own uploaded e-signature, now guaranteed to exist by the
                // time any row reaches 'approved' (see
                // `User::hasUsableSignature()`), recorded here alongside the
                // existing `approvedAt` timestamp so the admin-facing
                // timeline shows who signed off and with what signature, not
                // just when.
                'approverSignatureUrl' => $assignment->status === 'approved'
                    ? $assignment->employee?->user?->signatureUrl()
                    : null,
                'done' => in_array($assignment->status, ['approved', 'declined']),
                'cancelled' => $assignment->status === 'declined',
                'delegatedTo' => $assignment->delegatedEmployee?->name,
                'delegatedToCode' => $assignment->delegatedEmployee?->employee_code,
                'delegationStatus' => $assignment->delegation_status,
                'delegateCompletedAt' => $assignment->delegate_completed_at?->format('M d, Y g:i A'),
                'dueAt' => $assignment->due_at?->format('M d, Y g:i A'),
                'isOverdue' => $assignment->isOverdue(),
                // Extend Due history block (Offboarding Status tab) — the
                // checklist's very first due date (see
                // `OffboardingRequestApprover::originalDueDate()`'s own
                // docblock for why this differs from both `dueAt` above and
                // any single extension's own `previousDueDate` below), plus
                // the complete, permanent, chronological (oldest-first,
                // matching `dueDateExtensions()`'s own ordering) list of
                // every extension ever made — empty when the checklist has
                // never been extended, so the view can tell "never
                // extended" apart from "extended, currently 0 rows shown"
                // by simply checking this array's length.
                'originalDueDate' => $assignment->originalDueDate()?->format('M d, Y'),
                'dueDateExtensions' => $assignment->dueDateExtensions->map(fn (ChecklistDueDateExtension $extension) => [
                    'previousDueDate' => $extension->previous_due_date->format('M d, Y'),
                    'newDueDate' => $extension->new_due_date->format('M d, Y'),
                    'additionalDays' => $extension->additional_extension_days,
                    'extendedBy' => $extension->extendedBy?->name ?? 'Unknown',
                    'extendedAt' => $extension->created_at->format('M d, Y g:i A'),
                ])->values(),
                // Persisted, refresh-proof basis for the completed-status
                // color (green if approved on/before `due_at`, red if
                // approved at/after it) — always derived from the row's own
                // stored `due_at`/`approved_at`, never from "now", so it
                // never changes once the row is approved. See
                // `OffboardingRequestApprover::wasCompletedLate()`.
                'wasCompletedLate' => $assignment->wasCompletedLate(),
                // The whole-checklist "reason for the delay" remark left via
                // the due-date confirmation dialog at Submit time — distinct
                // from each checklist item's own `remark` above (which
                // explains one specific item, not the checklist as a
                // whole).
                'approvalRemarks' => $assignment->approval_remarks,
                'usesPerItemApprovers' => $assignment->usesPerItemApprovers(),
                'checklistItems' => $checklistItems,
            ]];

            foreach ($remindersByAssignment->get($assignment->id, collect()) as $reminder) {
                $steps[] = [
                    'label' => $reminder->label(),
                    'date' => $reminder->created_at->format('M d, Y g:i A'),
                    'done' => true,
                    'comment' => $reminder->comment,
                ];
            }

            // One permanent Timeline entry per "Extend Due" action — never
            // merged or overwritten, so a checklist extended several times
            // shows one line per extension, in the order they actually
            // happened (see `$dueDateExtensionEventsByAssignment`'s own
            // comment above for why that ordering is guaranteed).
            foreach ($dueDateExtensionEventsByAssignment->get($assignment->id, collect()) as $extensionEvent) {
                $steps[] = [
                    'label' => $extensionEvent->label(),
                    'date' => $extensionEvent->created_at->format('M d, Y g:i A'),
                    'done' => true,
                    'comment' => $extensionEvent->comment,
                ];
            }

            foreach ($delegationEventsByAssignment->get($assignment->id, collect()) as $event) {
                $steps[] = [
                    'label' => $event->label(),
                    'date' => $event->created_at->format('M d, Y g:i A'),
                    'done' => $event->action !== 'checklist_item_held',
                    'hold' => $event->action === 'checklist_item_held',
                    'comment' => $event->comment,
                ];
            }

            return $steps;
        };

        $steps = [
            [
                'label' => 'Request Submitted',
                'date' => $this->created_at->format('M d, Y g:i A'),
                'done' => true,
            ],
        ];

        foreach ($this->approvers->filter(fn ($a) => ! $a->checklistTemplate?->is_final_pay_checklist) as $assignment) {
            array_push($steps, ...$buildRichStep($assignment));
        }

        $milestones = $this->activities->keyBy('action');

        foreach (['all_checklists_approved', 'final_pay_notified'] as $milestoneAction) {
            if ($milestone = $milestones->get($milestoneAction)) {
                $steps[] = [
                    'label' => $milestone->label(),
                    'date' => $milestone->created_at->format('M d, Y g:i A'),
                    'done' => true,
                    'comment' => $milestone->comment,
                ];
            }
        }

        foreach ($this->approvers->filter(fn ($a) => $a->checklistTemplate?->is_final_pay_checklist) as $assignment) {
            array_push($steps, ...$buildRichStep($assignment));
        }

        // General Signatories are Checklist Clearance Signatories too — each
        // one attached to this request gets its own rich card here, the
        // same shape a department approver's does, sourced from the very
        // same `OffboardingRequestGeneralSignatory` row the Approvals page's
        // Submit action and the Clearance Form both read/write.
        //
        // Deliberately NOT filtered on `GeneralSignatory.is_active` — that
        // flag only decided who got attached to this request at CREATION
        // time (a frozen snapshot, see `ChecklistApprovalNotifier::notifyGeneralSignatories()`
        // and `ClearanceFormController::buildData()`'s matching comment);
        // re-checking it live here would let deactivating a General
        // Signatory make them retroactively vanish from an
        // already-created request's Offboarding Status/Timeline, exactly
        // the inconsistency the Clearance Form is deliberately guarded
        // against too.
        foreach ($this->generalSignatoryApprovals as $generalSignatoryApproval) {
            $steps[] = $this->buildGeneralSignatoryRichStep($generalSignatoryApproval);

            // Notification resends and the first-view timestamp both
            // already show as fields directly on the rich card above
            // (`reminderSentAt`-equivalent isn't exposed there today, but
            // `firstViewedAt` is) — these trailing plain sub-steps are what
            // actually make each individual event show up in the
            // Offboarding Status/Timeline's chronological Timeline tab too,
            // exactly like a checklist approver's reminders/delegation
            // events do via `$remindersByAssignment`/`$delegationEventsByAssignment`
            // above.
            foreach ($generalSignatoryEventsByAssignment->get($generalSignatoryApproval->id, collect()) as $event) {
                $steps[] = [
                    'label' => $event->label(),
                    'date' => $event->created_at->format('M d, Y g:i A'),
                    'done' => true,
                    'comment' => $event->comment,
                ];
            }
        }

        $completedActivity = $milestones->get('completed');

        $steps[] = $completedActivity
            ? [
                'label' => $completedActivity->label(),
                'date' => $completedActivity->created_at->format('M d, Y g:i A'),
                'done' => true,
                'comment' => $completedActivity->comment,
            ]
            : [
                'label' => 'Offboarding Completed',
                'date' => $this->completed_at?->format('M d, Y g:i A'),
                'done' => $this->status === 'completed',
            ];

        // Final Approval — the true last stage, only ever reachable once
        // every step above is already done (`FinalApprovalController::send()`
        // requires `status === 'completed'`), so it's always the very last
        // card here regardless of the exact activity-row ordering above.
        if ($finalApproval = $this->finalApproval) {
            $finalApproval->loadMissing('employee.user');
            $steps[] = $this->buildFinalApprovalRichStep($finalApproval);
        }

        return $steps;
    }

    /**
     * A General Signatory's rich step for `approverActivityTimeline()` —
     * the same shape `buildRichStep()` produces for a department approver
     * (`department`/`approverName`/`status`/timestamps), minus the fields
     * that only apply to checklist-item-based approval (`checklistItems`
     * stays empty, `usesPerItemApprovers` false, no due date/overdue
     * concept, no delegation — General Signatories have none of those).
     * `status` is only ever 'pending' or 'approved' (see
     * `OffboardingRequestGeneralSignatory`), so this never needs the
     * 'declined'/'viewed' branches the department version does.
     * `canRemind`/`remindUrl` DO apply, unlike delegation — see
     * `GeneralSignatoryApprovalController::remind()`, the "Notify Approver"
     * action for resending this General Signatory's own notification
     * email.
     *
     * `remarks` is the General Signatory's own optional comment left on the
     * Approvals page at Submit time (`GeneralSignatoryApprovalController::approve()`),
     * stored on THIS row's own `remarks` column — same field name/shape as
     * `buildFinalApprovalRichStep()`'s `remarks` below, so the shared
     * Offboarding Status/Timeline card markup (`step.remarks`) renders it
     * identically with no template changes needed. Never null-coalesced
     * from `approval_remarks`/`OffboardingRequestApprover` or any other
     * approver's own remarks column — this is exclusively this General
     * Signatory's own.
     */
    private function buildGeneralSignatoryRichStep(OffboardingRequestGeneralSignatory $generalSignatoryApproval): array
    {
        $clearanceSignatory = $generalSignatoryApproval->generalSignatory->clearanceSignatory;

        return [
            'rich' => true,
            'department' => $clearanceSignatory?->department ?? 'General Signatory',
            'approverName' => $clearanceSignatory?->name,
            'status' => $generalSignatoryApproval->status,
            'assignedAt' => $generalSignatoryApproval->created_at?->format('M d, Y g:i A'),
            'firstViewedAt' => $generalSignatoryApproval->first_viewed_at?->format('M d, Y g:i A'),
            'approvedAt' => $generalSignatoryApproval->approved_at?->format('M d, Y g:i A'),
            // Same convention as `buildRichStep()` above — only present once
            // actually approved, now guaranteed to exist by then.
            'approverSignatureUrl' => $generalSignatoryApproval->status === 'approved'
                ? $clearanceSignatory?->user?->signatureUrl()
                : null,
            'remarks' => $generalSignatoryApproval->remarks,
            'declinedAt' => null,
            'declineReason' => null,
            'reminderSentAt' => null,
            'canRemind' => $generalSignatoryApproval->status !== 'approved',
            'remindUrl' => route('general-signatory-approvals.remind', $generalSignatoryApproval->id),
            'isGeneralSignatory' => true,
            'done' => $generalSignatoryApproval->status === 'approved',
            'cancelled' => false,
            'delegatedTo' => null,
            'delegatedToCode' => null,
            'delegationStatus' => null,
            'delegateCompletedAt' => null,
            'dueAt' => null,
            'isOverdue' => false,
            'usesPerItemApprovers' => false,
            'checklistItems' => [],
        ];
    }

    /**
     * The Final Approval rich step for `approverActivityTimeline()` —
     * reuses the exact same card shape `buildGeneralSignatoryRichStep()`
     * produces above (so the shared Offboarding Status/Timeline markup
     * renders it identically, no template changes needed), extended with
     * two fields no other rich step needs: `approvalMethod` (Final
     * Approval only ever happens via the emailed one-click link today —
     * there is no in-app equivalent action) and `remarks` (the Final
     * Signatory's own optional comment, left on the confirmation dialog).
     * Resending from this card is deliberately NOT wired up — Final
     * Approval already has its own dedicated "Final Approval" button on
     * the Offboardee page, which resends against this exact same row (see
     * `FinalApprovalController::send()`'s `firstOrCreate` — never a
     * duplicate) — so `canRemind` stays false here to avoid a second,
     * differently-behaved entry point to the same action.
     */
    private function buildFinalApprovalRichStep(OffboardingRequestFinalApproval $finalApproval): array
    {
        $approverEmployee = $finalApproval->employee;
        $isApproved = $finalApproval->status === 'approved';

        return [
            'rich' => true,
            'department' => 'Final Approval',
            'approverName' => $approverEmployee
                ? $approverEmployee->name . ' (' . $approverEmployee->employee_code . ')'
                : null,
            'status' => $finalApproval->status,
            'assignedAt' => $finalApproval->initiated_at?->format('M d, Y g:i A'),
            'firstViewedAt' => $finalApproval->first_viewed_at?->format('M d, Y g:i A'),
            'approvedAt' => $finalApproval->approved_at?->format('M d, Y g:i A'),
            'approverSignatureUrl' => $isApproved ? $approverEmployee?->user?->signatureUrl() : null,
            'approvalMethod' => $isApproved ? 'Via Email' : null,
            'remarks' => $finalApproval->remarks,
            'declinedAt' => null,
            'declineReason' => null,
            'reminderSentAt' => null,
            'canRemind' => false,
            'remindUrl' => null,
            'isGeneralSignatory' => false,
            'done' => $isApproved,
            'cancelled' => false,
            'delegatedTo' => null,
            'delegatedToCode' => null,
            'delegationStatus' => null,
            'delegateCompletedAt' => null,
            'dueAt' => null,
            'isOverdue' => false,
            'usesPerItemApprovers' => false,
            'checklistItems' => [],
        ];
    }
}
