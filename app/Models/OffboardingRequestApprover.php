<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'delegated_employee_id',
        'delegation_status',
        'delegated_at',
        'delegate_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'first_viewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'declined_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'delegated_at' => 'datetime',
            'delegate_completed_at' => 'datetime',
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

    public function delegatedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'delegated_employee_id');
    }

    public function itemProgress(): HasMany
    {
        return $this->hasMany(ChecklistItemProgress::class);
    }

    public function delegations(): HasMany
    {
        return $this->hasMany(ChecklistDelegation::class)->orderBy('assigned_at');
    }

    public function isDelegated(): bool
    {
        return $this->delegated_employee_id !== null;
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
