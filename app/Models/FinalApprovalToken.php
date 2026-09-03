<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-click "Approve" link embedded in the Final Approval Request email —
 * mirrors `GeneralSignatoryApprovalToken`/`ChecklistApprovalToken` exactly
 * (hashed random token, row id doubles as the lookup key, never marked
 * "used" — re-visiting an already-approved link is a valid, idempotent
 * no-op rather than an invalid one).
 */
class FinalApprovalToken extends Model
{
    protected $fillable = [
        'offboarding_request_final_approval_id',
        'token',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function finalApproval(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequestFinalApproval::class, 'offboarding_request_final_approval_id');
    }

    public function isExpired(): bool
    {
        return now()->greaterThan($this->expires_at);
    }

    public function isValid(): bool
    {
        return ! $this->isExpired();
    }
}
