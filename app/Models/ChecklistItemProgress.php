<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistItemProgress extends Model
{
    protected $fillable = [
        'offboarding_request_approver_id',
        'checklist_item_id',
        'is_checked',
        'remark',
        'checked_by_user_id',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_checked' => 'boolean',
            'checked_at' => 'datetime',
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

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by_user_id');
    }

    /**
     * Upserts checked/remark state for each submitted item against the given
     * assignment. Shared by the delegate's "Save Progress" action and the
     * primary approver's "Submit" action, since both persist the same shape
     * of per-item data — just from different callers.
     *
     * @param  array<int, array{checklist_item_id: int, is_checked?: bool, remark?: ?string}>  $items
     */
    public static function syncForAssignment(OffboardingRequestApprover $assignment, array $items, int $userId): void
    {
        foreach ($items as $item) {
            static::updateOrCreate(
                [
                    'offboarding_request_approver_id' => $assignment->id,
                    'checklist_item_id' => $item['checklist_item_id'],
                ],
                [
                    'is_checked' => (bool) ($item['is_checked'] ?? false),
                    'remark' => $item['remark'] ?? null,
                    'checked_by_user_id' => $userId,
                    'checked_at' => now(),
                ]
            );
        }
    }
}
