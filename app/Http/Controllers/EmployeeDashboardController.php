<?php

namespace App\Http\Controllers;

use App\Models\ChecklistItem;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use App\Services\ChecklistFollowUpService;
use Illuminate\View\View;

class EmployeeDashboardController extends Controller
{
    public function __construct(private ChecklistFollowUpService $followUpService)
    {
    }

    /**
     * The offboardee's own self-service dashboard — always scoped to
     * `auth()->user()->employee`'s own offboarding request, never a route
     * parameter, so there is no id to tamper with and no way for one
     * employee to ever reach another's data through this page.
     */
    public function index(): View
    {
        $employee = auth()->user()->employee;

        abort_if(! $employee, 404, 'No employee record is linked to this account.');

        $offboardingRequest = OffboardingRequest::where('employee_id', $employee->id)
            ->with([
                'approvers.checklistTemplate.items.signatory',
                'approvers.itemProgress.checkedBy',
                'approvers.itemAssignments.assignedEmployee',
                'approvers.employee',
                'generalSignatoryApprovals',
                'activities',
            ])
            ->latest()
            ->first();

        if (! $offboardingRequest) {
            return view('pages.employee.dashboard', [
                'title' => 'My Dashboard',
                'offboardingRequest' => null,
            ]);
        }

        $approvers = $offboardingRequest->approvers;
        $totalChecklists = $approvers->count();
        $approvedChecklists = $approvers->where('status', 'approved')->count();

        // The Follow Up button's own two gates (daily-per-checklist
        // cooldown, and the request's shared attempt budget) are enforced
        // authoritatively — again, under a lock — by
        // `ChecklistFollowUpService::send()` when the button is actually
        // clicked; what's built here is purely for what the button should
        // look like on this page load.
        $hasRecipient = (bool) $offboardingRequest->creator;
        $requestIsActive = in_array($offboardingRequest->status, ['pending', 'in_progress'], true);

        $checklists = $approvers->map(function (OffboardingRequestApprover $approver) use ($employee, $hasRecipient, $requestIsActive) {
            $items = $approver->checklistTemplate?->items ?? collect();
            $progressByItemId = $approver->itemProgress->keyBy('checklist_item_id');
            $completedItems = $items->filter(fn (ChecklistItem $item) => (bool) ($progressByItemId->get($item->id)?->is_checked ?? false));
            $statusLabel = $approver->clearanceStatusLabel();

            $isFollowUpEligible = $requestIsActive
                && $hasRecipient
                && ! in_array($statusLabel, ['Cleared', 'Declined'], true);

            $followUpState = $isFollowUpEligible ? $this->followUpService->stateFor($approver, $employee) : null;

            return [
                'assignmentId' => $approver->id,
                'title' => $approver->checklistTemplate?->title ?? 'Untitled Checklist',
                'department' => $approver->department(),
                'approverName' => $approver->employee?->name,
                'approverCode' => $approver->employee?->employee_code,
                'items' => $items->map(function (ChecklistItem $item) use ($approver, $progressByItemId) {
                    $progress = $progressByItemId->get($item->id);
                    $signatory = $approver->effectiveSignatoryFor($item);

                    return [
                        'title' => $item->title,
                        'signatoryName' => $signatory?->name,
                        'checked' => (bool) ($progress?->is_checked ?? false),
                        'onHold' => $progress?->status === 'hold' && ! ($progress?->is_checked ?? false),
                    ];
                })->values()->all(),
                'completedItemsCount' => $completedItems->count(),
                'totalItemsCount' => $items->count(),
                'dueAt' => $approver->due_at?->format('M d, Y'),
                'isOverdue' => $approver->isOverdue(),
                'status' => $statusLabel,
                'completedAt' => $approver->approved_at?->format('M d, Y'),
                'followUpEligible' => $isFollowUpEligible,
                'followUpUrl' => $isFollowUpEligible ? route('employee.follow-up', $approver->id) : null,
                'followUpCanSendNow' => $followUpState['canSendNow'] ?? false,
                'followUpLastSentAt' => ($followUpState['lastSentAt'] ?? null)?->format('M d, Y g:i A'),
                'followUpNextAllowedAt' => ($followUpState['nextAllowedAt'] ?? null)?->format('M d, Y g:i A'),
                'followUpAttemptsUsed' => $followUpState['attemptsUsed'] ?? 0,
                'followUpMaxAttempts' => $followUpState['maxAttempts'] ?? (int) config('offboarding.max_follow_up_attempts'),
                'followUpMaxReached' => $followUpState['maxReached'] ?? false,
            ];
        })->values();

        return view('pages.employee.dashboard', [
            'title' => 'My Dashboard',
            'offboardingRequest' => $offboardingRequest,
            'overview' => [
                'name' => $employee->name,
                'employeeCode' => $employee->employee_code,
                'position' => $employee->designation,
                'department' => $employee->department,
                'requestDate' => $offboardingRequest->created_at->format('M d, Y'),
                'lastWorkingDay' => $offboardingRequest->last_working_day->format('M d, Y'),
                'status' => ucfirst(str_replace('_', ' ', $offboardingRequest->displayStatus())),
                'progressPercent' => $totalChecklists > 0 ? (int) round($approvedChecklists / $totalChecklists * 100) : 0,
            ],
            'stages' => $this->buildStages($offboardingRequest),
            'checklists' => $checklists,
            'timeline' => $offboardingRequest->timeline(),
        ]);
    }

    /**
     * The 6-stage progress stepper — each stage's `done` flag is derived
     * from existing, already-tracked signals (no new state to maintain):
     * request creation is always true by the time this page exists;
     * "assigned" once at least one checklist is attached; "in progress"
     * reuses the existing `hasApproverActivity()` helper; "checklists
     * completed" reflects the same `all_checklists_approved` milestone the
     * admin dashboard/timeline already log; "clearance" reflects the
     * `clearance_generated` activity (see `ClearanceFormController`),
     * falling back to the request being fully completed if the clearance
     * form was never explicitly generated as its own step; "completed"
     * mirrors the real `status` column.
     */
    private function buildStages(OffboardingRequest $offboardingRequest): array
    {
        $hasAllChecklistsApproved = $offboardingRequest->activities->contains('action', 'all_checklists_approved')
            || in_array($offboardingRequest->status, ['in_progress', 'completed'], true);

        $hasClearanceGenerated = $offboardingRequest->activities->contains('action', 'clearance_generated')
            || $offboardingRequest->status === 'completed';

        return [
            ['label' => 'Offboarding Request Created', 'done' => true],
            ['label' => 'Checklists Assigned', 'done' => $offboardingRequest->approvers->isNotEmpty()],
            ['label' => 'Checklists In Progress', 'done' => $offboardingRequest->hasApproverActivity()],
            ['label' => 'Checklists Completed', 'done' => $hasAllChecklistsApproved],
            ['label' => 'Clearance', 'done' => $hasClearanceGenerated],
            ['label' => 'Offboarding Completed', 'done' => $offboardingRequest->status === 'completed'],
        ];
    }
}
