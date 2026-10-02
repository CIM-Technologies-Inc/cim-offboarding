<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * This app's audit trail for authentication/account-security events —
 * the same "a queryable DB table, not just a flat file" role
 * `OffboardingActivity` already plays for business events, applied here
 * to login/lockout/reactivation events (see `AuthController::store()`,
 * `ForgotPasswordController::sendResetLink()`,
 * `UserController::reactivate()`). Every write goes through `record()`
 * below so `ip_address`/`user_agent` are always captured consistently.
 */
class SecurityLog extends Model
{
    protected $fillable = [
        'user_id',
        'event',
        'attempted_username',
        'performed_by_user_id',
        'ip_address',
        'user_agent',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    /**
     * The account this event happened TO — null for a login attempt
     * against a username that doesn't match any real account (still
     * worth recording for monitoring, just with nothing to attach to).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Who performed an ADMIN action on this event's subject (e.g. the
     * admin who reactivated a blocked account) — distinct from `user()`,
     * which is always the account the event happened to, not who (if
     * anyone else) caused it.
     */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    /**
     * The one call-site convention every caller uses — always stamps the
     * current request's IP/user agent automatically, so no caller has to
     * remember to.
     */
    public static function record(string $event, array $attributes = []): self
    {
        return static::create(array_merge([
            'event' => $event,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ], $attributes));
    }
}
