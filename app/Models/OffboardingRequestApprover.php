<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OffboardingRequestApprover extends Model
{
    protected $fillable = [
        'offboarding_request_id',
        'checklist_template_id',
        'employee_id',
        'status',
        'assigned_at',
        'first_viewed_at',
        'approved_at',
        'declined_at',
        'decline_reason',
        'reminder_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'first_viewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'declined_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }

    public function offboardingRequest(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequest::class);
    }

    public function checklistTemplate(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function department(): ?string
    {
        return $this->checklistTemplate?->department ?? $this->employee?->department;
    }

    /**
     * True when the approver hasn't viewed, approved, or declined yet —
     * drives whether the "Notify Approver" reminder button is shown.
     */
    public function hasNoActivity(): bool
    {
        return ! $this->first_viewed_at && ! $this->approved_at && ! $this->declined_at;
    }
}
