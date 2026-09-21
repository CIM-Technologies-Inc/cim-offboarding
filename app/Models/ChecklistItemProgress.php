<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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
        // For a "Use Task Assignee as Clearance Signatory" checklist item
        // checked by a regular employee (not a Department/Group Head
        // themselves) — an additional sign-off gate. Set ONCE, the moment
        // the item is first checked (see `syncForAssignment()` below), and
        // never recomputed afterward.
        'head_approval_required',
        'head_approver_employee_id',
        'head_approved_at',
        'head_approved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_checked' => 'boolean',
            'checked_at' => 'datetime',
            'held_at' => 'datetime',
            'head_approval_required' => 'boolean',
            'head_approved_at' => 'datetime',
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

    public function headApprover(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'head_approver_employee_id');
    }

    public function headApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_approved_by_user_id');
    }

    /**
     * True once this item counts as fully done: checked, and — if it ever
     * needed one — its Department/Group Head approval has been given.
     * `head_approval_required` is always false for anything other than a
     * "Use Task Assignee as Clearance Signatory" item, so this is
     * equivalent to plain `is_checked` everywhere else, unchanged.
     */
    public function isFullyApproved(): bool
    {
        return $this->is_checked && (! $this->head_approval_required || $this->head_approved_at !== null);
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
     * @return Collection<int, static> every row that just became genuinely
     *         checked AND newly requires head approval on THIS call — never
     *         one that already required it from a previous save. Callers
     *         use this to email the required head exactly once, right when
     *         the gate is first created, not on every subsequent save.
     */
    public static function syncForAssignment(OffboardingRequestApprover $assignment, array $items, int $userId): Collection
    {
        $existing = $assignment->itemProgress()->get()->keyBy('checklist_item_id');
        $newlyPendingHeadApproval = collect();

        foreach ($items as $item) {
            $current = $existing->get($item['checklist_item_id']);

            // There is no "uncheck" action anywhere in the app — once an item
            // is checked, its checkbox and remark field are both disabled in
            // the UI. A submission that omits this item's `is_checked` field
            // (e.g. a DIFFERENT item's "Done" button resubmitted the whole
            // form, and this one's now-disabled checkbox was excluded from
            // that browser form data entirely) must never be read as an
            // explicit uncheck and wipe out completion that was already
            // persisted — so an already-checked item is always left alone.
            if ((bool) $current?->is_checked) {
                continue;
            }

            $isChecked = (bool) ($item['is_checked'] ?? false);
            $remark = $item['remark'] ?? null;

            if ($current
                && (bool) $current->is_checked === $isChecked
                && (string) $current->remark === (string) $remark) {
                continue;
            }

            // The head-approval gate is decided ONCE, right here, the very
            // first time an item is actually checked — never recomputed on
            // a later save of the same item (which can't happen anyway,
            // since a checked item is excluded above, but the `$isChecked`
            // guard keeps this block from ever running for an unchecked
            // save either).
            $headApprovalAttributes = $isChecked
                ? static::resolveHeadApproval($assignment, (int) $item['checklist_item_id'])
                : ['head_approval_required' => false, 'head_approver_employee_id' => null];

            $row = static::updateOrCreate(
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
                    ...$headApprovalAttributes,
                ]
            );

            if ($isChecked && $row->head_approval_required) {
                $newlyPendingHeadApproval->push($row);
            }
        }

        return $newlyPendingHeadApproval;
    }

    /**
     * Requirement: a "Use Task Assignee as Clearance Signatory" item
     * checked by a regular employee needs their approval head's — Immediate
     * Head first, else Department/Group Head, see `Employee::approvalHead()`
     * — additional approval; one checked directly by someone who is already
     * an approval head themselves needs none (never require someone to
     * approve their own work). Every other checklist kind never reaches the
     * `true` branch at all — `use_task_assignee_as_signatory` is checked
     * first — so this never affects a normal single-/per-item-approver
     * checklist.
     *
     * Fails open (no approval required) when the checked-by employee has no
     * approval head configured anywhere to resolve to — an admin data gap,
     * not a normal case, but one that must never leave an item permanently
     * unapprovable.
     *
     * @return array{head_approval_required: bool, head_approver_employee_id: ?int}
     */
    private static function resolveHeadApproval(OffboardingRequestApprover $assignment, int $checklistItemId): array
    {
        $assignment->loadMissing('checklistTemplate.items', 'itemAssignments.assignedEmployee');

        if (! $assignment->checklistTemplate?->use_task_assignee_as_signatory) {
            return ['head_approval_required' => false, 'head_approver_employee_id' => null];
        }

        $checklistItem = $assignment->checklistTemplate->items->firstWhere('id', $checklistItemId);
        $signatory = $checklistItem ? $assignment->effectiveSignatoryFor($checklistItem) : null;

        if (! $signatory || $signatory->isApprovalAuthority()) {
            return ['head_approval_required' => false, 'head_approver_employee_id' => null];
        }

        $head = $signatory->approvalHead();

        if (! $head) {
            Log::warning('Checklist item checked by an employee with no resolvable approval head — skipping the approval gate.', [
                'offboarding_request_approver_id' => $assignment->id,
                'checklist_item_id' => $checklistItemId,
                'employee_id' => $signatory->id,
            ]);

            return ['head_approval_required' => false, 'head_approver_employee_id' => null];
        }

        return ['head_approval_required' => true, 'head_approver_employee_id' => $head->id];
    }
}
