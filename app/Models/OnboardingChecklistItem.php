<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingChecklistItem extends Model
{
    protected $fillable = [
        'onboarding_checklist_template_id',
        'title',
        'signatory_id',
        'sort_order',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(OnboardingChecklistTemplate::class, 'onboarding_checklist_template_id');
    }

    public function signatory(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'signatory_id');
    }
}
