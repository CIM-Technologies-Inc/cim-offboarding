<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistDelegation extends Model
{
    protected $fillable = [
        'offboarding_request_approver_id',
        'assigned_by_user_id',
        'delegated_employee_id',
        'delegated_user_id',
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

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function delegatedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'delegated_employee_id');
    }

    public function delegatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_user_id');
    }
}
