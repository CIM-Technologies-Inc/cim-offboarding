<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class OffboardingRequest extends Model
{
    /** @use HasFactory<\Database\Factories\OffboardingRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'created_by',
        'immediate_head_id',
        'reason',
        'resignation_type',
        'notice_date',
        'last_working_day',
        'approval_mode',
        'email_template_id',
        'approver_notification_template_id',
        'offboardee_notification_template_id',
        'general_signatory_notification_template_id',
        'status',
        'remarks',
        'completed_at',
        'final_pay_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'notice_date' => 'date',
            'last_working_day' => 'date',
            'completed_at' => 'datetime',
            'final_pay_notified_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
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

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
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

    public function scheduledEmailSends(): HasMany
    {
        return $this->hasMany(EmailTemplateScheduledSend::class);
    }

    /**
     * True once at least one assigned approver has viewed, approved, or
     * declined their checklist, or the checklist has been delegated in any
     * way — i.e. someone has actually started working on this request,
     * even though the real `status` column is still "pending" (it only
     * flips to "in_progress" once every regular checklist is approved).
     * Requires `approvers` to be loaded/loadable on this instance.
     */
    public function hasApproverActivity(): bool
    {
        return $this->approvers->contains(
            fn (OffboardingRequestApprover $approver) => $approver->status !== 'pending' || $approver->delegation_status !== null
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
                        ->whereHas('approvers', fn (Builder $q3) => static::approverActivityConstraint($q3));
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
        $delegationEventsByAssignment = $this->activities
            ->whereIn('action', ['checklist_assigned', 'checklist_delegate_completed', 'checklist_item_cleared_by_other', 'checklist_item_held', 'checklist_ready_for_approval'])
            ->groupBy('offboarding_request_approver_id');

        $buildRichStep = function (OffboardingRequestApprover $assignment) use ($remindersByAssignment, $delegationEventsByAssignment): array {
            $assignment->loadMissing('checklistTemplate.items', 'itemProgress');
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

                    return [
                        'title' => $item->title,
                        'status' => $status,
                        'timestamp' => $timestamp?->format('F d, Y – g:i A'),
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
                'approvedAt' => $assignment->approved_at?->format('M d, Y g:i A'),
                'declinedAt' => $assignment->declined_at?->format('M d, Y g:i A'),
                'declineReason' => $assignment->decline_reason,
                'reminderSentAt' => $assignment->reminder_sent_at?->format('M d, Y g:i A'),
                'canRemind' => ! in_array($assignment->status, ['approved', 'declined'], true),
                'remindUrl' => route('approvals.remind', $assignment->id),
                'done' => in_array($assignment->status, ['approved', 'declined']),
                'cancelled' => $assignment->status === 'declined',
                'delegatedTo' => $assignment->delegatedEmployee?->name,
                'delegatedToCode' => $assignment->delegatedEmployee?->employee_code,
                'delegationStatus' => $assignment->delegation_status,
                'delegateCompletedAt' => $assignment->delegate_completed_at?->format('M d, Y g:i A'),
                'dueAt' => $assignment->due_at?->format('M d, Y g:i A'),
                'isOverdue' => $assignment->isOverdue(),
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

        return $steps;
    }

    /**
     * A General Signatory's rich step for `approverActivityTimeline()` —
     * the same shape `buildRichStep()` produces for a department approver
     * (`department`/`approverName`/`status`/timestamps), minus the fields
     * that only apply to checklist-item-based approval (`checklistItems`
     * stays empty, `usesPerItemApprovers` false, no due date/overdue
     * concept, no delegation, no reminder — General Signatories have none
     * of those). `status` is only ever 'pending' or 'approved' (see
     * `OffboardingRequestGeneralSignatory`), so this never needs the
     * 'declined'/'viewed' branches the department version does.
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
            'declinedAt' => null,
            'declineReason' => null,
            'reminderSentAt' => null,
            'canRemind' => false,
            'remindUrl' => null,
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
}
