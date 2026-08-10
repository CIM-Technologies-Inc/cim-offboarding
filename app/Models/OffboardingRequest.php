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
    ];

    protected function casts(): array
    {
        return [
            'notice_date' => 'date',
            'last_working_day' => 'date',
            'completed_at' => 'datetime',
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

        foreach ($this->activities as $activity) {
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
                'date' => $this->completed_at?->format('M d, Y'),
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
     */
    public function approverActivityTimeline(): array
    {
        $steps = [
            [
                'label' => 'Request Submitted',
                'date' => $this->created_at->format('M d, Y g:i A'),
                'done' => true,
            ],
        ];

        foreach ($this->approvers as $assignment) {
            $steps[] = [
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
                'canRemind' => $assignment->hasNoActivity(),
                'remindUrl' => route('approvals.remind', $assignment->id),
                'done' => in_array($assignment->status, ['approved', 'declined']),
                'cancelled' => $assignment->status === 'declined',
            ];
        }

        $steps[] = [
            'label' => 'Offboarding Completed',
            'date' => $this->completed_at?->format('M d, Y g:i A'),
            'done' => $this->status === 'completed',
        ];

        return $steps;
    }
}
