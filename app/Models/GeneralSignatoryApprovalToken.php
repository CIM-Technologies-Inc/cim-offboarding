<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-click "Approve" link embedded in the General Signatory Offboarding
 * Notification email — the General Signatory equivalent of
 * `ChecklistApprovalToken`, mirroring it column-for-column and convention-
 * for-convention (hashed random token, row id doubles as the lookup key,
 * never marked "used" — clicking it after the assignment is already
 * approved is a valid, idempotent no-op, not an invalid link). Kept as its
 * own model/table so the checklist workflow's token model is never touched.
 */
class GeneralSignatoryApprovalToken extends Model
{
    protected $fillable = [
        'offboarding_request_general_signatory_id',
        'token',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequestGeneralSignatory::class, 'offboarding_request_general_signatory_id');
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
