<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistItem extends Model
{
    protected $fillable = [
        'checklist_template_id',
        'title',
        'signatory_id',
        'sort_order',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }

    public function signatory(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'signatory_id');
    }
}
