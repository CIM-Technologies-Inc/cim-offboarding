<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records one occurrence of an `EmailTemplateSchedule` being resolved for a
 * given `OffboardingRequest` — written only once something actually
 * happened (sent/failed/cancelled); see `EmailTemplateScheduleSend`'s own
 * migration docblock for why there's no stored 'pending' status.
 */
class EmailTemplateScheduleSend extends Model
{
    protected $fillable = [
        'email_template_schedule_id',
        'offboarding_request_id',
        'status',
        'scheduled_for',
        'email_sent_at',
        'system_sent_at',
        'failure_reason',
        'recipient_log',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'date',
            'email_sent_at' => 'datetime',
            'system_sent_at' => 'datetime',
            'recipient_log' => 'array',
            'attempts' => 'integer',
        ];
    }

    public function emailTemplateSchedule(): BelongsTo
    {
        return $this->belongsTo(EmailTemplateSchedule::class);
    }

    public function offboardingRequest(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequest::class);
    }
}
