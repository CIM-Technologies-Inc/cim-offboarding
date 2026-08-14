<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistItemAssignment extends Model
{
    protected $fillable = [
        'offboarding_request_approver_id',
        'checklist_item_id',
        'assigned_by_user_id',
        'assigned_employee_id',
        'assigned_user_id',
        'status',
        'assigned_at',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequestApprover::class, 'offboarding_request_approver_id');
    }

    public function checklistItem(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class);
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function assignedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_employee_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }
}
