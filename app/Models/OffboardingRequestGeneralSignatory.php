<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    protected function casts(): array
    {
        return [
            'is_final_pay_signatory' => 'boolean',
            'first_viewed_at' => 'datetime',
            'approved_at' => 'datetime',
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
     * `OffboardingRequestApprover::clearanceStatusLabel()`.
     */
    public function clearanceStatusLabel(): string
    {
        return $this->status === 'approved' ? 'Cleared' : 'Pending';
    }
}
