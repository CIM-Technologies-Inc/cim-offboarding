<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records that a checklist item's scheduled notification has already been
 * sent for a given OffboardingRequest, so
 * SendScheduledChecklistItemNotifications never sends the same
 * (item, request) pair twice.
 */
class ChecklistItemScheduledSend extends Model
{
    protected $fillable = [
        'checklist_item_id',
        'offboarding_request_id',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class);
    }

    public function offboardingRequest(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequest::class);
    }
}
