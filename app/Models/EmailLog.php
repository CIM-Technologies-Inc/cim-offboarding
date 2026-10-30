<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One email send attempt — see the `create_email_logs_table` migration and
 * `EmailLogRecorder`. Read-only in the UI (Logs → Email Notifications).
 */
class EmailLog extends Model
{
    public const TYPE_SYSTEM = 'system';

    public const TYPE_SCHEDULED = 'scheduled';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const TYPE_LABELS = [
        self::TYPE_SYSTEM => 'System-Triggered',
        self::TYPE_SCHEDULED => 'Scheduled',
    ];

    public const STATUS_LABELS = [
        self::STATUS_SENDING => 'Unconfirmed',
        self::STATUS_SENT => 'Sent',
        self::STATUS_FAILED => 'Failed',
    ];

    protected $fillable = [
        'message_key',
        'attempt',
        'retry_of_id',
        'notification_type',
        'status',
        'subject',
        'template_name',
        'mailable',
        'to_recipients',
        'cc_recipients',
        'bcc_recipients',
        'recipients_search',
        'redirected_to',
        'trigger',
        'trigger_source',
        'delivery',
        'schedule_name',
        'scheduled_for',
        'offboarding_request_id',
        'offboardee_name',
        'triggered_by_user_id',
        'triggered_by_name',
        'smtp_message_id',
        'error_message',
        'sent_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'to_recipients' => 'array',
            'cc_recipients' => 'array',
            'bcc_recipients' => 'array',
            'scheduled_for' => 'date',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function offboardingRequest(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequest::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }

    /** Every attempt of the same notification (including this one), oldest first. */
    public function attempts(): HasMany
    {
        return $this->hasMany(self::class, 'message_key', 'message_key')->orderBy('attempt')->orderBy('id');
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->notification_type] ?? ucfirst((string) $this->notification_type);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst((string) $this->status);
    }
}
