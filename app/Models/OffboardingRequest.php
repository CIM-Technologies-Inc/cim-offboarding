<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

class OffboardingRequest extends Model
{
    /** @use HasFactory<\Database\Factories\OffboardingRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'reason',
        'resignation_type',
        'notice_date',
        'last_working_day',
        'notice_period',
        'approval_mode',
        'status',
        'remarks',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'notice_date' => 'date',
            'last_working_day' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function checklistTemplates(): BelongsToMany
    {
        return $this->belongsToMany(ChecklistTemplate::class, 'checklist_assignments')->withTimestamps();
    }

    /**
     * Build the offboarding lifecycle as a sequence of timeline steps,
     * from the request being submitted through to completion/cancellation.
     */
    public function timeline(): array
    {
        $today = Carbon::today();

        $steps = [
            [
                'label' => 'Request Submitted',
                'date' => $this->created_at->format('M d, Y'),
                'done' => true,
            ],
            [
                'label' => 'Resignation / Notice Date',
                'date' => $this->notice_date?->format('M d, Y'),
                'done' => (bool) $this->notice_date,
            ],
            [
                'label' => 'Offboarding In Progress',
                'date' => null,
                'done' => in_array($this->status, ['in_progress', 'completed']),
            ],
            [
                'label' => 'Last Working Day',
                'date' => $this->last_working_day->format('M d, Y'),
                'done' => $today->greaterThanOrEqualTo($this->last_working_day),
            ],
        ];

        if ($this->status === 'cancelled') {
            $steps[] = [
                'label' => 'Cancelled',
                'date' => $this->updated_at->format('M d, Y'),
                'done' => true,
                'cancelled' => true,
            ];
        } else {
            $steps[] = [
                'label' => 'Completed',
                'date' => $this->completed_at?->format('M d, Y'),
                'done' => $this->status === 'completed',
            ];
        }

        return $steps;
    }
}
