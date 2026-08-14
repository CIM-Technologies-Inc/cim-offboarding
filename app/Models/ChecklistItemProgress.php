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
        'status',
        'checked_by_user_id',
        'checked_at',
        'held_by_user_id',
        'held_at',
    ];

    protected function casts(): array
    {
        return [
            'is_checked' => 'boolean',
            'checked_at' => 'datetime',
            'held_at' => 'datetime',
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

    public function heldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by_user_id');
    }

    /**
     * Upserts checked/remark state for each submitted item against the given
     * assignment. Shared by the delegate's "Save Progress" action, the
     * Department Head's own "Save Progress"/"Submit" actions, and a bare
     * item-approver's "Done" — all persist the same shape of per-item data,
     * just from different callers. Checking an item off this way implicitly
     * resumes it out of "Hold" — there's no separate "Resume" action, since
     * actually completing the item is itself the resumption.
     *
     * Every caller here (delegate, Department Head, admin) has unrestricted
     * edit rights over every item on the assignment, so a single form
     * submission routinely includes items the current user never actually
     * touched — e.g. the Department Head clicking Submit while another
     * signatory's item is already checked. An item whose checked-state and
     * remark are unchanged from what's already stored is left alone
     * entirely, preserving its existing "Cleared By"/"Checked Date"
     * attribution instead of silently overwriting it with whoever just
     * happened to submit the form.
     *
     * @param  array<int, array{checklist_item_id: int, is_checked?: bool, remark?: ?string}>  $items
     */
    public static function syncForAssignment(OffboardingRequestApprover $assignment, array $items, int $userId): void
    {
        $existing = $assignment->itemProgress()->get()->keyBy('checklist_item_id');

        foreach ($items as $item) {
            $isChecked = (bool) ($item['is_checked'] ?? false);
            $remark = $item['remark'] ?? null;
            $current = $existing->get($item['checklist_item_id']);

            if ($current
                && (bool) $current->is_checked === $isChecked
                && (string) $current->remark === (string) $remark) {
                continue;
            }

            static::updateOrCreate(
                [
                    'offboarding_request_approver_id' => $assignment->id,
                    'checklist_item_id' => $item['checklist_item_id'],
                ],
                [
                    'is_checked' => $isChecked,
                    'remark' => $remark,
                    'checked_by_user_id' => $userId,
                    'checked_at' => now(),
                    ...($isChecked ? ['status' => null] : []),
                ]
            );
        }
    }
}
