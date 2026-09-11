<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeneralSignatory extends Model
{
    protected $fillable = [
        'clearance_signatory_id',
        'is_active',
        'sequence_type',
        'is_final_pay_signatory',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_final_pay_signatory' => 'boolean',
        ];
    }

    public function clearanceSignatory(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'clearance_signatory_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(GeneralSignatoryTask::class)->orderBy('sort_order');
    }
}
