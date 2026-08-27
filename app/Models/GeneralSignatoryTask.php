<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeneralSignatoryTask extends Model
{
    protected $fillable = [
        'general_signatory_id',
        'title',
        'signatory_id',
        'sort_order',
    ];

    public function generalSignatory(): BelongsTo
    {
        return $this->belongsTo(GeneralSignatory::class);
    }

    public function signatory(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'signatory_id');
    }
}
