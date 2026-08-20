<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PasswordResetRequest extends Model
{
    protected $fillable = [
        'user_id',
        'token',
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
     * A link is only usable while unexpired AND not already used —
     * checked both when the reset-password page itself loads and again
     * when the new password is actually submitted, since the 5-minute
     * window can lapse in between.
     */
    public function isValid(): bool
    {
        return ! $this->isExpired() && ! $this->isUsed();
    }
}
