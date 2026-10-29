<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot of one checklist task exempted on one offboarding request,
 * written once at submission time (`TaskExemptionResolver::snapshotFor()`)
 * and never recalculated — so a later edit/deactivation/deletion of the
 * matching rule(s), or a change to the offboardee's department/position,
 * never changes what this request includes. `matched_rules` lists every
 * rule that exempted this task: [{id, name, reason, matched_by: [...]}].
 */
class OffboardingRequestExemptedTask extends Model
{
    protected $fillable = [
        'offboarding_request_id',
        'checklist_template_id',
        'checklist_item_id',
        'checklist_title',
        'item_title',
        'matched_rules',
    ];

    protected function casts(): array
    {
        return [
            'matched_rules' => 'array',
        ];
    }

    public function offboardingRequest(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequest::class);
    }

    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class);
    }

    public function checklistTemplate(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class);
    }
}
