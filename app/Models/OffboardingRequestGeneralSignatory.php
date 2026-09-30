<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A General Signatory's approval state on one specific offboarding request —
 * the General Signatory equivalent of `OffboardingRequestApprover`, backed
 * by the same `offboarding_request_general_signatories` row that
 * `OffboardingRequest::generalSignatories()` (a plain `belongsToMany`
 * snapshot) already reads for Clearance Form display. This model exposes
 * that same table's approval-state columns (`status`, `approved_at`, etc.)
 * so a General Signatory can be tracked as a real Clearance
 * Signatory/Approver — visible on the Approvals page, actionable via
 * Submit — independently of the checklist approval workflow
 * (`OffboardingRequestApprover`), which is never touched by this model.
 */
class OffboardingRequestGeneralSignatory extends Model
{
    protected $table = 'offboarding_request_general_signatories';

    protected $fillable = [
        'offboarding_request_id',
        'general_signatory_id',
        'sequence_type',
        'is_final_pay_signatory',
        'status',
        'first_viewed_at',
        'approved_at',
        'approved_by',
        'remarks',
        'declined_at',
        'decline_reason',
        'on_hold_removed_at',
        'on_hold_removed_by',
        'on_hold_removal_reason',
        // Per-request snapshot of the Clearance Signing Due Date — computed
        // once at attachment (`ChecklistApprovalNotifier::notifyGeneralSignatories()`)
        // from the offboardee's Last Working Day + this General Signatory's
        // own `GeneralSignatory.due_in_days`, and recalculated by "Extend
        // Due" (`ApprovalController::extendAllDue()`) alongside checklists'
        // own `clearance_signing_due_at`.
        'due_at',
        // Guards `app:notify-clearance-signing-due` — set once that command
        // has emailed/notified this General Signatory for `due_at`, so a
        // later run never re-sends it for the same due-date event. Mirrors
        // `OffboardingRequestApprover.clearance_signing_due_notified_at`.
        'clearance_signing_due_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'is_final_pay_signatory' => 'boolean',
            'first_viewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'declined_at' => 'datetime',
            'on_hold_removed_at' => 'datetime',
            'due_at' => 'datetime',
            'clearance_signing_due_notified_at' => 'datetime',
        ];
    }

    public function offboardingRequest(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequest::class);
    }

    public function generalSignatory(): BelongsTo
    {
        return $this->belongsTo(GeneralSignatory::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Who removed this General Signatory's "On Hold" state — see
     * `OffboardingRequestApprover::onHoldRemovedBy()`'s matching docblock.
     */
    public function onHoldRemovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'on_hold_removed_by');
    }

    /**
     * Rows this user may see/act on: every row for an admin, otherwise only
     * rows where they're the General Signatory's own configured Clearance
     * Signatory — the General Signatory equivalent of
     * `OffboardingRequestApprover::scopeVisibleTo()`.
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

        return $query->whereHas(
            'generalSignatory',
            fn (Builder $q) => $q->where('clearance_signatory_id', $employee->id)
        );
    }

    /**
     * This General Signatory's current-state label for the Clearance Form's
     * Remarks column — the General Signatory equivalent of
     * `OffboardingRequestApprover::clearanceStatusLabel()`. A decline now
     * places this on genuine hold (`status = 'on_hold'` — see
     * `GeneralSignatoryApprovalController::decline()`), never cleared/
     * resolved until the same signatory removes it; 'declined' is kept only
     * for any legacy row from before this change.
     */
    public function clearanceStatusLabel(): string
    {
        return match ($this->status) {
            'approved' => 'Cleared',
            'declined' => 'Declined',
            'on_hold' => 'On Hold',
            default => 'Pending',
        };
    }

    /**
     * Recomputes `due_at` from a (possibly just-extended) Last Working Day
     * and this General Signatory's own `GeneralSignatory.due_in_days` — the
     * General Signatory equivalent of `OffboardingRequestApprover::recalculateClearanceSigningDueDate()`,
     * same `->endOfDay()` convention. Called both at initial attachment and
     * by `ApprovalController::extendAllDue()`. Also clears
     * `clearance_signing_due_notified_at`, same reasoning as its checklist
     * counterpart — a freshly recalculated due date must be eligible for
     * `app:notify-clearance-signing-due` again.
     */
    public function recalculateClearanceSigningDueDate(Carbon $lastWorkingDay): void
    {
        $days = $this->generalSignatory?->due_in_days;

        $this->update([
            'due_at' => $days !== null ? $lastWorkingDay->copy()->addDays($days)->endOfDay() : null,
            'clearance_signing_due_notified_at' => null,
        ]);
    }

    /**
     * True while this General Signatory's own action is still outstanding
     * past its Clearance Signing Due Date — the display-time "overdue"
     * styling `checklist-modal.blade.php`'s own "Due: ..." line already
     * uses for `OffboardingRequestApprover::isOverdue()`, mirrored here.
     */
    public function isClearanceSigningOverdue(): bool
    {
        return $this->due_at !== null
            && $this->status !== 'approved'
            && now()->greaterThan($this->due_at);
    }
}
