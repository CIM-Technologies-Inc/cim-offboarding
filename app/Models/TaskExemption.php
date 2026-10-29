<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Task Exemption rule (System Administration -> Task Exemption): leaves
 * the selected checklist tasks (`items()`) out of any FUTURE offboarding
 * request whose offboardee matches ANY of this rule's targets (`targets()`
 * — a department, a position, or a specific employee). See
 * `App\Services\TaskExemptionResolver` for the matching itself, and
 * `OffboardingRequestExemptedTask` for the per-request snapshot that keeps
 * edits/deletes here from ever reaching an already-submitted request.
 */
class TaskExemption extends Model
{
    protected $fillable = [
        'name',
        'reason',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function targets(): HasMany
    {
        return $this->hasMany(TaskExemptionTarget::class);
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(ChecklistItem::class, 'task_exemption_items')->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
