<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistFollowUp extends Model
{
    protected $fillable = [
        'offboarding_request_id',
        'offboarding_request_approver_id',
        'employee_id',
        'recipient_user_id',
        'attempt_number',
        'status',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function offboardingRequest(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequest::class);
    }

    public function offboardingRequestApprover(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequestApprover::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
