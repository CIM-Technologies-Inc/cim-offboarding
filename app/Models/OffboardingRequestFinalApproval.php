<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A completed offboarding request's Final Approval process — at most one
 * row per request (`offboarding_request_id` is unique), created the first
 * time an admin clicks "Final Approval" on the Offboardee page and never
 * duplicated on a resend. `employee_id` snapshots whichever Final Signatory
 * the request was last sent to; see this migration's own docblock and
 * `FinalApprovalController` for why approval time re-validates that this
 * is still the currently active `FinalApprover`.
 */
class OffboardingRequestFinalApproval extends Model
{
    protected $table = 'offboarding_request_final_approvals';

    protected $fillable = [
        'offboarding_request_id',
        'employee_id',
        'status',
        'initiated_by',
        'initiated_at',
        'first_viewed_at',
        'approved_at',
        'approved_by',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'initiated_at' => 'datetime',
            'first_viewed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function offboardingRequest(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequest::class);
    }

    /**
     * The Final Signatory this approval is for — an Employee, not a
     * `FinalApprover` config row, matching the same "snapshot the
     * employee" convention used everywhere else in this app.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(FinalApprovalToken::class);
    }
}
