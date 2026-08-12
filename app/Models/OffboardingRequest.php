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
        'reason',
        'resignation_type',
        'notice_date',
        'last_working_day',
        'notice_period',
        'approval_mode',
        'email_template_id',
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

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    public function checklistTemplates(): BelongsToMany
    {
        return $this->belongsToMany(ChecklistTemplate::class, 'checklist_assignments')->withTimestamps();
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
     * The status to actually show the user: identical to the real `status`
     * column except a still-"pending" request that already has approver
     * activity displays as "in_progress". This is display-only — the real
     * `status` column must stay untouched, since other approvers' own
     * visibility into the request (`scopeVisibleTo`, the Approvals page
     * query) depends on it staying "pending" until every regular checklist
     * is actually approved.
     */
    public function displayStatus(): string
    {
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
            ->whereDoesntHave('approvers', fn (Builder $q) => static::approverActivityConstraint($q));
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
        });
    }

    private static function approverActivityConstraint(Builder $query): void
    {
        $query->where('status', '!=', 'pending')->orWhereNotNull('delegation_status');
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
        $remindersByAssignment = $this->activities->where('action', 'reminder_sent')->groupBy('offboarding_request_approver_id');
        $delegationEventsByAssignment = $this->activities
            ->whereIn('action', ['checklist_assigned', 'checklist_delegate_completed'])
            ->groupBy('offboarding_request_approver_id');

        $buildRichStep = function (OffboardingRequestApprover $assignment) use ($remindersByAssignment, $delegationEventsByAssignment): array {
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
                    'done' => true,
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
}
