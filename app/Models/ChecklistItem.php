<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChecklistItem extends Model
{
    protected $fillable = [
        'checklist_template_id',
        'title',
        'signatory_id',
        'sort_order',
        'notify_enabled',
        'email_template_id',
        'notify_timing',
        'notify_days',
    ];

    protected function casts(): array
    {
        return [
            'notify_enabled' => 'boolean',
            'notify_days' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }

    public function signatory(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'signatory_id');
    }

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    public function scheduledSends(): HasMany
    {
        return $this->hasMany(ChecklistItemScheduledSend::class);
    }
}
