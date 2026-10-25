<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use, one-hour, email-delivered "log me in" link — the general
 * account-access analog of `PasswordResetRequest` (same hashed-token/
 * expires_at/used_at shape, copied convention-for-convention), but instead
 * of changing a password it establishes a real authenticated session for
 * `user` via `Auth::login()` (see `AutoLoginController::confirm()`). Kept
 * entirely separate from the three existing scoped one-click approval-token
 * tables (`ChecklistApprovalToken`/`GeneralSignatoryApprovalToken`/the
 * `final_approval_tokens` table) — those act on one specific record via the
 * token alone and never authenticate anyone; this one authenticates a real
 * session and is not tied to any single checklist/request.
 */
class LoginLinkToken extends Model
{
    protected $fillable = [
        'user_id',
        'token',
        'redirect_route',
        'expires_at',
        'used_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return now()->greaterThan($this->expires_at);
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    /**
     * A link is only usable while unexpired AND not already used — checked
     * both when the confirmation page loads and again, under a row lock,
     * when it's actually submitted, since the one-hour window (or a
     * concurrent submission) can lapse in between.
     */
    public function isValid(): bool
    {
        return ! $this->isExpired() && ! $this->isUsed();
    }
}
