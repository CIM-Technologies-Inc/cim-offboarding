<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-click "Approve" link embedded in the Checklist Ready for Department
 * Head Approval email. Mirrors `PasswordResetRequest`'s exact convention
 * (hashed random token, row id doubles as the lookup key) rather than
 * Laravel's built-in signed-URL middleware, to stay consistent with the only
 * other "click an emailed link while logged out" feature in this app.
 *
 * Unlike a password reset link, this one is never marked "used" — clicking
 * it after the checklist is already approved is a valid, idempotent no-op
 * (see `ApprovalController::approveViaEmail()`), not an invalid link. Only
 * `expires_at` limits its lifetime.
 */
class ChecklistApprovalToken extends Model
{
    protected $fillable = [
        'offboarding_request_approver_id',
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
        return $this->belongsTo(OffboardingRequestApprover::class, 'offboarding_request_approver_id');
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
