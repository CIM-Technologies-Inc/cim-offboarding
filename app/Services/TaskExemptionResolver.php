<?php

namespace App\Services;

use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestExemptedTask;
use App\Models\TaskExemption;
use App\Models\TaskExemptionTarget;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The ONE place Task Exemption rules are evaluated. Both the New Request
 * "Review" preview (`OffboardingRequestPreviewService`) and the real
 * submission (`OffboardingRequestController::store()`, via `snapshotFor()`)
 * call `exemptionsFor()`, so the preview always shows exactly what gets
 * saved. After submission nothing re-evaluates the rules again: every
 * downstream reader (approvals, delegation, Clearance Form, emails,
 * scheduled commands) only ever reads the request's own frozen snapshot
 * (`OffboardingRequest::exemptedItemIds()`).
 *
 * Matching: an ACTIVE rule applies when the offboardee matches ANY of its
 * targets — their employee record, OR their department, OR their position
 * (`position`, falling back to the mirrored `designation`). Department/
 * position compare case- and surrounding-whitespace-insensitively, since
 * both are free text on `employees`. Every matching rule is combined; a
 * task exempted by several rules is still exempted once, listing every
 * matching rule. Only tasks on ACTIVE checklists count — a rule keeps its
 * selections for an inactive checklist (so reactivating it restores them)
 * but they're ignored here.
 */
class TaskExemptionResolver
{
    /**
     * Active rules matching this employee, each with the target type(s)
     * that matched (`matched_by`).
     *
     * @return Collection<int, array{rule: TaskExemption, matched_by: array<int, string>}>
     */
    public function matchingRules(Employee $employee): Collection
    {
        $department = $this->normalize($employee->department);
        $positions = collect([$employee->position, $employee->designation])
            ->map(fn ($value) => $this->normalize($value))
            ->filter()
            ->unique();

        return TaskExemption::active()
            ->with(['targets', 'items'])
            ->orderBy('name')
            ->get()
            ->map(function (TaskExemption $rule) use ($employee, $department, $positions) {
                $matchedBy = [];

                foreach ($rule->targets as $target) {
                    $matches = match ($target->target_type) {
                        TaskExemptionTarget::TYPE_EMPLOYEE => $target->employee_id === $employee->id,
                        TaskExemptionTarget::TYPE_DEPARTMENT => $department !== '' && $this->normalize($target->target_value) === $department,
                        TaskExemptionTarget::TYPE_POSITION => $positions->contains($this->normalize($target->target_value)),
                        default => false,
                    };

                    if ($matches) {
                        $matchedBy[] = $target->target_type;
                    }
                }

                return $matchedBy ? ['rule' => $rule, 'matched_by' => array_values(array_unique($matchedBy))] : null;
            })
            ->filter()
            ->values();
    }

    /**
     * Every task exempted for this employee right now, keyed by
     * `checklist_items.id` — one entry per task no matter how many rules
     * exempt it.
     *
     * @return Collection<int, array{item: ChecklistItem, template: ChecklistTemplate, rules: array<int, array{id: int, name: string, reason: ?string, matched_by: array<int, string>}>}>
     */
    public function exemptionsFor(Employee $employee): Collection
    {
        $matches = $this->matchingRules($employee);

        if ($matches->isEmpty()) {
            return collect();
        }

        $itemIds = $matches->flatMap(fn (array $match) => $match['rule']->items->pluck('id'))->unique();

        $items = ChecklistItem::whereIn('id', $itemIds)
            ->whereHas('template', fn ($query) => $query->where('is_active', true))
            ->with('template')
            ->get()
            ->keyBy('id');

        $result = collect();

        foreach ($matches as $match) {
            foreach ($match['rule']->items as $ruleItem) {
                $item = $items->get($ruleItem->id);

                if (! $item) {
                    continue;
                }

                $entry = $result->get($item->id) ?? ['item' => $item, 'template' => $item->template, 'rules' => []];
                $entry['rules'][] = [
                    'id' => $match['rule']->id,
                    'name' => $match['rule']->name,
                    'reason' => $match['rule']->reason,
                    'matched_by' => $match['matched_by'],
                ];
                $result->put($item->id, $entry);
            }
        }

        return $result;
    }

    /**
     * Freezes this employee's current exemptions onto a just-created
     * request. Must run inside the same DB transaction that creates the
     * request (see `OffboardingRequestController::store()`), and BEFORE any
     * checklist is attached, so attachment/notifications already see it.
     */
    public function snapshotFor(OffboardingRequest $offboardingRequest, Employee $employee): void
    {
        $exemptions = $this->exemptionsFor($employee);

        foreach ($exemptions as $itemId => $entry) {
            OffboardingRequestExemptedTask::create([
                'offboarding_request_id' => $offboardingRequest->id,
                'checklist_template_id' => $entry['template']->id,
                'checklist_item_id' => $itemId,
                'checklist_title' => $entry['template']->title,
                'item_title' => $entry['item']->title,
                'matched_rules' => $entry['rules'],
            ]);
        }

        $offboardingRequest->forceFill([
            'task_exemption_context' => [
                'evaluated_at' => now()->toIso8601String(),
                'employee_id' => $employee->id,
                'department' => $employee->department,
                'position' => $employee->position ?: $employee->designation,
                'rule_ids' => $exemptions->flatMap(fn (array $entry) => array_column($entry['rules'], 'id'))->unique()->values()->all(),
            ],
        ])->save();

        $offboardingRequest->forgetExemptedItemIds();
    }

    /**
     * Drops every template whose tasks are ALL exempted on this request —
     * such a checklist is never attached at all (no approver row, no
     * signatory, no emails, no approval requirement), rather than attached
     * as an empty checklist someone would still have to approve. A template
     * that genuinely has no tasks configured is unaffected (kept exactly as
     * before this feature).
     *
     * @param  Collection<int, ChecklistTemplate>  $templates
     * @return Collection<int, ChecklistTemplate>
     */
    public function withoutFullyExemptedTemplates(OffboardingRequest $offboardingRequest, Collection $templates): Collection
    {
        $exemptedItemIds = $offboardingRequest->exemptedItemIds();

        if ($exemptedItemIds->isEmpty()) {
            return $templates;
        }

        return $templates
            ->reject(fn (ChecklistTemplate $template) => self::isFullyExempted($template, $exemptedItemIds))
            ->values();
    }

    /**
     * @param  Collection<int, int>  $exemptedItemIds
     */
    public static function isFullyExempted(ChecklistTemplate $template, Collection $exemptedItemIds): bool
    {
        $template->loadMissing('items');

        return $template->items->isNotEmpty()
            && $template->items->every(fn (ChecklistItem $item) => $exemptedItemIds->contains($item->id));
    }

    private function normalize(?string $value): string
    {
        return Str::lower(trim((string) $value));
    }
}
