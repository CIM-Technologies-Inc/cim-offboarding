<?php

namespace App\Models;

use App\Support\UserAgentParser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;

/**
 * The app's unified audit trail (see the `create_activity_logs_table`
 * migration's own docblock) — every logged event, from authentication
 * through user/role/employee management and offboarding transactions,
 * goes through `record()` below so every row gets the same actor
 * snapshot, IP/browser/session capture, and shape. Immutable by design:
 * no role, admin included, may edit or delete a row through the UI (see
 * `LogController` — it only ever reads).
 */
class ActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'employee_number',
        'employee_name',
        'username',
        'roles',
        'action',
        'module',
        'description',
        'subject_type',
        'subject_id',
        'old_values',
        'new_values',
        'status',
        'failure_reason',
        'ip_address',
        'user_agent',
        'browser',
        'browser_version',
        'platform',
        'session_id',
        'route',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The single write path every call site uses — mirrors
     * `SecurityLog::record()`'s own `array_merge([defaults], $attributes)`
     * ergonomics so every call site stays a one-liner.
     *
     * Pass `user` in `$attributes` to override the acting user (e.g. a
     * failed login logs the ATTEMPTED account, not whoever happens to be
     * authenticated — which is no one, since the login hasn't succeeded;
     * a scheduled command logs the session's owner, not the console
     * "user"). Pass `subject_type`/`subject_id` when the action was
     * performed ON a different record than the actor themselves (e.g. an
     * admin reactivating someone else's account).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function record(string $action, string $module, string $description, array $attributes = []): self
    {
        $user = Arr::get($attributes, 'user', auth()->user());
        $employee = $user?->employee;
        $userAgent = request()?->userAgent();

        return static::create(array_merge([
            'user_id' => $user?->id,
            'employee_number' => $employee?->employee_code,
            'employee_name' => $employee?->name,
            'username' => $user?->username,
            'roles' => $user ? $user->roles->pluck('name')->implode(', ') : null,
            'action' => $action,
            'module' => $module,
            'description' => $description,
            'status' => 'success',
            'ip_address' => request()?->ip(),
            'user_agent' => $userAgent,
            'browser' => UserAgentParser::browser($userAgent),
            'browser_version' => UserAgentParser::browserVersion($userAgent),
            'platform' => UserAgentParser::platform($userAgent),
            'session_id' => request()?->hasSession() ? request()->session()->getId() : null,
            'route' => request()?->route()?->getName() ?? request()?->path(),
        ], Arr::except($attributes, ['user'])));
    }
}
