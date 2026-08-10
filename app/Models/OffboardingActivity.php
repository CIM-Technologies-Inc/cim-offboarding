<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OffboardingActivity extends Model
{
    protected $fillable = [
        'offboarding_request_id',
        'user_id',
        'action',
        'status',
        'comment',
    ];

    public function offboardingRequest(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Human-readable summary for the timeline, e.g. "Approved by John Santos (IT)".
     */
    public function label(): string
    {
        $actor = $this->user?->name ?? 'Unknown';
        $department = $this->user?->employee?->department;
        $suffix = $department ? " ({$department})" : '';

        return match ($this->action) {
            'approved' => "Approved by {$actor}{$suffix}",
            'declined' => "Declined by {$actor}{$suffix}",
            default => ucfirst($this->action) . " by {$actor}{$suffix}",
        };
    }
}
