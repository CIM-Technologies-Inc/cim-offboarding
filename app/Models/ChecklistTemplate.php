<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChecklistTemplate extends Model
{
    protected $fillable = [
        'title',
        'department_head_id',
        'is_immediate_head_checklist',
        'department',
        'is_final_pay_checklist',
        'is_active',
        'created_by',
        'due_in_days',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_final_pay_checklist' => 'boolean',
            'is_immediate_head_checklist' => 'boolean',
            'due_in_days' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('sort_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function departmentHead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'department_head_id');
    }

    public function offboardingRequests(): BelongsToMany
    {
        return $this->belongsToMany(OffboardingRequest::class, 'checklist_assignments')->withTimestamps();
    }

    /**
     * A checklist template with no `department` set applies to every
     * employee, unchanged from before this filter existed. One WITH a
     * `department` set is exclusive to employees whose own `department`
     * exactly matches it — an HR-only checklist must never reach an IT
     * employee, and vice versa.
     */
    public function scopeApplicableToDepartment(Builder $query, ?string $department): Builder
    {
        return $query->where(function (Builder $q) use ($department) {
            $q->whereNull('department')->orWhere('department', '');

            if ($department !== null && $department !== '') {
                $q->orWhere('department', $department);
            }
        });
    }
}
