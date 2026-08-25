<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records that a scheduled EmailTemplate has already been sent for a given
 * OffboardingRequest, so the daily SendScheduledEmailTemplates command never
 * sends the same (template, request) pair twice.
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
