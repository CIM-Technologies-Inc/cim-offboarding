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
        // How many days after the offboardee's Last Working Day this
        // General Signatory has to complete/approve their assigned
        // checklist — the General Signatory equivalent of
        // `ChecklistTemplate.clearance_signing_deadline_days`. See
        // `OffboardingRequestGeneralSignatory.due_at`.
        'due_in_days',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_final_pay_signatory' => 'boolean',
            'due_in_days' => 'integer',
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
