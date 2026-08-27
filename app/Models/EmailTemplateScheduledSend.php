<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records one occurrence of a scheduled EmailTemplate being sent for a
 * given OffboardingRequest. For a 'one_time' template this table holds at
 * most one row per (template, request) pair — `SendScheduledEmailTemplates`
 * checks for ANY existing row to ensure it never fires again. For a
 * 'recurring' template it holds one row per day it actually sent (e.g. day
 * 5, 10, 15, ...) — the same command instead checks for a row dated today
 * before sending, so a same-day re-run is a no-op but a later scheduled day
 * still sends normally.
 */
class EmailTemplateScheduledSend extends Model
{
    protected $fillable = [
        'email_template_id',
        'offboarding_request_id',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    public function offboardingRequest(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequest::class);
    }
}
